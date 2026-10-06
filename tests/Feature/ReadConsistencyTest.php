<?php

namespace Tests\Feature;

use App\Arkon\Errors\StaleVersionException;
use App\Arkon\Pages\PageManagement;
use App\Arkon\Pages\PageService;
use App\Arkon\Sites\SiteContext;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\DatabaseTestCase;
use Tests\Support\Parallel;

/**
 * Coupled reads (page title/URL, draft document and version, live state,
 * history) must come from one snapshot. Each test commits a change from another
 * process at the worst moment: right after the read has loaded the page row and
 * before it loads anything else.
 */
class ReadConsistencyTest extends DatabaseTestCase
{
    private SiteContext $ctx;

    private string $pageId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->ctx = $this->siteFixture()['ctx'];
        $this->pageId = app(PageManagement::class)->create($this->ctx, ['title' => 'Before', 'path' => '/before', 'requestKey' => self::key()])['pageId'];
    }

    /**
     * Runs `$read`; right after its first query on the pages table, each change in
     * `$changes` runs to completion (committed) in a separate worker process.
     *
     * @param  list<array{0: string, 1: array}>  $changes
     */
    private function withChangesAfterPageRead(callable $read, array $changes): mixed
    {
        $fired = false;
        DB::listen(function (QueryExecuted $query) use (&$fired, $changes) {
            if ($fired || ! preg_match('/\bfrom "pages"/', $query->sql)) {
                return;
            }
            $fired = true;
            foreach ($changes as [$call, $input]) {
                $result = Parallel::run($call, $this->ctx, $input);
                $this->assertTrue($result['ok'], json_encode($result));
            }
        });
        $result = $read();
        $this->assertTrue($fired, 'the concurrent change ran in the middle of the read');

        return $result;
    }

    private function rename(int $expectedVersion, string $title, string $path): array
    {
        return ['updateSettings', ['pageId' => $this->pageId, 'expectedVersion' => $expectedVersion, 'title' => $title, 'path' => $path, 'saveKey' => self::key()]];
    }

    private function currentPage(): object
    {
        return DB::table('pages as p')->join('page_drafts as d', 'd.page_id', '=', 'p.id')->where('p.id', $this->pageId)->first(['p.title', 'p.path', 'd.version']);
    }

    public function test_the_editor_never_pairs_old_metadata_with_a_new_version_and_cannot_roll_a_url_back(): void
    {
        $state = $this->withChangesAfterPageRead(
            fn () => app(PageService::class)->editorState($this->ctx, $this->pageId),
            [$this->rename(1, 'After', '/after')],
        );
        $seen = [$state['page']['title'], $state['page']['path'], $state['draft']['version']];

        // The editor submits a title-only change with the URL and version it was given.
        try {
            app(PageManagement::class)->updateSettings($this->ctx, [
                'pageId' => $this->pageId, 'expectedVersion' => $state['draft']['version'], 'title' => 'Edited title',
                'path' => $state['page']['path'], 'saveKey' => self::key(),
            ]);
        } catch (StaleVersionException) {
            // Correct when the editor saw the older state: it must reload, not overwrite.
        }
        $this->assertSame('/after', $this->currentPage()->path, 'the other editor\'s URL change is not silently reverted');
        $this->assertContains($seen, [['Before', '/before', 1], ['After', '/after', 2]], 'title, URL and version come from one moment');
    }

    public function test_the_editor_page_load_is_one_consistent_snapshot(): void
    {
        $user = User::findOrFail($this->ctx->userId);
        $response = $this->withChangesAfterPageRead(
            fn () => $this->actingAs($user)->get("/admin/editor/{$this->pageId}"),
            [$this->rename(1, 'After', '/after')],
        );
        $response->assertInertia(fn (Assert $page) => $page->where('init', function ($init) {
            $seen = [$init['page']['title'], $init['page']['path'], $init['draft']['version'], $init['revisions'][0]['number']];

            return in_array($seen, [['Before', '/before', 1, 1], ['After', '/after', 2, 2]], true);
        }));
    }

    public function test_preview_pairs_the_title_with_the_document_of_the_same_moment(): void
    {
        $heroId = collect(app(PageService::class)->editorState($this->ctx, $this->pageId)['draft']['document']['nodes'])->firstWhere('type', 'hero')['id'];
        $html = $this->withChangesAfterPageRead(
            fn () => app(PageService::class)->renderPreview($this->ctx, $this->pageId),
            [
                $this->rename(1, 'After', '/after'),
                ['saveDraft', ['pageId' => $this->pageId, 'baseVersion' => 2, 'saveKey' => self::key(),
                    'operations' => [['op' => 'updateProps', 'nodeId' => $heroId, 'set' => ['heading' => 'New heading']]]]],
            ],
        );
        $old = str_contains($html, '<title>Before · Test Site</title>') && str_contains($html, '>Before</h1>');
        $new = str_contains($html, '<title>After · Test Site</title>') && str_contains($html, '>New heading</h1>');
        $this->assertTrue($old || $new, 'preview title and content come from one moment');
    }

    public function test_ordinary_reads_take_no_row_locks(): void
    {
        $locks = [];
        DB::listen(function (QueryExecuted $query) use (&$locks) {
            if (preg_match('/\bfor (update|share|no key update|key share)\b/i', $query->sql)) {
                $locks[] = $query->sql;
            }
        });
        app(PageService::class)->editorState($this->ctx, $this->pageId);
        app(PageService::class)->listRevisions($this->ctx, $this->pageId);
        app(PageService::class)->renderPreview($this->ctx, $this->pageId);
        $this->assertSame([], $locks);
    }
}
