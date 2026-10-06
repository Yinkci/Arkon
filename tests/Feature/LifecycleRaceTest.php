<?php

namespace Tests\Feature;

use App\Arkon\Media\MediaService;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Pages\PageManagement;
use App\Arkon\Pages\PageService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;
use Tests\Support\Interleaves;

/**
 * Writers that wait behind a delete (or a rename) must act on what that writer
 * committed. Each test forces one exact interleaving: the first operation runs
 * in this process and holds the page's write lock until a worker process is
 * blocked behind it, then commits.
 */
class LifecycleRaceTest extends DatabaseTestCase
{
    use Interleaves;

    private const HOST = 'race.test';

    private array $f;

    private array $asset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
        $this->addDomain($this->f['siteId'], self::HOST);
        // The page is live with an image, so "resurrection" and media exposure are both observable.
        $this->asset = app(MediaService::class)->upload($this->f['ctx'], self::png(), 'race.png');
        app(PageService::class)->saveDraft($this->f['ctx'], [
            'pageId' => $this->f['pageId'], 'baseVersion' => 1, 'saveKey' => self::key(),
            'operations' => [['op' => 'updateProps', 'nodeId' => $this->f['heroId'], 'set' => ['image' => ['assetId' => $this->asset['id'], 'alt' => 'A dot']]]],
        ]);
        app(PageService::class)->publish($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => 2, 'idempotencyKey' => self::key()]);
    }

    /** Runs `$first` here, holding its lock until the worker's call is blocked behind it. */
    private function interleave(callable $first, string $call, array $input): array
    {
        return $this->interleaveWith($first, $call, $this->f['ctx'], $input);
    }

    private function deletePage(): array
    {
        return app(PageManagement::class)->delete($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => 2]);
    }

    private function counts(): array
    {
        return [
            'revisions' => DB::table('page_revisions')->where('page_id', $this->f['pageId'])->count(),
            'publications' => DB::table('publications')->where('page_id', $this->f['pageId'])->count(),
            'version' => (int) DB::table('page_drafts')->where('page_id', $this->f['pageId'])->value('version'),
        ];
    }

    private function assertGoneAndHistoryKept(array $before): void
    {
        $this->assertNotNull(DB::table('pages')->where('id', $this->f['pageId'])->value('deleted_at'));
        $this->assertNull(app(PageService::class)->livePage($this->f['siteId'], '/'));
        $this->assertSame(0, DB::table('live_pages')->where('page_id', $this->f['pageId'])->count());
        $access = app(MediaService::class)->resolveAccess(substr($this->asset['url'], 7), self::HOST, null, null, new MediaSigner);
        $this->assertNull($access, 'the image is private again');
        // History is preserved.
        $after = $this->counts();
        $this->assertGreaterThanOrEqual($before['revisions'], $after['revisions']);
        $this->assertGreaterThanOrEqual($before['publications'], $after['publications']);
        $this->assertSame(1, DB::table('audit_logs')->where('target_id', $this->f['pageId'])->where('action', 'page.delete')->count());
    }

    public function test_a_publish_waiting_behind_a_delete_cannot_resurrect_the_page(): void
    {
        $before = $this->counts();
        [$deleted, $publish] = $this->interleave(fn () => $this->deletePage(), 'publish', [
            'pageId' => $this->f['pageId'], 'expectedVersion' => 2, 'idempotencyKey' => self::key(),
        ]);
        $this->assertSame(['wasDeleted' => true, 'wasLive' => true], $deleted);
        $this->assertSame('NOT_FOUND', $publish['code'] ?? 'OK');
        $this->assertSame($before['publications'], $this->counts()['publications'], 'the waiting publish wrote nothing');
        $this->assertGoneAndHistoryKept($before);
    }

    public function test_a_delete_waiting_behind_a_publish_takes_the_new_publication_offline(): void
    {
        $before = $this->counts();
        $publishKey = self::key();
        [$published, $delete] = $this->interleave(
            fn () => app(PageService::class)->publish($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => 2, 'idempotencyKey' => $publishKey]),
            'delete',
            ['pageId' => $this->f['pageId'], 'expectedVersion' => 2],
        );
        $this->assertFalse($published['replayed']);
        $this->assertTrue($delete['ok']);
        $this->assertSame(['wasDeleted' => true, 'wasLive' => true], $delete['result']);
        $this->assertSame($before['publications'] + 1, $this->counts()['publications']);
        $this->assertGoneAndHistoryKept($before);
    }

    public function test_saves_restores_and_title_changes_waiting_behind_a_delete_change_nothing(): void
    {
        $revisionId = DB::table('page_revisions')->where('page_id', $this->f['pageId'])->value('id');
        $waiting = [
            ['saveDraft', ['pageId' => $this->f['pageId'], 'baseVersion' => 2, 'saveKey' => self::key(),
                'operations' => [['op' => 'updateProps', 'nodeId' => $this->f['heroId'], 'set' => ['heading' => 'Too late']]]]],
            ['restore', ['pageId' => $this->f['pageId'], 'revisionId' => $revisionId, 'expectedVersion' => 2]],
            ['updateSettings', ['pageId' => $this->f['pageId'], 'expectedVersion' => 2, 'title' => 'Too late', 'path' => '/too-late', 'saveKey' => self::key()]],
            ['unpublish', ['pageId' => $this->f['pageId'], 'expectedPublicationId' => DB::table('live_pages')->where('page_id', $this->f['pageId'])->value('publication_id')]],
            ['delete', ['pageId' => $this->f['pageId'], 'expectedVersion' => 2]],
        ];
        foreach ($waiting as $i => [$call, $input]) {
            if ($i > 0) {
                // Fresh live page with the image for each case.
                $this->setUp();
                $input['pageId'] = $this->f['pageId'];
                $input['revisionId'] = DB::table('page_revisions')->where('page_id', $this->f['pageId'])->value('id');
                $input['expectedPublicationId'] = DB::table('live_pages')->where('page_id', $this->f['pageId'])->value('publication_id');
                if ($call === 'saveDraft') {
                    $input['operations'][0]['nodeId'] = $this->f['heroId'];
                }
            }
            $before = $this->counts();
            [$deleted, $result] = $this->interleave(fn () => $this->deletePage(), $call, $input);
            $this->assertTrue($deleted['wasDeleted'], $call);
            if ($call === 'delete') {
                // A second delete that waited is a no-op, not a second deletion.
                $this->assertSame(['wasDeleted' => false, 'wasLive' => false], $result['result'], $call);
            } else {
                $this->assertSame('NOT_FOUND', $result['code'] ?? 'OK', $call);
            }
            $this->assertSame($before, $this->counts(), "{$call} wrote nothing");
            $this->assertNotSame('/too-late', DB::table('pages')->where('id', $this->f['pageId'])->value('path'));
            $this->assertGoneAndHistoryKept($before);
        }
    }

    public function test_a_save_waiting_behind_a_rename_records_the_new_title_and_url(): void
    {
        [$renamed, $save] = $this->interleave(
            fn () => app(PageManagement::class)->updateSettings($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => 2, 'title' => 'Renamed', 'path' => '/renamed', 'saveKey' => self::key()]),
            'saveDraft',
            ['pageId' => $this->f['pageId'], 'baseVersion' => 3, 'saveKey' => self::key(), 'operations' => [['op' => 'updateProps', 'nodeId' => $this->f['heroId'], 'set' => ['heading' => 'After rename']]]],
        );
        $this->assertSame(3, $renamed['version']);
        $this->assertTrue($save['ok'], json_encode($save));
        $revision = DB::table('page_revisions')->where('id', $save['result']['revision']['id'])->first();
        $this->assertSame(['Renamed', '/renamed'], [$revision->title, $revision->path]);
    }

    public function test_public_reads_ignore_a_deleted_page_even_if_a_live_row_were_left_behind(): void
    {
        // Simulates a bug elsewhere: the page is marked deleted but its live row remains.
        DB::table('pages')->where('id', $this->f['pageId'])->update(['deleted_at' => now()]);
        $this->assertSame(1, DB::table('live_pages')->where('page_id', $this->f['pageId'])->count());
        $this->assertNull(app(PageService::class)->livePage($this->f['siteId'], '/'));
        $this->assertNull(app(MediaService::class)->resolveAccess(substr($this->asset['url'], 7), self::HOST, null, null, new MediaSigner));
        // A background re-render cannot touch it either.
        DB::table('pages')->where('id', $this->f['pageId'])->update(['deleted_at' => null]);
        DB::table('sites')->where('id', $this->f['siteId'])->increment('publish_epoch');
        $prepared = app(PageService::class)->prepareRerender($this->f['siteId'], $this->f['pageId']);
        DB::table('pages')->where('id', $this->f['pageId'])->update(['deleted_at' => now()]);
        $this->assertSame(['applied' => false], app(PageService::class)->commitRerender($prepared));
    }
}
