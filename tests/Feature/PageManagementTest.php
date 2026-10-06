<?php

namespace Tests\Feature;

use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\ForbiddenException;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Errors\StaleVersionException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Pages\PageManagement;
use App\Arkon\Pages\PageService;
use App\Arkon\Sites\SiteContext;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/** Port of packages/core/test/page-management.test.ts. */
class PageManagementTest extends DatabaseTestCase
{
    private array $f;

    private SiteContext $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
        $this->ctx = $this->f['ctx'];
    }

    private function pages(): PageService
    {
        return app(PageService::class);
    }

    private function management(): PageManagement
    {
        return app(PageManagement::class);
    }

    private function create(string $title, string $path, ?SiteContext $as = null): array
    {
        return $this->management()->create($as ?? $this->ctx, ['title' => $title, 'path' => $path, 'requestKey' => self::key()]);
    }

    private function version(string $pageId, ?SiteContext $as = null): int
    {
        return $this->pages()->editorState($as ?? $this->ctx, $pageId)['draft']['version'];
    }

    private function publish(string $pageId): array
    {
        return $this->pages()->publish($this->ctx, ['pageId' => $pageId, 'expectedVersion' => $this->version($pageId), 'idempotencyKey' => self::key()]);
    }

    private function rename(string $pageId, string $title, string $path, ?SiteContext $as = null): array
    {
        return $this->management()->updateSettings($as ?? $this->ctx, [
            'pageId' => $pageId, 'expectedVersion' => $this->version($pageId, $as), 'title' => $title, 'path' => $path, 'saveKey' => self::key(),
        ]);
    }

    private function live(string $path): ?object
    {
        return $this->pages()->livePage($this->f['siteId'], $path);
    }

    private function liveTitle(string $path): ?string
    {
        $html = $this->live($path)?->html;

        return $html && preg_match('#<title>(.*?)</title>#', $html, $m) ? $m[1] : null;
    }

    private function redirect(string $path, ?string $siteId = null): ?string
    {
        return $this->pages()->resolveRedirect($siteId ?? $this->f['siteId'], $path);
    }

    // ── creating pages ──

    public function test_creates_an_unpublished_page_with_a_first_revision_and_nothing_live(): void
    {
        $pageId = $this->create('About us', '/about')['pageId'];
        $state = $this->pages()->editorState($this->ctx, $pageId);
        $this->assertSame(['id' => $pageId, 'path' => '/about', 'title' => 'About us'], $state['page']);
        $this->assertSame('draft', $state['status']);
        $this->assertNull($this->live('/about'));
        $this->assertSame(['Created page'], array_column($this->pages()->listRevisions($this->ctx, $pageId), 'message'));
    }

    public function test_rejects_reserved_malformed_and_taken_paths(): void
    {
        $this->assertThrows(fn () => $this->create('Admin', '/admin'), ValidationException::class, 'reserved');
        $this->assertThrows(fn () => $this->create('Media', '/media/x'), ValidationException::class, 'reserved');
        $this->assertThrows(fn () => $this->create('Login', '/login'), ValidationException::class, 'reserved');
        $this->assertThrows(fn () => $this->create('Bad', '/About Us'), ValidationException::class);
        $this->assertThrows(fn () => $this->create('  ', '/blank-title'), ValidationException::class, 'A title is required');
        $this->assertThrows(fn () => $this->create('Home again', '/'), ConflictException::class); // the fixture page is at "/"
        $this->create('Pricing', '/pricing');
        $this->assertThrows(fn () => $this->create('Pricing 2', '/pricing'), ConflictException::class, 'already used');
    }

    public function test_a_retried_create_returns_the_same_page_and_a_reused_key_for_something_else_is_rejected(): void
    {
        $request = ['title' => 'Contact', 'path' => '/contact', 'requestKey' => self::key()];
        $first = $this->management()->create($this->ctx, $request);
        $retry = $this->management()->create($this->ctx, $request);
        $this->assertSame(['pageId' => $first['pageId'], 'replayed' => true], $retry);
        $this->assertThrows(fn () => $this->management()->create($this->ctx, [...$request, 'path' => '/contact-2']), ConflictException::class);
    }

    public function test_editors_can_create_viewers_cannot_and_paths_are_unique_per_site_not_globally(): void
    {
        $editor = $this->addMember($this->f['siteId'], 'editor');
        $this->assertFalse($this->create('By editor', '/by-editor', $editor)['replayed']);
        $viewer = $this->addMember($this->f['siteId'], 'viewer');
        $this->assertThrows(fn () => $this->create('By viewer', '/by-viewer', $viewer), ForbiddenException::class);
        $other = $this->siteFixture();
        $this->assertFalse($this->create('Elsewhere', '/by-editor', $other['ctx'])['replayed']);
    }

    // ── changing title and URL ──

    public function test_a_title_and_url_change_is_a_draft_change_until_published(): void
    {
        $pageId = $this->create('Services', '/services')['pageId'];
        $this->publish($pageId);
        $before = $this->live('/services');

        $renamed = $this->rename($pageId, 'Our services', '/our-services');
        $this->assertSame(2, $renamed['version']);
        $this->assertEquals($before, $this->live('/services'));
        $this->assertNull($this->live('/our-services'));
        $this->assertNull($this->redirect('/services'));
        $row = collect($this->pages()->listPages($this->ctx))->firstWhere('id', $pageId);
        $this->assertSame(['/our-services', '/services', 'changed'], [$row['path'], $row['livePath'], $row['status']]);

        $this->publish($pageId);
        $this->assertNull($this->live('/services'));
        $this->assertSame('Our services · Test Site', $this->liveTitle('/our-services'));
        $this->assertSame('/our-services', $this->redirect('/services'));
    }

    public function test_stale_title_and_url_requests_are_rejected_and_change_nothing(): void
    {
        $pageId = $this->create('Team', '/team')['pageId'];
        $this->pages()->saveDraft($this->ctx, ['pageId' => $pageId, 'baseVersion' => 1, 'saveKey' => self::key(), 'operations' => [['op' => 'updateSeo', 'set' => ['title' => 'x']]]]);
        $this->assertThrows(
            fn () => $this->management()->updateSettings($this->ctx, ['pageId' => $pageId, 'expectedVersion' => 1, 'title' => 'Crew', 'path' => '/crew', 'saveKey' => self::key()]),
            StaleVersionException::class,
        );
        $page = $this->pages()->editorState($this->ctx, $pageId)['page'];
        $this->assertSame(['Team', '/team'], [$page['title'], $page['path']]);
    }

    public function test_a_retried_title_change_is_recognised_not_applied_twice(): void
    {
        $pageId = $this->create('Blog', '/blog')['pageId'];
        $request = ['pageId' => $pageId, 'expectedVersion' => 1, 'title' => 'Journal', 'path' => '/journal', 'saveKey' => self::key()];
        $this->management()->updateSettings($this->ctx, $request);
        $retry = $this->management()->updateSettings($this->ctx, $request);
        $this->assertSame([2, true], [$retry['version'], $retry['replayed']]);
        $this->assertCount(2, $this->pages()->listRevisions($this->ctx, $pageId));
    }

    public function test_cannot_take_a_url_that_another_page_uses_as_draft_or_still_serves_live(): void
    {
        $a = $this->create('A', '/a')['pageId'];
        $this->publish($a);
        $this->rename($a, 'A', '/a-new'); // draft only: /a is still A's live URL
        $b = $this->create('B', '/b')['pageId'];
        $this->assertThrows(fn () => $this->rename($b, 'B', '/a'), ConflictException::class, 'still the live address');
        $this->assertThrows(fn () => $this->rename($b, 'B', '/a-new'), ConflictException::class, 'already used');
        $this->assertThrows(fn () => $this->create('C', '/a'), ConflictException::class);
    }

    public function test_restoring_a_revision_brings_back_content_not_the_title_or_url(): void
    {
        $pageId = $this->create('Original', '/original')['pageId'];
        $this->rename($pageId, 'Renamed', '/renamed');
        $revisions = $this->pages()->listRevisions($this->ctx, $pageId);
        $this->pages()->restoreRevision($this->ctx, ['pageId' => $pageId, 'revisionId' => end($revisions)['id'], 'expectedVersion' => $this->version($pageId)]);
        $page = $this->pages()->editorState($this->ctx, $pageId)['page'];
        $this->assertSame(['Renamed', '/renamed'], [$page['title'], $page['path']]);
    }

    public function test_viewers_cannot_change_title_or_url(): void
    {
        $pageId = $this->create('Locked', '/locked')['pageId'];
        $viewer = $this->addMember($this->f['siteId'], 'viewer');
        $this->assertThrows(fn () => $this->rename($pageId, 'x', '/x', $viewer), ForbiddenException::class);
    }

    // ── redirects never chain or loop ──

    public function test_a_chain_of_renames_resolves_every_old_url_to_the_current_one(): void
    {
        $pageId = $this->create('P', '/one')['pageId'];
        $this->publish($pageId);
        $this->rename($pageId, 'P', '/two');
        $this->publish($pageId);
        $this->rename($pageId, 'P', '/three');
        $this->publish($pageId);
        $this->assertSame('/three', $this->redirect('/one'));
        $this->assertSame('/three', $this->redirect('/two'));
        $this->assertNull($this->redirect('/three'));
    }

    public function test_renaming_back_removes_the_redirect_from_the_url_that_is_live_again(): void
    {
        $pageId = $this->create('P', '/x')['pageId'];
        $this->publish($pageId);
        $this->rename($pageId, 'P', '/y');
        $this->publish($pageId);
        $this->rename($pageId, 'P', '/x');
        $this->publish($pageId);
        $this->assertNotNull($this->live('/x'));
        $this->assertNull($this->redirect('/x'));
        $this->assertSame('/x', $this->redirect('/y'));
        $this->assertSame(['/y'], DB::table('redirects')->where('site_id', $this->f['siteId'])->pluck('from_path')->all());
    }

    public function test_another_page_can_take_a_freed_url_and_its_live_page_wins_over_the_old_redirect(): void
    {
        $a = $this->create('A', '/old')['pageId'];
        $this->publish($a);
        $this->rename($a, 'A', '/moved');
        $this->publish($a);
        $this->assertSame('/moved', $this->redirect('/old'));

        $b = $this->create('B', '/old')['pageId'];
        $this->publish($b);
        $this->assertSame('B · Test Site', $this->liveTitle('/old'));
        $this->assertNull($this->redirect('/old'));
    }

    public function test_redirects_are_site_scoped(): void
    {
        $pageId = $this->create('P', '/here')['pageId'];
        $this->publish($pageId);
        $this->rename($pageId, 'P', '/there');
        $this->publish($pageId);
        $other = $this->siteFixture();
        $this->assertNull($this->redirect('/here', $other['siteId']));
    }

    // ── unpublishing ──

    public function test_unpublish_takes_the_page_offline_keeps_history_and_redirects_stop_resolving(): void
    {
        $pageId = $this->create('Promo', '/promo')['pageId'];
        $this->publish($pageId);
        $this->rename($pageId, 'Promo', '/promo-2024');
        $this->publish($pageId);
        $seen = $this->pages()->editorState($this->ctx, $pageId)['live']['publicationId'];
        $publications = DB::table('publications')->where('page_id', $pageId)->count();

        $this->assertSame(['wasLive' => true], $this->management()->unpublish($this->ctx, ['pageId' => $pageId, 'expectedPublicationId' => $seen]));
        $this->assertNull($this->live('/promo-2024'));
        $this->assertNull($this->redirect('/promo'));
        $this->assertSame($publications, DB::table('publications')->where('page_id', $pageId)->count());
        $this->assertSame('draft', $this->pages()->editorState($this->ctx, $pageId)['status']);

        // Repeating it is harmless; publishing again brings the page and its old URLs back.
        $this->assertSame(['wasLive' => false], $this->management()->unpublish($this->ctx, ['pageId' => $pageId, 'expectedPublicationId' => $seen]));
        $this->publish($pageId);
        $this->assertSame('/promo-2024', $this->redirect('/promo'));
    }

    public function test_unpublish_is_stale_when_a_newer_version_went_live_since_the_user_looked(): void
    {
        $pageId = $this->create('News', '/news')['pageId'];
        $this->publish($pageId);
        $seen = $this->pages()->editorState($this->ctx, $pageId)['live']['publicationId'];
        $this->pages()->saveDraft($this->ctx, ['pageId' => $pageId, 'baseVersion' => $this->version($pageId), 'saveKey' => self::key(), 'operations' => [['op' => 'updateSeo', 'set' => ['title' => 'New']]]]);
        $this->publish($pageId);
        $this->assertThrows(fn () => $this->management()->unpublish($this->ctx, ['pageId' => $pageId, 'expectedPublicationId' => $seen]), ConflictException::class, 'newer version');
        $this->assertNotNull($this->live('/news'));
    }

    public function test_unpublish_requires_publish_permission_and_advances_the_epoch(): void
    {
        $pageId = $this->create('Gated', '/gated')['pageId'];
        $this->publish($pageId);
        $publicationId = $this->pages()->editorState($this->ctx, $pageId)['live']['publicationId'];
        $editor = $this->addMember($this->f['siteId'], 'editor');
        $this->assertThrows(fn () => $this->management()->unpublish($editor, ['pageId' => $pageId, 'expectedPublicationId' => $publicationId]), ForbiddenException::class);
        $before = (int) DB::table('sites')->where('id', $this->f['siteId'])->value('publish_epoch');
        $this->management()->unpublish($this->ctx, ['pageId' => $pageId, 'expectedPublicationId' => $publicationId]);
        $this->assertSame($before + 1, (int) DB::table('sites')->where('id', $this->f['siteId'])->value('publish_epoch'));
    }

    // ── deleting ──

    public function test_delete_takes_a_live_page_offline_hides_it_frees_its_url_and_keeps_history(): void
    {
        $pageId = $this->create('Old offer', '/offer')['pageId'];
        $this->publish($pageId);
        $result = $this->management()->delete($this->ctx, ['pageId' => $pageId, 'expectedVersion' => $this->version($pageId)]);
        $this->assertSame(['wasDeleted' => true, 'wasLive' => true], $result);

        $this->assertNull($this->live('/offer'));
        $this->assertNotContains($pageId, array_column($this->pages()->listPages($this->ctx), 'id'));
        $this->assertThrows(fn () => $this->pages()->editorState($this->ctx, $pageId), NotFoundException::class);
        $this->assertGreaterThan(0, DB::table('page_revisions')->where('page_id', $pageId)->count());
        $this->assertSame(1, DB::table('publications')->where('page_id', $pageId)->count());

        $this->assertFalse($this->create('New offer', '/offer')['replayed']);
        $this->assertSame(['wasDeleted' => false, 'wasLive' => false], $this->management()->delete($this->ctx, ['pageId' => $pageId, 'expectedVersion' => 1]));
    }

    public function test_delete_is_stale_if_the_draft_changed_since_the_user_confirmed(): void
    {
        $pageId = $this->create('Keep me', '/keep')['pageId'];
        $this->rename($pageId, 'Keep me please', '/keep');
        $this->assertThrows(fn () => $this->management()->delete($this->ctx, ['pageId' => $pageId, 'expectedVersion' => 1]), StaleVersionException::class);
        $this->assertContains($pageId, array_column($this->pages()->listPages($this->ctx), 'id'));
    }

    public function test_only_owners_and_admins_can_delete_and_other_sites_cannot_see_the_page(): void
    {
        $pageId = $this->create('Protected', '/protected')['pageId'];
        $editor = $this->addMember($this->f['siteId'], 'editor');
        $this->assertThrows(fn () => $this->management()->delete($editor, ['pageId' => $pageId, 'expectedVersion' => 1]), ForbiddenException::class);
        $outsider = $this->siteFixture()['ctx'];
        $this->assertThrows(fn () => $this->management()->delete($outsider, ['pageId' => $pageId, 'expectedVersion' => 1]), NotFoundException::class);
        $this->assertThrows(fn () => $this->management()->delete(new SiteContext($this->f['siteId'], $outsider->userId), ['pageId' => $pageId, 'expectedVersion' => 1]), NotFoundException::class);
        $this->assertNull(DB::table('pages')->where('id', $pageId)->value('deleted_at'));
        $admin = $this->addMember($this->f['siteId'], 'admin');
        $this->assertTrue($this->management()->delete($admin, ['pageId' => $pageId, 'expectedVersion' => 1])['wasDeleted']);
    }

    public function test_every_change_is_audited(): void
    {
        $pageId = $this->create('Audited', '/audited')['pageId'];
        $this->rename($pageId, 'Audited', '/audited-2');
        $this->publish($pageId);
        $this->management()->unpublish($this->ctx, ['pageId' => $pageId, 'expectedPublicationId' => $this->pages()->editorState($this->ctx, $pageId)['live']['publicationId']]);
        $this->management()->delete($this->ctx, ['pageId' => $pageId, 'expectedVersion' => $this->version($pageId)]);
        $actions = DB::table('audit_logs')->where('target_id', $pageId)->orderBy('created_at')->orderBy('id')->pluck('action')->all();
        $this->assertSame(['page.create', 'page.settings.update', 'page.publish', 'page.unpublish', 'page.delete'], $actions);
    }
}
