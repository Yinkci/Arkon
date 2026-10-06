<?php

namespace Tests\Feature;

use App\Arkon\Errors\ConflictException;
use App\Arkon\Pages\PageManagement;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Upgrades\DataUpgrades;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;
use Tests\Support\Interleaves;

/**
 * A create request key names one create intent: these inputs, once. Retries of
 * it (also concurrent ones) return the same page; the key never creates a
 * second page or means anything else, whatever happens to the page later.
 */
class CreateIntentTest extends DatabaseTestCase
{
    use Interleaves;

    private SiteContext $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->siteFixture()['ctx'];
    }

    private function create(array $request): array
    {
        return app(PageManagement::class)->create($this->ctx, $request);
    }

    private function pagesAt(string $path): int
    {
        return DB::table('pages')->where('site_id', $this->ctx->siteId)->where('path', $path)->count();
    }

    public function test_two_identical_creates_in_flight_return_the_same_page_and_the_second_really_waited(): void
    {
        $request = ['title' => 'Contact', 'path' => '/contact', 'requestKey' => self::key()];
        [$first, $second] = $this->interleaveWith(fn () => $this->create($request), 'createPage', $this->ctx, $request);

        $this->assertFalse($first['replayed']);
        $this->assertTrue($second['ok'], json_encode($second));
        $this->assertSame(['pageId' => $first['pageId'], 'replayed' => true], $second['result']);
        $this->assertSame(1, $this->pagesAt('/contact'));
        $this->assertSame(['Created page'], DB::table('page_revisions')->where('page_id', $first['pageId'])->pluck('message')->all());
        $this->assertSame(1, DB::table('audit_logs')->where('target_id', $first['pageId'])->where('action', 'page.create')->count());
    }

    public function test_a_waiting_request_reusing_the_key_with_different_inputs_is_rejected(): void
    {
        $key = self::key();
        [$first, $second] = $this->interleaveWith(
            fn () => $this->create(['title' => 'Contact', 'path' => '/contact', 'requestKey' => $key]),
            'createPage',
            $this->ctx,
            ['title' => 'Other', 'path' => '/other', 'requestKey' => $key],
        );
        $this->assertFalse($first['replayed']);
        $this->assertSame('CONFLICT', $second['code'] ?? 'OK');
        $this->assertStringContainsString('request key', $second['message']);
        $this->assertSame(0, $this->pagesAt('/other'));
    }

    public function test_a_retry_after_the_page_was_renamed_still_means_the_original_create(): void
    {
        $request = ['title' => 'Blog', 'path' => '/blog', 'requestKey' => self::key()];
        $pageId = $this->create($request)['pageId'];
        app(PageManagement::class)->updateSettings($this->ctx, ['pageId' => $pageId, 'expectedVersion' => 1, 'title' => 'Journal', 'path' => '/journal', 'saveKey' => self::key()]);

        // The original intent, retried after an uncertain response: same page, nothing new.
        $this->assertSame(['pageId' => $pageId, 'replayed' => true], $this->create($request));
        // The key cannot be re-purposed for what the page looks like now.
        $this->assertThrows(fn () => $this->create([...$request, 'title' => 'Journal', 'path' => '/journal']), ConflictException::class, 'request key');
        $this->assertSame(1, DB::table('pages')->where('site_id', $this->ctx->siteId)->where('request_key', $request['requestKey'])->count());
    }

    public function test_pages_created_before_fingerprints_existed_are_backfilled_from_their_first_revision(): void
    {
        $request = ['title' => 'Legacy', 'path' => '/legacy', 'requestKey' => self::key()];
        $pageId = $this->create($request)['pageId'];
        app(PageManagement::class)->updateSettings($this->ctx, ['pageId' => $pageId, 'expectedVersion' => 1, 'title' => 'Renamed', 'path' => '/renamed', 'saveKey' => self::key()]);
        // As the row looked before the column existed (pages is not append-only, so the runtime role may do this).
        DB::table('pages')->where('id', $pageId)->update(['request_fingerprint' => null]);
        $this->assertThrows(fn () => $this->create($request), ConflictException::class, 'request key');

        $report = app(DataUpgrades::class)->backfillPageRequestFingerprints();
        $this->assertSame(['pages' => 1, 'backfilled' => 1, 'withoutFirstRevision' => 0], $report);
        // The original inputs (from revision #1), not the current, renamed ones.
        $this->assertSame(['pageId' => $pageId, 'replayed' => true], $this->create($request));
        $this->assertSame(['pages' => 0, 'backfilled' => 0, 'withoutFirstRevision' => 0], app(DataUpgrades::class)->backfillPageRequestFingerprints());
    }

    public function test_a_retry_after_the_page_was_deleted_is_refused_explicitly_and_creates_nothing(): void
    {
        $request = ['title' => 'Promo', 'path' => '/promo', 'requestKey' => self::key()];
        $pageId = $this->create($request)['pageId'];
        app(PageManagement::class)->delete($this->ctx, ['pageId' => $pageId, 'expectedVersion' => 1]);

        $this->assertThrows(fn () => $this->create($request), ConflictException::class, 'has since been deleted');
        $this->assertSame(0, DB::table('pages')->where('site_id', $this->ctx->siteId)->where('path', '/promo')->whereNull('deleted_at')->count());
    }
}
