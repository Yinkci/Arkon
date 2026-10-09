<?php

namespace Tests\Feature;

use App\Arkon\Media\MediaService;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Pages\PageManagement;
use App\Arkon\Pages\PageService;
use App\Arkon\Setup\SetupService;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\DatabaseTestCase;

/** The HTTP layer: auth, admin pages, the editor's JSON API, public pages, preview and media. */
class HttpTest extends DatabaseTestCase
{
    private const HOST = 'site.test';

    private array $f;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        File::deleteDirectory(storage_path('testing/media'));
        $this->f = $this->siteFixture();
        $this->addDomain($this->f['siteId'], self::HOST);
        $this->owner = User::findOrFail($this->f['ctx']->userId);
        $this->owner->forceFill(['email' => 'owner@test.local', 'password' => 'owner-password-123'])->save();
    }

    private function api(string $method, string $uri, array|string $body = [], ?User $as = null): TestResponse
    {
        $content = is_string($body) ? $body : json_encode($body);

        return $this->actingAs($as ?? $this->owner)->call($method, "/admin/api{$uri}", [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], $content);
    }

    private function page(): string
    {
        return "/pages/{$this->f['pageId']}";
    }

    /** A request to the public site's host (only for that request). */
    private function publicGet(string $path, array $headers = [], ?string $host = null): TestResponse
    {
        $host ??= self::HOST;

        return $this->call('GET', "http://{$host}{$path}", [], $this->prepareCookiesForRequest(), [], $this->transformHeadersToServerVars(['Host' => $host, ...$headers]));
    }

    // ── sign-in ──

    public function test_guests_are_sent_to_sign_in_and_the_api_answers_401(): void
    {
        $this->get('/admin')->assertRedirect('/login?next=%2Fadmin');
        $this->get('/admin/editor/'.$this->f['pageId'])->assertRedirect();
        $this->getJson('/admin/api'.$this->page().'/status')->assertStatus(401)->assertJson(['ok' => false, 'code' => 'UNAUTHENTICATED']);
        $this->get('/preview/'.$this->f['pageId'])->assertRedirect('/login?next=%2Fpreview%2F'.$this->f['pageId']);
    }

    public function test_sign_in_with_email_and_password_then_sign_out(): void
    {
        $this->get('/login?next=/admin/pages')->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('next', '/admin/pages'));
        $this->post('/login', ['email' => 'OWNER@test.local', 'password' => 'owner-password-123', 'next' => '/admin/pages'])->assertRedirect('/admin/pages');
        $this->assertAuthenticatedAs($this->owner);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_wrong_passwords_are_refused_and_rate_limited(): void
    {
        foreach (range(1, 5) as $_) {
            $this->post('/login', ['email' => 'owner@test.local', 'password' => 'wrong-password-123'])->assertSessionHasErrors(['email' => 'Email or password is incorrect.']);
        }
        // Even the right password is refused until the window passes (Inertia shows the message on the form).
        $this->post('/login', ['email' => 'owner@test.local', 'password' => 'owner-password-123'])
            ->assertSessionHasErrors(['email' => 'Too many attempts. Wait a minute and try again.']);
        $this->assertGuest();
    }

    public function test_an_open_redirect_through_next_is_impossible(): void
    {
        $this->post('/login', ['email' => 'owner@test.local', 'password' => 'owner-password-123', 'next' => '//evil.example/x'])->assertRedirect('/admin');
    }

    public function test_the_signed_in_surface_cannot_be_framed_by_other_sites(): void
    {
        $responses = [
            $this->get('/login'),
            $this->actingAs($this->owner)->get('/admin'),
            $this->actingAs($this->owner)->get('/admin/editor/'.$this->f['pageId']),
            $this->api('GET', $this->page().'/status'),
        ];
        foreach ($responses as $response) {
            $response->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Referrer-Policy', 'same-origin');
        }
        // The member preview keeps its own policy (framed by the editor, same origin only).
        $this->actingAs($this->owner)->get('/preview/'.$this->f['pageId'])->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_there_is_no_public_sign_up(): void
    {
        $this->post('/register', ['email' => 'x@test.local', 'password' => 'whatever-123456'])->assertStatus(405);
        $this->get('/register')->assertNotFound();
        $this->assertSame(1, User::count());
    }

    public function test_a_signed_in_user_without_a_site_is_refused(): void
    {
        $loner = User::create(['name' => 'Loner', 'email' => 'loner@test.local', 'password' => 'loner-password-123']);
        $this->actingAs($loner)->get('/admin')->assertStatus(403)->assertInertia(fn (Assert $page) => $page->component('Auth/NoSite'));
        $this->api('GET', $this->page().'/status', as: $loner)->assertStatus(403)->assertJson(['code' => 'FORBIDDEN']);
    }

    public function test_cli_owner_creation_generates_a_password_once_and_refuses_duplicates(): void
    {
        $this->artisan('arkon:owner-create', ['--email' => 'new@test.local', '--name' => 'New Owner', '--site' => $this->f['siteId']])
            ->expectsOutputToContain('Password (shown once')
            ->assertSuccessful();
        $this->assertSame('owner', DB::table('site_members')->where('user_id', User::where('email', 'new@test.local')->value('id'))->value('role'));
        $this->assertSame('cli', DB::table('audit_logs')->where('action', 'user.owner.create')->value('actor_via'));
        $this->artisan('arkon:owner-create', ['--email' => 'new@test.local', '--site' => $this->f['siteId']])->assertFailed();
        $this->assertSame(['siteId' => $this->f['siteId'], 'created' => false], app(SetupService::class)->seedDemoSite([self::HOST]));
    }

    // ── admin pages ──

    public function test_dashboard_pages_and_editor_render_for_members(): void
    {
        $this->actingAs($this->owner)->get('/admin')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Dashboard')->has('pages', 1)->where('site.name', 'Test Site')
            ->where('can', fn ($can) => $can['page.publish'] === true && $can['page.delete'] === true));
        $this->actingAs($this->owner)->get('/admin/pages')->assertInertia(fn (Assert $page) => $page->component('Admin/Pages'));
        $this->actingAs($this->owner)->get('/admin/editor/'.$this->f['pageId'])->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Editor')
            ->where('init.draft.version', 1)
            ->where('init.permissions.publish', true)
            ->where('init.canvas.body', fn ($body) => str_contains($body, 'data-ak-id="'.$this->f['heroId'].'"'))
            ->where('init.multiline.hero', ['text']));
    }

    public function test_editors_see_reduced_permissions_and_other_sites_get_404(): void
    {
        $editor = User::findOrFail($this->addMember($this->f['siteId'], 'editor')->userId);
        $this->actingAs($editor)->get('/admin/editor/'.$this->f['pageId'])->assertInertia(fn (Assert $page) => $page
            ->where('init.permissions.edit', true)->where('init.permissions.publish', false));
        $outsider = User::findOrFail($this->siteFixture()['ctx']->userId);
        $this->actingAs($outsider)->get('/admin/editor/'.$this->f['pageId'])->assertNotFound();
        $this->actingAs($outsider)->get('/admin/editor/not-a-uuid')->assertNotFound();
    }

    // ── the editor API ──

    public function test_save_publish_restore_and_status_round_trip_through_the_json_api(): void
    {
        $save = $this->api('POST', $this->page().'/save', [
            'baseVersion' => 1, 'saveKey' => self::key(),
            'operations' => [['op' => 'updateProps', 'nodeId' => $this->f['heroId'], 'set' => ['heading' => 'Via HTTP']]],
        ])->assertOk()->assertJson(['ok' => true, 'data' => ['version' => 2, 'replayed' => false]]);
        $this->assertSame(1, $save->json('data.revision.number'));

        $this->api('POST', $this->page().'/publish', ['expectedVersion' => 2, 'idempotencyKey' => self::key()])
            ->assertOk()->assertJson(['ok' => true, 'data' => ['replayed' => false, 'status' => 'published', 'version' => 2, 'live' => ['revisionNumber' => 1, 'path' => '/']]]);

        $status = $this->api('GET', $this->page().'/status')->assertOk();
        $this->assertTrue($status->json('data.revisions.0.isLive'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $status->json('data.live.publishedAt'));

        $restore = $this->api('POST', $this->page().'/restore', ['revisionId' => $status->json('data.revisions.0.id'), 'expectedVersion' => 2])->assertOk();
        $this->assertSame(3, $restore->json('data.version'));
    }

    public function test_errors_use_one_envelope_with_codes_the_editor_acts_on(): void
    {
        $ops = [['op' => 'updateProps', 'nodeId' => $this->f['heroId'], 'set' => ['heading' => 'x']]];
        $this->api('POST', $this->page().'/save', ['baseVersion' => 1, 'saveKey' => self::key(), 'operations' => $ops])->assertOk();
        $this->api('POST', $this->page().'/save', ['baseVersion' => 1, 'saveKey' => self::key(), 'operations' => $ops])
            ->assertStatus(409)->assertJson(['ok' => false, 'code' => 'STALE_VERSION', 'currentVersion' => 2]);
        $this->api('POST', $this->page().'/save', ['baseVersion' => 2, 'saveKey' => self::key(), 'operations' => [['op' => 'updateProps', 'nodeId' => $this->f['heroId'], 'set' => ['heading' => str_repeat('x', 161)]]]])
            ->assertStatus(422)->assertJson(['ok' => false, 'code' => 'VALIDATION'])->assertJsonPath('issues.0.message', 'Too long: at most 160 characters');
        $this->api('POST', $this->page().'/save', '{not json')->assertStatus(422)->assertJson(['code' => 'VALIDATION']);
        $this->api('POST', $this->page().'/save', ['baseVersion' => 2, 'saveKey' => 'short', 'operations' => $ops])->assertStatus(422);

        $editor = User::findOrFail($this->addMember($this->f['siteId'], 'editor')->userId);
        $this->api('POST', $this->page().'/publish', ['expectedVersion' => 2, 'idempotencyKey' => self::key()], $editor)
            ->assertStatus(403)->assertJson(['code' => 'FORBIDDEN']);
        $outsider = User::findOrFail($this->siteFixture()['ctx']->userId);
        $this->api('POST', $this->page().'/save', ['baseVersion' => 2, 'saveKey' => self::key(), 'operations' => $ops], $outsider)
            ->assertStatus(404)->assertJson(['code' => 'NOT_FOUND']);
        $this->assertSame(2, (int) DB::table('page_drafts')->where('page_id', $this->f['pageId'])->value('version'));
    }

    public function test_empty_json_objects_survive_the_http_layer(): void
    {
        $this->api('POST', $this->page().'/save', '{"baseVersion":1,"saveKey":"'.self::key().'","operations":[{"op":"updateSeo","set":{},"unset":["title"]}]}')->assertOk();
        $this->assertInstanceOf(\stdClass::class, json_decode(DB::table('page_drafts')->where('page_id', $this->f['pageId'])->value('document'))->seo);
        $restored = $this->api('POST', $this->page().'/restore', [
            'revisionId' => DB::table('page_revisions')->where('page_id', $this->f['pageId'])->value('id'), 'expectedVersion' => 2,
        ]);
        $this->assertStringContainsString('"seo":{}', $restored->getContent());
        $this->assertStringContainsString('"props":{}', $restored->getContent());
    }

    public function test_the_canvas_endpoint_renders_the_unsaved_document_in_editor_mode_with_signed_media(): void
    {
        $asset = app(MediaService::class)->upload($this->f['ctx'], self::png(), 'dot.png');
        $doc = json_decode(json_encode($this->f['document']), true);
        $doc['nodes'][$this->f['heroId']]['props']['heading'] = 'Not saved yet';
        $doc['nodes'][$this->f['heroId']]['props']['image'] = ['assetId' => $asset['id'], 'alt' => ''];
        $doc['nodes'][$doc['root']]['props'] = new \stdClass;
        $doc['seo'] = new \stdClass;
        $body = $this->api('POST', $this->page().'/canvas', ['document' => $doc])->assertOk()->json('data.body');
        $this->assertStringContainsString('Not saved yet', $body);
        $this->assertStringContainsString('data-ak-prop="heading"', $body);
        $this->assertMatchesRegularExpression('#src="/media/[0-9a-f-]+\.png\?t=\d+\.#', $body);
        // Invalid documents are refused with the same validation the save uses.
        $doc['nodes'][$this->f['heroId']]['props']['heading'] = str_repeat('x', 161);
        $this->api('POST', $this->page().'/canvas', ['document' => $doc])->assertStatus(422);
    }

    public function test_page_list_actions_create_unpublish_and_delete(): void
    {
        $created = $this->api('POST', '/pages', ['title' => 'About', 'path' => '/about', 'requestKey' => self::key()])->assertOk();
        $pageId = $created->json('data.pageId');
        $this->api('POST', '/pages', ['title' => 'Admin', 'path' => '/admin', 'requestKey' => self::key()])
            ->assertStatus(422)->assertJsonPath('message', 'This path is reserved by Arkon');
        $this->api('POST', '/pages', ['title' => 'Dup', 'path' => '/about', 'requestKey' => self::key()])->assertStatus(409)->assertJson(['code' => 'CONFLICT']);

        $this->api('POST', "/pages/{$pageId}/publish", ['expectedVersion' => 1, 'idempotencyKey' => self::key()])->assertOk();
        $publicationId = DB::table('live_pages')->where('page_id', $pageId)->value('publication_id');
        $this->api('POST', "/pages/{$pageId}/unpublish", ['expectedPublicationId' => $publicationId])->assertOk()->assertJson(['data' => ['wasLive' => true]]);
        $this->api('POST', "/pages/{$pageId}/delete", ['expectedVersion' => 1])->assertOk()->assertJson(['data' => ['wasDeleted' => true]]);
    }

    public function test_uploads_are_checked_by_content_and_return_a_signed_url(): void
    {
        $file = UploadedFile::fake()->createWithContent('dot.png', self::png());
        $response = $this->actingAs($this->owner)->post('/admin/api/media', ['file' => $file], ['Accept' => 'application/json'])->assertOk();
        $this->assertMatchesRegularExpression('#^/media/[0-9a-f-]+\.png\?t=\d+\.[A-Za-z0-9_-]{43}$#', $response->json('data.url'));
        $fake = UploadedFile::fake()->createWithContent('evil.png', '<?php echo 1; ?>            ');
        $this->actingAs($this->owner)->post('/admin/api/media', ['file' => $fake], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJson(['code' => 'VALIDATION']);
    }

    // ── public pages, preview and media ──

    public function test_public_pages_serve_clean_stored_html_without_session_or_admin_assets(): void
    {
        $this->publicGet('/')->assertNotFound();
        app(PageService::class)->publish($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => 1, 'idempotencyKey' => self::key()]);

        $response = $this->publicGet('/')->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('<h1 class="ak-hero3__heading">Original heading</h1>', $html);
        $this->assertDoesNotMatchRegularExpression('/<script(?! type="application\/ld\+json")/i', $response->getContent());
        foreach (['data-ak-', '/build/', 'inertia', 'data-page', 'contenteditable'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $html);
        }
        $this->assertSame([], $response->headers->getCookies());
        $this->assertStringContainsString("script-src 'none'", $response->headers->get('Content-Security-Policy'));
        $this->publicGet('/', ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);

        // Unknown hosts and reserved paths are never public pages.
        $this->publicGet('/', host: 'unknown.test')->assertNotFound();
        $this->publicGet('/admin/nonexistent')->assertNotFound();
    }

    public function test_old_urls_redirect_permanently_after_a_published_rename(): void
    {
        app(PageService::class)->publish($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => 1, 'idempotencyKey' => self::key()]);
        app(PageManagement::class)->updateSettings($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => 1, 'title' => 'Home', 'path' => '/start', 'saveKey' => self::key()]);
        $this->publicGet('/start')->assertNotFound(); // a draft rename changes nothing live
        app(PageService::class)->publish($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => 2, 'idempotencyKey' => self::key()]);
        $this->publicGet('/')->assertStatus(301)->assertHeader('Location', '/start')->assertHeader('Cache-Control', 'max-age=0, must-revalidate, public');
        $this->publicGet('/start')->assertOk();
    }

    public function test_preview_shows_the_draft_to_members_only_and_is_not_indexed(): void
    {
        app(PageService::class)->saveDraft($this->f['ctx'], ['pageId' => $this->f['pageId'], 'baseVersion' => 1, 'saveKey' => self::key(),
            'operations' => [['op' => 'updateProps', 'nodeId' => $this->f['heroId'], 'set' => ['heading' => 'Draft only']]]]);
        $response = $this->actingAs($this->owner)->get('/preview/'.$this->f['pageId'])->assertOk();
        $this->assertStringContainsString('Draft only', $response->getContent());
        $this->assertStringNotContainsString('data-ak-', $response->getContent());
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Cache-Control', 'no-store, private');
        $outsider = User::findOrFail($this->siteFixture()['ctx']->userId);
        $this->actingAs($outsider)->get('/preview/'.$this->f['pageId'])->assertNotFound();
    }

    public function test_media_is_private_until_published_with_signed_and_session_access(): void
    {
        $asset = app(MediaService::class)->upload($this->f['ctx'], self::png(), 'dot.png');
        $url = $asset['url'];

        $this->publicGet($url)->assertNotFound();
        $signed = (new MediaSigner)->signUrl($url);
        $this->publicGet($signed)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Content-Type', 'image/png');

        // A signed-in member, through the real session cookie (the media route has no session middleware).
        config(['session.driver' => 'database']);
        // The guard keeps the session store it was created with: start both afresh on the new driver.
        $this->app->forgetInstance('session.store');
        $this->app['auth']->forgetGuards();
        $login = $this->post('/login', ['email' => 'owner@test.local', 'password' => 'owner-password-123']);
        $cookie = $login->getCookie(config('session.cookie'), decrypt: false);
        $member = $this->withUnencryptedCookie(config('session.cookie'), $cookie->getValue())->publicGet($url);
        $member->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame([], $member->headers->getCookies());

        // Published: public on the site's own host, cacheable, still no cookies.
        app(PageService::class)->saveDraft($this->f['ctx'], ['pageId' => $this->f['pageId'], 'baseVersion' => 1, 'saveKey' => self::key(),
            'operations' => [['op' => 'updateProps', 'nodeId' => $this->f['heroId'], 'set' => ['image' => ['assetId' => $asset['id'], 'alt' => 'A dot']]]]]);
        app(PageService::class)->publish($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => 2, 'idempotencyKey' => self::key()]);
        $this->unencryptedCookies = [];
        $public = $this->publicGet($url);
        $public->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
        $this->assertSame([], $public->headers->getCookies());
        $this->publicGet($url, host: 'other.test')->assertNotFound();
    }
}
