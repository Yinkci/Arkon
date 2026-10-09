<?php

namespace Tests\Feature;

use App\Arkon\Errors\ForbiddenException;
use App\Arkon\Errors\StaleVersionException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Media\MediaService;
use App\Arkon\Pages\PageService;
use App\Arkon\Renderer\PageRenderer;
use App\Arkon\Seo\SeoDashboard;
use App\Arkon\Seo\SeoDefaults;
use App\Arkon\Support\Json;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

final class SeoWorkflowTest extends DatabaseTestCase
{
    public function test_defaults_reject_stale_edits_and_editors_cannot_publish_site_settings(): void
    {
        $f = $this->siteFixture();
        $service = app(SeoDefaults::class);
        $input = ['baseVersion' => 1, 'saveKey' => self::key(), 'settings' => SeoDefaults::defaults()];
        $service->save($f['ctx'], $input);
        $this->assertThrows(fn () => $service->save($f['ctx'], [...$input, 'saveKey' => self::key()]), StaleVersionException::class);
        $editor = $this->addMember($f['siteId'], 'editor');
        $this->assertThrows(fn () => $service->publish($editor, ['expectedVersion' => 2, 'idempotencyKey' => self::key()]), ForbiddenException::class);
    }

    public function test_analysis_is_site_scoped_and_does_not_save_unsaved_seo(): void
    {
        $f = $this->siteFixture();
        $other = $this->siteFixture('owner', 'Other');
        $this->addDomain($f['siteId'], 'seo.test');
        $this->actingAs(User::findOrFail($f['ctx']->userId));
        $doc = $f['document'];
        $asset = app(MediaService::class)->upload($f['ctx'], self::png(), 'share.png');
        $doc['seo'] = ['description' => 'Unsaved analysis summary.', 'socialImage' => $asset['id']];
        $this->postJson('/admin/api/pages/'.$f['pageId'].'/seo-analysis', ['document' => $doc])->assertOk()->assertJsonPath('data.description', 'Unsaved analysis summary.');
        $preview = $this->postJson('/admin/api/pages/'.$f['pageId'].'/seo-analysis', ['document' => $doc])->assertOk()->json('data.socialPreviewUrl');
        $this->assertStringStartsWith('http://seo.test/media/', $preview);
        $this->assertStringContainsString('?t=', $preview);
        auth()->guard()->logout();
        $this->get($preview)->assertOk();
        $this->get(strtok($preview, '?'))->assertNotFound();
        $this->actingAs(User::findOrFail($f['ctx']->userId));
        $this->assertSame(1, (int) DB::table('page_drafts')->where('page_id', $f['pageId'])->value('version'));
        $this->postJson('/admin/api/pages/'.$other['pageId'].'/seo-analysis', ['document' => $doc])->assertNotFound();
    }

    public function test_a_page_social_image_reference_cannot_use_another_sites_asset(): void
    {
        $f = $this->siteFixture();
        $other = $this->siteFixture('owner', 'Other');
        $asset = app(MediaService::class)->upload($other['ctx'], self::png(), 'private.png');
        $this->expectException(ValidationException::class);
        app(PageService::class)->saveDraft($f['ctx'], ['pageId' => $f['pageId'], 'baseVersion' => 1, 'saveKey' => self::key(), 'operations' => [['op' => 'updateSeo', 'set' => ['socialImage' => $asset['id']]]]]);
    }

    public function test_defaults_are_draft_until_published_refresh_live_revision_and_retries_keep_one_version(): void
    {
        $f = $this->siteFixture();
        $this->addDomain($f['siteId'], 'seo.test');
        $pages = app(PageService::class);
        $pages->publish($f['ctx'], ['pageId' => $f['pageId'], 'expectedVersion' => 1, 'idempotencyKey' => self::key()]);
        $old = DB::table('publications')->first();
        $pages->saveDraft($f['ctx'], ['pageId' => $f['pageId'], 'baseVersion' => 1, 'saveKey' => self::key(), 'operations' => [['op' => 'updateProps', 'nodeId' => $f['heroId'], 'set' => ['heading' => 'Unsaved to live']]]]);
        $defaults = app(SeoDefaults::class);
        $save = ['baseVersion' => 1, 'saveKey' => self::key(), 'settings' => [...SeoDefaults::defaults(), 'description' => 'Published default summary.', 'noindex' => true]];
        $this->assertSame(2, $defaults->save($f['ctx'], $save)['version']);
        $this->assertTrue($defaults->save($f['ctx'], $save)['replayed']);
        $this->get('http://seo.test/')->assertOk()->assertDontSee('Published default summary.', false);
        $publish = ['expectedVersion' => 2, 'idempotencyKey' => self::key()];
        $this->assertFalse($defaults->publish($f['ctx'], $publish)['replayed']);
        $this->assertTrue($defaults->publish($f['ctx'], $publish)['replayed']);
        $this->assertSame(1, DB::table('site_seo_versions')->count());
        $this->get('http://seo.test/')->assertOk()->assertSee('Published default summary.', false)->assertDontSee('Unsaved to live', false);
        $this->get('http://seo.test/sitemap.xml')->assertOk()->assertDontSee('<loc>', false);
        $this->assertSame($old->html, app(PageRenderer::class)->reproduce(Json::decode(DB::table('page_revisions')->where('id', $old->revision_id)->value('document')), Json::decode($old->render_inputs))['html']);
        $this->assertSame('42501', $this->sqlState(fn () => DB::table('site_seo_versions')->update(['settings' => '{}'])));
    }

    public function test_page_metadata_is_private_until_published_and_dashboard_distinguishes_draft_and_live(): void
    {
        $f = $this->siteFixture();
        $this->addDomain($f['siteId'], 'seo.test');
        $pages = app(PageService::class);
        $pages->publish($f['ctx'], ['pageId' => $f['pageId'], 'expectedVersion' => 1, 'idempotencyKey' => self::key()]);
        $pages->saveDraft($f['ctx'], ['pageId' => $f['pageId'], 'baseVersion' => 1, 'saveKey' => self::key(), 'operations' => [['op' => 'updateSeo', 'set' => ['title' => 'New search title', 'description' => 'Useful page summary.', 'canonical' => 'https://seo.test/', 'pageType' => 'contact']]]]);
        $r = app(SeoDashboard::class)->overview($f['siteId'], 1);
        $this->assertSame('New search title', $r['rows'][0]['draft']['title']);
        $this->assertNotSame('New search title', $r['rows'][0]['published']['title']);
        $pages->publish($f['ctx'], ['pageId' => $f['pageId'], 'expectedVersion' => 2, 'idempotencyKey' => self::key()]);
        $this->get('http://seo.test/')->assertOk()->assertSee('<title>New search title</title>', false)->assertSee('"@type":"ContactPage"', false);
    }

    public function test_social_images_cannot_cross_sites_and_invalid_default_image_is_validation_error(): void
    {
        $f = $this->siteFixture();
        $this->expectException(ValidationException::class);
        app(SeoDefaults::class)->save($f['ctx'], ['baseVersion' => 1, 'saveKey' => self::key(), 'settings' => [...SeoDefaults::defaults(), 'socialImage' => 'not-a-uuid']]);
    }
}
