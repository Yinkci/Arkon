<?php

namespace Tests\Feature;

use App\Arkon\Ai\ProposalPrompt;
use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Components\DocumentValidator;
use App\Arkon\Design\ComponentService;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\ForbiddenException;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Errors\StaleVersionException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Pages\PageService;
use App\Arkon\Renderer\PageRenderer;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Json;
use App\Arkon\Themes\ThemeService;
use App\Arkon\Themes\ThemeStore;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;
use Tests\DatabaseTestCase;
use Tests\Support\Parallel;

class ThemeActivationTest extends DatabaseTestCase
{
    private string $dir;

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('testing/theme-activation-'.uniqid());
        File::copyDirectory(base_path('themes/mysite'), $this->dir.'/sources/mysite');
        config(['arkon.theme_source' => $this->dir.'/sources', 'arkon.theme_store' => $this->dir.'/installed']);
        ThemeStore::install($this->dir.'/sources/mysite');
        foreach ([ComponentRegistry::class, DocumentValidator::class, PageRenderer::class] as $class) {
            $this->app->forgetInstance($class);
        }
        $this->f = $this->siteFixture();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function activate(?string $id = 'mysite', int $version = 1, ?string $key = null): array
    {
        return app(ThemeService::class)->activate($this->f['ctx'], ['themeId' => $id, 'baseVersion' => $version, 'requestKey' => $key ?? self::key()]);
    }

    private function saveThemeBlock(): array
    {
        $def = app(ComponentRegistry::class)->current('theme-mysite-testimonial');

        return app(PageService::class)->saveDraft($this->f['ctx'], ['pageId' => $this->f['pageId'], 'baseVersion' => 1, 'saveKey' => self::key(),
            'operations' => Json::decode(Json::encode([['op' => 'insertNode', 'parentId' => $this->f['document']['root'], 'index' => 1, 'nodes' => [
                ['id' => 'themeNode01', 'type' => $def->type, 'version' => $def->version, 'props' => $def->defaultProps],
            ]]]))]);
    }

    public function test_concurrent_theme_changes_of_the_same_version_have_one_winner(): void
    {
        $parallel = new Parallel;
        foreach (range(1, 4) as $_) {
            $parallel->add('activateTheme', $this->f['ctx'], ['themeId' => null, 'baseVersion' => 1, 'requestKey' => self::key()]);
        }
        $codes = array_map(fn ($r) => $r['ok'] ? 'OK' : $r['code'], $parallel->wait());
        sort($codes);
        $this->assertSame(['OK', 'STALE_VERSION', 'STALE_VERSION', 'STALE_VERSION'], $codes);
        $this->assertSame(1, DB::table('site_theme_requests')->count());
    }

    public function test_concurrent_duplicate_theme_publishes_record_one_epoch(): void
    {
        $this->activate();
        $parallel = new Parallel;
        $input = ['expectedVersion' => 2, 'requestKey' => self::key()];
        foreach (range(1, 4) as $_) {
            $parallel->add('publishTheme', $this->f['ctx'], $input);
        }
        $results = $parallel->wait();
        foreach ($results as $result) {
            $this->assertTrue($result['ok'], Json::encode($result));
        }
        $this->assertSame(3, count(array_filter($results, fn ($r) => $r['result']['replayed'])));
        $this->assertSame(1, DB::table('site_theme_versions')->count());
        $this->assertSame(1, (int) DB::table('sites')->value('publish_epoch'));
    }

    public function test_activation_is_draft_only_and_specific_to_one_site(): void
    {
        $other = $this->siteFixture();
        $before = DB::table('page_drafts')->where('page_id', $this->f['pageId'])->value('document');
        $this->activate();
        $state = app(ThemeService::class)->state($this->f['ctx']);
        $this->assertSame('mysite', $state['draft']['id']);
        $this->assertNull($state['published']);
        $this->assertTrue($state['changed']);
        $this->assertSame([], ThemeService::availableTypes($other['siteId']));
        $this->assertSame($before, DB::table('page_drafts')->where('page_id', $this->f['pageId'])->value('document'));
        $this->assertSame(0, DB::table('publications')->count());
    }

