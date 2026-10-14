<?php

namespace Tests\Feature;

use App\Arkon\Content\ContentItems;
use App\Arkon\Content\ContentTypeService;
use App\Arkon\Media\MediaService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * What the busy admin screens cost in queries, with enough content that an N+1 shows up as a
 * number that grows with the rows rather than staying flat. The budgets are deliberately a
 * little above what the screens use now, so ordinary work does not trip them, but adding a
 * query inside a loop does.
 */
final class QueryBudgetTest extends DatabaseTestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->f = $this->siteFixture();
        $this->addDomain($this->f['siteId'], 'budget.test');
        $this->actingAs(User::findOrFail($this->f['ctx']->userId));
    }

    /** @return array{0: int, 1: list<string>} */
    private function queries(callable $work): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $work();
        DB::flushQueryLog();

        return [count($queries), $queries];
    }

    public function test_the_pages_list_does_not_query_per_page(): void
    {
        foreach (range(1, 3) as $i) {
            $this->addPage($this->f['siteId'], "/small-{$i}", "Page {$i}");
        }
        [$few] = $this->queries(fn () => $this->get('/admin/pages')->assertOk());

        foreach (range(4, 25) as $i) {
            $this->addPage($this->f['siteId'], "/big-{$i}", "Page {$i}");
        }
        [$many, $sql] = $this->queries(fn () => $this->get('/admin/pages')->assertOk());

        $this->assertLessThanOrEqual($few, $many, "26 pages cost more than 4:\n".implode("\n", $sql));
        $this->assertLessThan(15, $many, "the pages list used {$many} queries");
    }

    public function test_the_posts_list_does_not_query_per_post(): void
    {
        $items = app(ContentItems::class);
        foreach (range(1, 3) as $i) {
            $items->create($this->f['ctx'], 'post', ['title' => "Post {$i}"]);
        }
        [$few] = $this->queries(fn () => $this->get('/admin/posts')->assertOk());

        foreach (range(4, 15) as $i) {
            $items->create($this->f['ctx'], 'post', ['title' => "Post {$i}"]);
        }
        [$many, $sql] = $this->queries(fn () => $this->get('/admin/posts')->assertOk());
        $this->assertLessThanOrEqual($few, $many, "15 posts cost more than 3:\n".implode("\n", $sql));
        $this->assertLessThan(15, $many, "the posts list used {$many} queries");
    }

    public function test_the_media_library_does_not_query_per_asset(): void
    {
        $media = app(MediaService::class);
        foreach (range(1, 3) as $i) {
            $media->upload($this->f['ctx'], self::png(), "small-{$i}.png");
        }
        [$few] = $this->queries(fn () => $this->get('/admin/media')->assertOk());

        foreach (range(4, 14) as $i) {
            $media->upload($this->f['ctx'], self::png(), "big-{$i}.png");
        }
        [$many, $sql] = $this->queries(fn () => $this->get('/admin/media')->assertOk());
        // Variants for the whole page come back in one IN (…), so more assets is not more queries.
        // A couple more are allowed for the counts that only run once there is a second page.
        $this->assertLessThanOrEqual($few + 2, $many, "the media library grew with its assets:\n".implode("\n", $sql));
        $this->assertLessThan(15, $many, "the media library used {$many} queries");
    }

    public function test_the_content_types_screen_counts_entries_without_a_query_each(): void
    {
        $service = app(ContentTypeService::class);
        foreach (['service', 'project', 'event', 'course'] as $index => $key) {
            $service->create($this->f['ctx'], [
                'key' => $key, 'name' => ucfirst($key).'s', 'singular' => ucfirst($key), 'description' => '',
                'icon' => 'component', 'pathPrefix' => '/'.$key, 'inMenu' => true, 'menuOrder' => $index,
                'details' => false, 'taxonomies' => [], 'fields' => [],
            ]);
        }
        [$count, $sql] = $this->queries(fn () => $this->get('/admin/content-types')->assertOk());
        $this->assertLessThan(15, $count, "four defined types cost {$count} queries:\n".implode("\n", $sql));
    }

    public function test_the_dashboard_stays_within_its_budget(): void
    {
        foreach (range(1, 10) as $i) {
            $this->addPage($this->f['siteId'], "/dash-{$i}", "Page {$i}");
        }
        [$count, $sql] = $this->queries(fn () => $this->get('/admin')->assertOk());
        $this->assertLessThan(30, $count, "the dashboard used {$count} queries:\n".implode("\n", $sql));
    }
}
