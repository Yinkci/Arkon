<?php

namespace Tests\Feature;

use App\Arkon\Content\ContentItems;
use App\Arkon\Content\TermService;
use App\Arkon\Media\MediaService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\DatabaseTestCase;

/**
 * The public API at 10,000 published posts and hundreds of images: collection reads stay fast,
 * and the number of queries does not grow with the page size (no N+1). The posts are clones of
 * one real published post (SQL), so every row has the same shape the domain writes.
 *
 * Opt-in (about 40 s of setup): php vendor/bin/phpunit --group performance
 */
#[Group('performance')]
class ApiPerformanceTest extends DatabaseTestCase
{
    private const BASE = 'http://perf.test/api/v1';

    public function test_collections_stay_fast_and_query_counts_stay_flat_at_ten_thousand_posts(): void
    {
        $f = $this->siteFixture();
        $this->addDomain($f['siteId'], 'perf.test');
        $category = app(TermService::class)->create($f['ctx'], 'category', ['name' => 'Performance']);
        $cover = app(MediaService::class)->upload($f['ctx'], self::png(), 'cover.png');
        $template = app(ContentItems::class)->create($f['ctx'], 'post', ['title' => 'Template', 'featuredMediaId' => $cover['id'], 'terms' => ['category' => [$category['id']]], 'blocks' => [['type' => 'paragraph', 'text' => 'Body']], 'status' => 'published'])['id'];
        $this->clone($f['siteId'], $template, $category['id'], 10000);
        DB::statement('INSERT INTO media_assets (id, site_id, storage_key, mime, bytes, width, height, original_name, title, created_by)
            SELECT gen_random_uuid(), ?, ?||g||\'.png\', \'image/png\', 68, 1, 1, \'bulk.png\', \'Bulk \'||g, ? FROM generate_series(1, 300) g', [$f['siteId'], 'perf-'.$f['siteId'].'-', $f['ctx']->userId]);
        DB::statement('ANALYZE pages, publications, live_pages, page_revisions, media_assets');
        $this->assertSame(10001, $this->getJson(self::BASE.'/posts?per_page=1')->assertOk()->json('meta.total'));

        $timings = [];
        foreach ([
            'newest 20' => '/posts?per_page=20',
            'page 400 of 20' => '/posts?per_page=20&page=400',
            '100 per page' => '/posts?per_page=100',
            'category filter' => '/posts?category=performance&per_page=20',
            'search' => '/posts?search=Perf%209999',
            'by title' => '/posts?sort=title&per_page=20',
            'with content' => '/posts?per_page=20&include=content,seo',
            'media library (300 private, public only)' => '/media?per_page=50',
            'categories' => '/categories',
        ] as $label => $url) {
            $this->getJson(self::BASE.$url)->assertOk();
            $start = hrtime(true);
            $this->getJson(self::BASE.$url)->assertOk();
            $timings[$label] = round((hrtime(true) - $start) / 1e6, 1);
        }
        fwrite(STDERR, "\nAPI timings at 10,001 posts (ms, warm, in-process): ".json_encode($timings)."\n");
        foreach ($timings as $label => $ms) {
            $this->assertLessThan(1500, $ms, "{$label} took {$ms} ms");
        }

        // A page of 100 costs the same number of queries as a page of 5.
        $count = function (string $url): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(self::BASE.$url)->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $this->assertSame($count('/posts?per_page=5'), $count('/posts?per_page=100'));
        // Content too: the publications of a page are read in one query (then cached, being immutable).
        $this->assertSame($count('/posts?per_page=10&page=150&include=content'), $count('/posts?per_page=50&page=30&include=content'));
    }

    /** Copies the template post's page, draft, revision, publication and live row $n times. */
    private function clone(string $siteId, string $template, string $category, int $n): void
    {
        // The same ids for every table (the runtime role may not create temporary tables).
        $ids = "(SELECT g AS n, md5('p'||g||'{$template}')::uuid AS page_id, md5('r'||g||'{$template}')::uuid AS revision_id, md5('u'||g||'{$template}')::uuid AS publication_id FROM generate_series(1, {$n}) g)";
        $copy = function (string $table, string $where, array $overrides) use ($ids) {
            $columns = DB::table('information_schema.columns')->where('table_name', $table)->where('table_schema', 'public')->orderBy('ordinal_position')->pluck('column_name')->all();
            $select = array_map(fn ($c) => $overrides[$c] ?? "t.\"{$c}\"", $columns);
            DB::statement('INSERT INTO "'.$table.'" ("'.implode('","', $columns).'") SELECT '.implode(', ', $select).' FROM "'.$table.'" t CROSS JOIN '.$ids.' i WHERE '.$where);
        };
        $q = fn (string $s) => DB::getPdo()->quote($s);
        $meta = "jsonb_build_object('kind', 'post', 'excerpt', 'Perf excerpt '||i.n, 'featuredMediaId', t.content_meta->>'featuredMediaId', 'terms', CASE WHEN i.n % 10 = 0 THEN jsonb_build_array({$q($category)}) ELSE '[]'::jsonb END)";
        $copy('pages', 't.id = '.$q($template), ['id' => 'i.page_id', 'path' => "'/blog/perf-'||i.n", 'title' => "'Perf '||i.n", 'request_key' => 'NULL', 'request_fingerprint' => 'NULL', 'first_published_at' => "now() - (i.n || ' minutes')::interval"]);
        $copy('page_revisions', 't.id = (SELECT revision_id FROM publications WHERE page_id = '.$q($template).')', ['id' => 'i.revision_id', 'page_id' => 'i.page_id', 'title' => "'Perf '||i.n", 'path' => "'/blog/perf-'||i.n"]);
        $copy('page_drafts', 't.page_id = '.$q($template), ['page_id' => 'i.page_id', 'checkpoint_revision_id' => 'i.revision_id']);
        $copy('publications', 't.page_id = '.$q($template), ['id' => 'i.publication_id', 'page_id' => 'i.page_id', 'revision_id' => 'i.revision_id', 'path' => "'/blog/perf-'||i.n", 'idempotency_key' => "'perf-'||i.n", 'content_meta' => $meta, 'created_at' => "now() - (i.n || ' minutes')::interval"]);
        $copy('live_pages', 't.page_id = '.$q($template), ['page_id' => 'i.page_id', 'publication_id' => 'i.publication_id', 'path' => "'/blog/perf-'||i.n"]);
    }
}