    public function test_exact_activation_retry_survives_later_switch_and_changed_payload_conflicts(): void
    {
        $key = self::key();
        $first = $this->activate(key: $key);
        $this->activate(null, 2);
        $retry = $this->activate(key: $key);
        $this->assertTrue($retry['replayed']);
        $this->assertSame($first['version'], $retry['version']);
        $this->assertNull(ThemeService::selection($this->f['siteId']));
        $this->assertThrows(fn () => $this->activate(null, 1, $key), ConflictException::class);
        $this->assertSame(2, DB::table('site_theme_requests')->count());
    }

    public function test_stale_activation_and_publish_do_not_overwrite_newer_selection(): void
    {
        $this->activate();
        $this->assertThrows(fn () => $this->activate(null), StaleVersionException::class);
        $this->assertThrows(fn () => app(ThemeService::class)->publish($this->f['ctx'], ['expectedVersion' => 1, 'requestKey' => self::key()]), StaleVersionException::class);
        $this->assertSame('mysite', ThemeService::selection($this->f['siteId'])['id']);
        $this->assertSame(0, DB::table('site_theme_versions')->count());
    }

    public function test_publishing_records_immutable_selection_and_retries_once(): void
    {
        $this->activate();
        $input = ['expectedVersion' => 2, 'requestKey' => self::key()];
        $service = app(ThemeService::class);
        $published = $service->publish($this->f['ctx'], $input);
        $this->assertTrue($service->publish($this->f['ctx'], $input)['replayed']);
        $this->assertSame(1, $published['version']);
        $this->assertSame(1, DB::table('site_theme_versions')->count());
        $this->assertSame(1, (int) DB::table('sites')->where('id', $this->f['siteId'])->value('publish_epoch'));
        $this->assertFalse($service->state($this->f['ctx'])['changed']);
        $this->activate(null, 2);
        $this->assertSame('mysite', $service->state($this->f['ctx'])['published']['id']);
        $this->assertTrue($service->publish($this->f['ctx'], $input)['replayed']);
        $this->assertThrows(fn () => DB::table('site_theme_versions')->update(['epoch' => 99]), QueryException::class);
        $this->assertThrows(fn () => DB::table('site_theme_requests')->delete(), QueryException::class);
    }

    public function test_only_owners_and_admins_manage_their_site_and_revoked_retries_are_denied(): void
    {
        foreach (['editor', 'viewer'] as $role) {
            $ctx = $this->addMember($this->f['siteId'], $role);
            $this->assertSame(1, app(ThemeService::class)->state($ctx)['version']);
            $this->assertThrows(fn () => app(ThemeService::class)->activate($ctx, ['themeId' => 'mysite', 'baseVersion' => 1, 'requestKey' => self::key()]), ForbiddenException::class);
            $this->assertThrows(fn () => app(ThemeService::class)->publish($ctx, ['expectedVersion' => 1, 'requestKey' => self::key()]), ForbiddenException::class);
        }
        $key = self::key();
        $this->activate(key: $key);
        DB::table('site_members')->where('site_id', $this->f['siteId'])->where('user_id', $this->f['ctx']->userId)->delete();
        $this->assertThrows(fn () => $this->activate(key: $key), NotFoundException::class);
        $this->assertThrows(fn () => app(ThemeService::class)->state(new SiteContext($this->f['siteId'], $this->createUser())), NotFoundException::class);
    }

    public function test_missing_invalid_or_changed_same_version_theme_cannot_activate(): void
    {
        foreach (['../outside', 'missing'] as $id) {
            $this->assertThrows(fn () => $this->activate($id), ValidationException::class);
        }
        file_put_contents($this->dir.'/sources/mysite/components/testimonial/styles.css', '.component { padding: 42px; }');
        $this->assertThrows(fn () => $this->activate(), ValidationException::class, 'immutable');
        $this->assertNull(ThemeService::selection($this->f['siteId']));
        $this->assertSame(0, DB::table('site_theme_requests')->count());
    }

