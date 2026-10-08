<?php

namespace Tests\Feature;

use App\Arkon\Ai\ProposalSchema;
use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Components\DocumentValidator;
use App\Arkon\Media\MediaService;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Pages\PageService;
use App\Arkon\Renderer\PageRenderer;
use App\Arkon\Support\Json;
use App\Arkon\Themes\Template;
use App\Arkon\Themes\ThemeService;
use App\Arkon\Themes\ThemeStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\DatabaseTestCase;

class ThemeComponentsTest extends DatabaseTestCase
{
    private string $dir;

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('testing/theme-'.uniqid());
        File::copyDirectory(base_path('themes/mysite'), $this->dir.'/source');
        config(['arkon.theme_store' => $this->dir.'/installed']);
        ThemeStore::install($this->dir.'/source');
        $this->reloadRegistry();
        $this->f = $this->siteFixture();
        app(ThemeService::class)->activate($this->f['ctx'], ['themeId' => 'mysite', 'baseVersion' => 1, 'requestKey' => self::key()]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function reloadRegistry(): void
    {
        foreach ([ComponentRegistry::class, DocumentValidator::class, PageRenderer::class] as $class) {
            $this->app->forgetInstance($class);
        }
    }

    private function add(): array
    {
        $def = app(ComponentRegistry::class)->current('theme-mysite-testimonial');

        return app(PageService::class)->saveDraft($this->f['ctx'], [
            'pageId' => $this->f['pageId'], 'baseVersion' => 1, 'saveKey' => self::key(),
            'operations' => Json::decode(Json::encode([['op' => 'insertNode', 'parentId' => $this->f['document']['root'], 'index' => 1,
                'nodes' => [['id' => 'testim01', 'type' => $def->type, 'version' => $def->version, 'props' => $def->defaultProps]]]])),
        ]);
    }

    public function test_installed_fields_save_and_publish_through_existing_services(): void
    {
        $this->add();
        app(PageService::class)->saveDraft($this->f['ctx'], ['pageId' => $this->f['pageId'], 'baseVersion' => 2, 'saveKey' => self::key(),
            'operations' => [['op' => 'updateProps', 'nodeId' => 'testim01', 'set' => ['quote' => '<script>alert(1)</script>', 'author' => 'Jamie', 'alignment' => 'center']]]]);
        $live = app(PageService::class)->publish($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => 3, 'idempotencyKey' => self::key()]);
        $row = DB::table('publications')->where('id', $live['publicationId'])->first();
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $row->html);
        $this->assertStringContainsString('variant-center', $row->html);
        $this->assertStringContainsString('.at-theme-mysite-testimonial-v1', $row->html);
        $this->assertStringNotContainsString('data-field', $row->html);
        $this->assertStringNotContainsString('data-ak-', $row->html);
        $this->assertStringNotContainsString('<script', $row->html);
        $this->assertContains('theme-mysite-testimonial@1', json_decode($row->render_inputs, true)['components']);
        $this->assertTrue(app(PageService::class)->reproducePublication($this->f['siteId'], $row->id)['matches']);
    }

    public function test_presentation_upgrade_preserves_old_publication_bytes_and_history(): void
    {
        $this->add();
        $old = app(PageService::class)->publish($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => 2, 'idempotencyKey' => self::key()]);
        $before = DB::table('publications')->where('id', $old['publicationId'])->value('html');
        $manifestFile = $this->dir.'/source/components/testimonial/component.json';
        $m = json_decode(file_get_contents($manifestFile), true);
        $m['version'] = 2;
        file_put_contents($manifestFile, Json::encode($m));
        file_put_contents($this->dir.'/source/components/testimonial/styles.css', '.component { padding: 40px; }');
        ThemeStore::install($this->dir.'/source');
        $this->reloadRegistry();
        $state = app(PageService::class)->editorState($this->f['ctx'], $this->f['pageId']);
        $this->assertSame(2, $state['draft']['document']['nodes']['testim01']['version']);
        $result = app(PageService::class)->reproducePublication($this->f['siteId'], $old['publicationId']);
        $this->assertTrue($result['matches'], (string) $result['reason']);
        $this->assertSame($before, $result['html']);
        $this->assertCount(2, ThemeStore::installed());
    }

    public function test_images_remain_private_until_published_and_have_intrinsic_dimensions(): void
    {
        $this->addDomain($this->f['siteId'], 'themes.test');
        $asset = app(MediaService::class)->upload($this->f['ctx'], self::png(), 'author.png');
        $this->add();
        app(PageService::class)->saveDraft($this->f['ctx'], ['pageId' => $this->f['pageId'], 'baseVersion' => 2, 'saveKey' => self::key(),
            'operations' => [['op' => 'updateProps', 'nodeId' => 'testim01', 'set' => ['photo' => ['assetId' => $asset['id'], 'alt' => 'Author portrait']]]]]);
        $media = app(MediaService::class);
        $this->assertNull($media->resolveAccess(substr($asset['url'], 7), 'themes.test', null, null, new MediaSigner));
        $live = app(PageService::class)->publish($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => 3, 'idempotencyKey' => self::key()]);
        $html = DB::table('publications')->where('id', $live['publicationId'])->value('html');
        $this->assertStringContainsString('alt="Author portrait" width="1" height="1" decoding="async"', $html);
        $this->assertSame('public', $media->resolveAccess(substr($asset['url'], 7), 'themes.test', null, null, new MediaSigner)['access']);
    }

    public function test_ai_discovers_theme_fields_and_scoped_styles_allow_builder_overrides(): void
    {
        $schema = new ProposalSchema(app(ComponentRegistry::class));
        $this->assertStringContainsString('theme-mysite-testimonial', $schema->catalogue());
        $this->assertArrayHasKey('block_theme-mysite-testimonial', $schema->schema([])['$defs']);
        $this->assertStringContainsString(':where(.at-theme-mysite-testimonial-v1 .quote)', ThemeStore::installed()[0]['css']);
        $this->add();
        $this->assertSame([], app(DocumentValidator::class)->validate(app(PageService::class)->editorState($this->f['ctx'], $this->f['pageId'])['draft']['document']));
    }

    public function test_schema_changes_require_a_new_type_and_corrupted_snapshots_fail_closed(): void
    {
        $file = $this->dir.'/source/components/testimonial/component.json';
        $m = json_decode(file_get_contents($file), true);
        $m['version'] = 2;
        $m['props']['quote']['maxLength'] = 1900;
        file_put_contents($file, Json::encode($m));
        $this->assertThrows(fn () => ThemeStore::install($this->dir.'/source'), RuntimeException::class, 'presentation updates only');
        $this->assertCount(1, ThemeStore::installed());
        $snapshot = glob($this->dir.'/installed/*/v1.json')[0];
        $p = json_decode(file_get_contents($snapshot), true);
        $p['css'] .= 'changed';
        file_put_contents($snapshot, Json::encode($p));
        $this->assertThrows(fn () => ThemeStore::installed(), RuntimeException::class, 'snapshot changed');
    }

    public function test_same_version_changes_are_refused_without_replacing_snapshot(): void
    {
        $before = ThemeStore::installed();
        file_put_contents($this->dir.'/source/components/testimonial/styles.css', '.component { padding: 40px; }');
        $this->assertThrows(fn () => ThemeStore::install($this->dir.'/source'), RuntimeException::class, 'immutable');
        $this->assertSame($before, ThemeStore::installed());
    }

    public function test_unsafe_templates_and_css_are_rejected(): void
    {
        $m = ThemeStore::installed()[0]['manifest'];
        foreach (['<script data-part="root">alert(1)</script>', '<div data-part="root" onclick="alert(1)"></div>', '<img data-part="root" data-image="photo" />', '<!DOCTYPE a><div data-part="root" />', '<?php echo 1; ?>'] as $html) {
            $this->assertThrows(fn () => Template::compile($html, $m), RuntimeException::class);
        }
        foreach (['body { color: red; }', '@import "https://example.com/a.css";', '.component { background-color: url(https://example.com/x); }', '.component { animation: fade 1s; }'] as $css) {
            $this->assertThrows(fn () => Template::css($css, $m), RuntimeException::class);
        }
    }

    public function test_theme_command_is_retry_safe_and_invalid_package_installs_nothing(): void
    {
        $this->artisan('arkon:theme', ['action' => 'validate', 'directory' => $this->dir.'/source'])->assertSuccessful();
        $this->artisan('arkon:theme', ['action' => 'install', 'directory' => $this->dir.'/source'])->assertSuccessful();
        $this->assertCount(1, ThemeStore::installed());
        config(['arkon.theme_store' => $this->dir.'/empty']);
        file_put_contents($this->dir.'/source/components/testimonial/template.html', '<iframe data-part="root" />');
        $this->artisan('arkon:theme', ['action' => 'install', 'directory' => $this->dir.'/source'])->assertFailed();
        $this->assertSame([], ThemeStore::installed());
    }
}
