<?php

namespace Tests\Feature;

use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\StaleVersionException;
use App\Arkon\Pages\PageManagement;
use App\Arkon\Pages\PageService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Uncertain outcomes: responses lost after commit, requests lost before commit,
 * reused keys. Port of packages/core/test/requests.test.ts.
 */
class RequestsTest extends DatabaseTestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
    }

    private function pages(): PageService
    {
        return app(PageService::class);
    }

    private function saveRequest(string $heading, int $baseVersion = 1, ?string $key = null): array
    {
        return [
            'pageId' => $this->f['pageId'], 'baseVersion' => $baseVersion, 'saveKey' => $key ?? self::key(),
            'operations' => [['op' => 'updateProps', 'nodeId' => $this->f['heroId'], 'set' => ['heading' => $heading]]],
        ];
    }

    private function publications(): int
    {
        return DB::table('publications')->where('page_id', $this->f['pageId'])->count();
    }

    private function version(): int
    {
        return $this->pages()->editorState($this->f['ctx'], $this->f['pageId'])['draft']['version'];
    }

    // ── save retries ──

    public function test_a_save_whose_response_was_lost_after_commit_is_recognised_on_retry_not_applied_twice(): void
    {
        $request = $this->saveRequest('Saved once');
        $first = $this->pages()->saveDraft($this->f['ctx'], $request);
        // The client never saw `first` and retries the identical request.
        $retry = $this->pages()->saveDraft($this->f['ctx'], $request);
        $this->assertSame($first['version'], $retry['version']);
        $this->assertSame($first['revision'], $retry['revision']);
        $this->assertTrue($retry['replayed']);
        $this->assertCount(1, $this->pages()->listRevisions($this->f['ctx'], $this->f['pageId']));
        $this->assertSame(2, $this->version());
    }

    public function test_a_save_lost_before_commit_is_simply_applied_by_the_retry(): void
    {
        $request = $this->saveRequest('Second try');
        $this->withFailingCommit(fn () => $this->pages()->saveDraft($this->f['ctx'], $request));
        $this->assertSame(1, $this->version());
        $retry = $this->pages()->saveDraft($this->f['ctx'], $request);
        $this->assertSame(2, $retry['version']);
        $this->assertFalse($retry['replayed']);
    }

    public function test_a_reused_save_key_with_different_content_is_rejected(): void
    {
        $key = self::key();
        $this->pages()->saveDraft($this->f['ctx'], $this->saveRequest('A', key: $key));
        $this->assertThrows(fn () => $this->pages()->saveDraft($this->f['ctx'], $this->saveRequest('B', key: $key)), ConflictException::class);
    }

    public function test_a_retry_after_someone_else_saved_is_stale_not_a_replay(): void
    {
        $request = $this->saveRequest('Mine');
        $this->pages()->saveDraft($this->f['ctx'], $request);
        $this->pages()->saveDraft($this->f['ctx'], $this->saveRequest('Theirs', 2));
        $this->assertThrows(fn () => $this->pages()->saveDraft($this->f['ctx'], $request), StaleVersionException::class);
    }

    // ── publish intent ──

    public function test_publish_response_lost_after_commit_the_retry_returns_the_same_publication(): void
    {
        $request = ['pageId' => $this->f['pageId'], 'expectedVersion' => 1, 'idempotencyKey' => self::key()];
        $first = $this->pages()->publish($this->f['ctx'], $request);
        $retry = $this->pages()->publish($this->f['ctx'], $request);
        $this->assertSame($first['publicationId'], $retry['publicationId']);
        $this->assertTrue($retry['replayed']);
        $this->assertSame(1, $this->publications());
    }

    public function test_publish_lost_before_commit_the_retry_publishes_exactly_once(): void
    {
        $request = ['pageId' => $this->f['pageId'], 'expectedVersion' => 1, 'idempotencyKey' => self::key()];
        $this->withFailingCommit(fn () => $this->pages()->publish($this->f['ctx'], $request));
        $this->assertSame(0, $this->publications());
        $this->assertNull($this->pages()->livePage($this->f['siteId'], '/'));
        $retry = $this->pages()->publish($this->f['ctx'], $request);
        $this->assertFalse($retry['replayed']);
        $this->assertSame(1, $this->publications());
    }

    public function test_after_an_intervening_edit_the_old_key_cannot_publish_the_new_version(): void
    {
        $oldKey = self::key();
        $this->pages()->publish($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => 1, 'idempotencyKey' => $oldKey]);
        $this->pages()->saveDraft($this->f['ctx'], $this->saveRequest('Edited after publish'));

        // Reusing the key for the new version is a different request: rejected, not silently replayed.
        $this->assertThrows(
            fn () => $this->pages()->publish($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => 2, 'idempotencyKey' => $oldKey]),
            ConflictException::class,
        );
        $this->assertStringNotContainsString('Edited after publish', $this->pages()->livePage($this->f['siteId'], '/')->html);

        // A new intent for the new version publishes it.
        $fresh = $this->pages()->publish($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => 2, 'idempotencyKey' => self::key()]);
        $this->assertFalse($fresh['replayed']);
        $this->assertStringContainsString('Edited after publish', $this->pages()->livePage($this->f['siteId'], '/')->html);
        $this->assertSame(2, $this->publications());
    }

    public function test_an_uncommitted_publish_followed_by_an_edit_retrying_the_old_intent_is_stale(): void
    {
        $request = ['pageId' => $this->f['pageId'], 'expectedVersion' => 1, 'idempotencyKey' => self::key()];
        $this->withFailingCommit(fn () => $this->pages()->publish($this->f['ctx'], $request));
        $this->pages()->saveDraft($this->f['ctx'], $this->saveRequest('Newer'));
        $this->assertThrows(fn () => $this->pages()->publish($this->f['ctx'], $request), StaleVersionException::class);
        $this->assertSame(0, $this->publications());
    }

    public function test_a_title_and_url_change_lost_before_commit_is_applied_once_by_the_retry(): void
    {
        $request = ['pageId' => $this->f['pageId'], 'expectedVersion' => 1, 'title' => 'Renamed', 'path' => '/renamed', 'saveKey' => self::key()];
        $this->withFailingCommit(fn () => app(PageManagement::class)->updateSettings($this->f['ctx'], $request));
        $this->assertSame(1, $this->version());
        $result = app(PageManagement::class)->updateSettings($this->f['ctx'], $request);
        $this->assertSame(['version' => 2, 'title' => 'Renamed', 'path' => '/renamed', 'replayed' => false], $result);
        $this->assertTrue(app(PageManagement::class)->updateSettings($this->f['ctx'], $request)['replayed']);
    }
}