    public function test_preview_is_read_only_and_dashboard_exposes_safe_controls(): void
    {
        $this->actingAs(User::findOrFail($this->f['ctx']->userId));
        $this->get('/admin/themes')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Themes')->where('manage', true));
        $response = $this->get('/admin/themes/mysite/preview')->assertOk();
        $this->assertStringContainsString('Alex Morgan', $response->getContent());
        $this->assertStringNotContainsString('<script', $response->getContent());
        $this->assertStringContainsString("script-src 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertSame(0, DB::table('site_theme_sets')->count());
        $this->assertCount(1, ThemeStore::installed());
        $this->get('/admin/themes/missing/preview')->assertStatus(422);
    }

    public function test_inactive_types_are_rejected_by_server_then_retained_after_switch(): void
    {
        $this->assertThrows(fn () => $this->saveThemeBlock(), ValidationException::class, 'Activate');
        $this->assertSame(1, (int) DB::table('page_drafts')->value('version'));
        $this->activate();
        $this->saveThemeBlock();
        $service = app(PageService::class);
        $publication = $service->publish($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => 2, 'idempotencyKey' => self::key()]);
        $bytes = DB::table('publications')->where('id', $publication['publicationId'])->value('html');
        $this->activate(null, 2);
        $this->assertSame(['theme-mysite-testimonial'], app(ThemeService::class)->state($this->f['ctx'])['retainedTypes']);
        $service->saveDraft($this->f['ctx'], ['pageId' => $this->f['pageId'], 'baseVersion' => 2, 'saveKey' => self::key(), 'operations' => [['op' => 'updateProps', 'nodeId' => 'themeNode01', 'set' => ['author' => 'Still editable']]]]);
        $this->assertSame($bytes, DB::table('publications')->where('id', $publication['publicationId'])->value('html'));
        $this->assertTrue($service->reproducePublication($this->f['siteId'], $publication['publicationId'])['matches']);
        $other = $this->siteFixture();
        $def = app(ComponentRegistry::class)->current('theme-mysite-testimonial');
        $this->assertThrows(fn () => app(ComponentService::class)->create($other['ctx'], ['name' => 'Inactive', 'nodes' => [['id' => 'custom001', 'type' => $def->type, 'version' => 1, 'props' => $def->defaultProps]]]), ValidationException::class, 'Activate');
    }

    public function test_page_publication_records_published_choice_and_ignores_draft_switch(): void
    {
        $this->activate();
        app(ThemeService::class)->publish($this->f['ctx'], ['expectedVersion' => 2, 'requestKey' => self::key()]);
        $this->activate(null, 2);
        $service = app(PageService::class);
        $publication = $service->publish($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => 1, 'idempotencyKey' => self::key()]);
        $row = DB::table('publications')->where('id', $publication['publicationId'])->first();
        $this->assertSame(1, Json::decode($row->render_inputs)['themeSelection']);
        $this->assertStringContainsString('Original heading', $row->html);
        app(ThemeService::class)->publish($this->f['ctx'], ['expectedVersion' => 3, 'requestKey' => self::key()]);
        $this->assertTrue($service->reproducePublication($this->f['siteId'], $row->id)['matches']);
        $this->assertSame($row->html, DB::table('publications')->where('id', $row->id)->value('html'));
    }

    public function test_ai_catalogue_and_schema_follow_the_site_and_existing_document(): void
    {
        $prompt = app(ProposalPrompt::class);
        $context = $prompt->context($this->f['ctx'], $this->f['pageId']);
        $this->assertStringNotContainsString('theme-mysite-testimonial', $prompt->instructions($context));
        $this->assertArrayNotHasKey('block_theme-mysite-testimonial', $prompt->schema($context)['$defs']);
        $this->activate();
        $context = $prompt->context($this->f['ctx'], $this->f['pageId']);
        $this->assertArrayHasKey('block_theme-mysite-testimonial', $prompt->schema($context)['$defs']);
        $this->saveThemeBlock();
        $this->activate(null, 2);
        $context = $prompt->context($this->f['ctx'], $this->f['pageId']);
        $this->assertArrayHasKey('update_theme-mysite-testimonial', $prompt->schema($context)['$defs']);
    }
}
