<?php

namespace Tests\Feature;

use App\Arkon\Ai\Duplicates;
use App\Arkon\Ai\ProposalCompiler;
use App\Arkon\Ai\WebsiteProposalService;
use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Components\Factories;
use App\Arkon\Errors\ForbiddenException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Navigation\MenuService;
use App\Arkon\Pages\PageManagement;
use App\Arkon\Pages\PageService;
use App\Arkon\Renderer\PageRenderer;
use App\Arkon\Sites\WebsitePublishing;
use App\Arkon\Support\Json;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

final class NavigationTest extends DatabaseTestCase
{
    private function definition(array $f, string $label = 'Home'): array
    {
        return ['name' => 'Main navigation', 'items' => [['id' => 'home', 'label' => $label, 'type' => 'page', 'pageId' => $f['pageId'], 'href' => '', 'anchor' => '', 'parentId' => null]]];
    }

    public function test_menu_drafts_are_retry_safe_and_do_not_change_the_published_definition(): void
    {
        $f = $this->siteFixture();
        $s = app(MenuService::class);
        $input = ['baseVersion' => 0, 'requestKey' => self::key(), 'definition' => $this->definition($f)];
        $a = $s->save($f['ctx'], $input);
        $this->assertTrue($s->save($f['ctx'], $input)['replayed']);
        $this->assertSame(1, DB::table('site_menus')->count());
        $s->publish($f['ctx'], $a['id'], ['expectedVersion' => 1, 'requestKey' => self::key()]);
        $s->save($f['ctx'], ['id' => $a['id'], 'baseVersion' => 1, 'requestKey' => self::key(), 'definition' => $this->definition($f, 'Changed draft')]);
        $old = Json::decode(DB::table('site_menu_versions')->value('definition'));
        $this->assertSame('Home', $old['items'][0]['label']);
        $this->assertSame('Changed draft', $s->list($f['ctx'])[0]['definition']['items'][0]['label']);
        $foreign = $this->siteFixture('owner', 'Other site');
        $this->expectException(ValidationException::class);
        $s->save($f['ctx'], ['baseVersion' => 0, 'requestKey' => self::key(), 'definition' => $this->definition($foreign)]);
    }

    public function test_cyclic_dropdowns_and_script_links_are_refused(): void
    {
        $f = $this->siteFixture();
        $definition = $this->definition($f);
        $definition['items'][0]['parentId'] = 'child';
        $definition['items'][] = [...$definition['items'][0], 'id' => 'child', 'parentId' => 'home'];
        try {
            MenuService::validateDefinition($definition);
            $this->fail('Cycle accepted');
        } catch (ValidationException) {
        }
        $definition = $this->definition($f);
        $definition['items'][0] = [...$definition['items'][0], 'type' => 'url', 'href' => 'javascript:alert(1)'];
        $this->expectException(ValidationException::class);
        MenuService::validateDefinition($definition);
    }

    public function test_local_layout_proposal_preserves_content_and_is_reviewed_before_writing_drafts(): void
    {
        $f = $this->siteFixture();
        $s = app(WebsiteProposalService::class);
        $key = self::key();
        $a = $s->prepareLayout($f['ctx'], ['requestKey' => $key]);
        $this->assertSame($a['id'], $s->prepareLayout($f['ctx'], ['requestKey' => $key])['id']);
        $this->assertSame(0, DB::table('site_menus')->count());
        $this->assertSame(0, DB::table('reusable_components')->count());
        $this->assertSame('local', DB::table('ai_proposals')->value('provider'));
        $applied = $s->apply($f['ctx'], $a['id']);
        $this->assertSame(0, DB::table('publications')->count());
        $doc = Json::decode(DB::table('page_drafts')->where('page_id', $f['pageId'])->value('document'));
        $this->assertArrayHasKey($f['heroId'], Json::entries($doc['nodes']));
        $this->assertCount(2, $applied['shared']);
        $this->assertNotNull($applied['menu']);
        $ready = app(WebsitePublishing::class)->readiness($f['ctx'], $a['id']);
        $this->assertTrue($ready['ready'], Json::encode($ready));
    }

    public function test_navigation_is_script_free_and_pinned_while_menu_publish_refreshes_live_pages(): void
    {
        $f = $this->siteFixture();
        $s = app(MenuService::class);
        $menu = $s->save($f['ctx'], ['baseVersion' => 0, 'requestKey' => self::key(), 'definition' => $this->definition($f)]);
        $s->publish($f['ctx'], $menu['id'], ['expectedVersion' => 1, 'requestKey' => self::key()]);
        $def = app(ComponentRegistry::class)->current('navigation');
        $nav = ['id' => 'n-navigation', 'type' => 'navigation', 'version' => $def->version, 'props' => ['menuId' => $menu['id']]];
        $doc = Factories::pageDocument([Factories::heroNode(['heading' => 'Welcome']), $nav]);
        DB::table('page_drafts')->where('page_id', $f['pageId'])->update(['document' => Json::encode($doc)]);
        $pub = app(PageService::class)->publish($f['ctx'], ['pageId' => $f['pageId'], 'expectedVersion' => 1, 'idempotencyKey' => self::key()]);
        $row = DB::table('publications')->where('id', $pub['publicationId'])->first();
        $this->assertStringContainsString('<nav', $row->html);
        $this->assertStringContainsString('<details', $row->html);
        $this->assertDoesNotMatchRegularExpression('/<script(?! type="application\/ld\+json")/i', $row->html);
        $this->assertStringNotContainsString('data-ak-', $row->html);
        $this->assertArrayHasKey($menu['id'], Json::decode($row->render_inputs)['menus']);
        $s->save($f['ctx'], ['id' => $menu['id'], 'baseVersion' => 1, 'requestKey' => self::key(), 'definition' => $this->definition($f, 'New Home')]);
        $s->publish($f['ctx'], $menu['id'], ['expectedVersion' => 2, 'requestKey' => self::key()]);
        $live = DB::table('live_pages as l')->join('publications as p', 'p.id', '=', 'l.publication_id')->where('l.page_id', $f['pageId'])->first(['p.html']);
        $this->assertTrue(str_contains($live->html, 'New Home'), Json::encode(['refreshes' => DB::table('page_refreshes')->get()->all(), 'deps' => DB::table('publication_dependencies')->get()->all()]));
        $reproduced = app(PageRenderer::class)->reproduce($doc, Json::decode($row->render_inputs));
        $this->assertSame($row->html, $reproduced['html']);
    }

    public function test_page_links_follow_published_paths_and_unpublishing_without_reading_drafts(): void
    {
        $f = $this->siteFixture();
        $target = $this->addPage($f['siteId'], '/services', 'Services');
        $menus = app(MenuService::class);
        $definition = $this->definition([...$f, 'pageId' => $target], 'Services');
        $m = $menus->save($f['ctx'], ['baseVersion' => 0, 'requestKey' => self::key(), 'definition' => $definition]);
        $menus->publish($f['ctx'], $m['id'], ['expectedVersion' => 1, 'requestKey' => self::key()]);
        $service = app(PageService::class);
        $service->publish($f['ctx'], ['pageId' => $target, 'expectedVersion' => 1, 'idempotencyKey' => self::key()]);
        $nav = ['id' => 'n-navigation', 'type' => 'navigation', 'version' => 1, 'props' => ['menuId' => $m['id']]];
        $doc = Factories::pageDocument([Factories::heroNode(['heading' => 'Welcome']), $nav]);
        DB::table('page_drafts')->where('page_id', $f['pageId'])->update(['document' => Json::encode($doc)]);
        $home = $service->publish($f['ctx'], ['pageId' => $f['pageId'], 'expectedVersion' => 1, 'idempotencyKey' => self::key()]);
        $old = DB::table('publications')->where('id', $home['publicationId'])->first();
        $this->assertStringContainsString('href="/services"', $old->html);
        $management = app(PageManagement::class);
        $management->updateSettings($f['ctx'], ['pageId' => $target, 'expectedVersion' => 1, 'saveKey' => self::key(), 'title' => 'Services', 'path' => '/offerings']);
        $this->assertSame('/services', MenuService::resolve($f['siteId'], $definition)['items'][0]['resolvedHref']);
        $published = $service->publish($f['ctx'], ['pageId' => $target, 'expectedVersion' => 2, 'idempotencyKey' => self::key()]);
        $html = fn () => DB::table('live_pages as l')->join('publications as p', 'p.id', '=', 'l.publication_id')->where('l.page_id', $f['pageId'])->value('p.html');
        $this->assertStringContainsString('href="/offerings"', $html());
        $management->unpublish($f['ctx'], ['pageId' => $target, 'expectedPublicationId' => $published['publicationId']]);
        $this->assertStringContainsString('href="#"', $html());
        $this->assertSame($old->html, app(PageRenderer::class)->reproduce($doc, Json::decode($old->render_inputs))['html']);
    }

    public function test_editor_can_save_menus_but_cannot_publish_and_history_is_immutable(): void
    {
        $f = $this->siteFixture();
        $s = app(MenuService::class);
        $editor = $this->addMember($f['siteId'], 'editor');
        $a = $s->save($editor, ['baseVersion' => 0, 'requestKey' => self::key(), 'definition' => $this->definition($f)]);
        try {
            $s->publish($editor, $a['id'], ['expectedVersion' => 1, 'requestKey' => self::key()]);
            $this->fail('Editor published a menu');
        } catch (ForbiddenException) {
        }
        $s->publish($f['ctx'], $a['id'], ['expectedVersion' => 1, 'requestKey' => self::key()]);
        $this->assertSame('42501', $this->sqlState(fn () => DB::table('site_menu_versions')->update(['definition' => '{}'])));
    }

    public function test_existing_empty_shared_resources_are_repaired_but_nonempty_headers_are_preserved(): void
    {
        $f = $this->siteFixture();
        $s = app(WebsiteProposalService::class);
        $snapshot = $s->snapshot($f['ctx']);
        $reply = ['summary' => 'Keep homepage', 'pages' => [['pageId' => $f['pageId'], 'title' => 'Home', 'path' => '/', 'seo' => ['title' => 'Home', 'description' => 'Test website'], 'proposal' => ['summary' => 'Keep', 'notes' => [], 'tokenChanges' => [], 'changes' => []]]], 'header' => null, 'footer' => null, 'menu' => null, 'form' => null, 'tokenChanges' => []];
        $snapshot['shared']['header']['version'] = 1;
        $compiled = $s->compile($snapshot, $reply);
        $this->assertTrue($compiled['shared']['header']['changed']);
        $snapshot['shared']['header']['document'] = Factories::pageDocument([['id' => 'header-text', 'type' => 'text', 'version' => 3, 'props' => ['text' => 'Custom brand']]]);
        $compiled = $s->compile($snapshot, $reply);
        $this->assertFalse($compiled['shared']['header']['changed']);
        $this->assertArrayHasKey('header-text', Json::entries($compiled['shared']['header']['document']['nodes']));
    }

    public function test_new_buttons_use_hash_but_existing_blank_links_are_not_silently_rewritten(): void
    {
        $registry = app(ComponentRegistry::class);
        $this->assertSame('#', $registry->current('button')->defaultProps['href']);
        $doc = Factories::pageDocument([['id' => 'old-button', 'type' => 'button', 'version' => 3, 'props' => ['label' => 'Old', 'href' => '']]]);
        $upgraded = $registry->migrateDocument($doc);
        $this->assertSame('', $upgraded['nodes']['old-button']['props']['href']);
        $compiler = app(ProposalCompiler::class);
        $result = $compiler->compile(Factories::pageDocument(), ['summary' => 'Button', 'notes' => [], 'tokenChanges' => [], 'changes' => [['action' => 'add', 'parent' => 'page', 'index' => null, 'ref' => null, 'block' => ['type' => 'button', 'props' => ['label' => 'Contact', 'href' => '']]]]], [], 20);
        $buttons = array_values(array_filter(Json::entries($result['document']['nodes']), fn ($n) => $n['type'] === 'button'));
        $this->assertSame('#', $buttons[0]['props']['href']);
    }

    public function test_empty_http_fields_and_nested_publication_paths_are_handled(): void
    {
        $f = $this->siteFixture();
        $definition = $this->definition($f);
        $definition['items'][0]['href'] = null;
        $definition['items'][0]['anchor'] = null;
        $normalized = MenuService::validateDefinition($definition);
        $this->assertSame('', $normalized['items'][0]['anchor']);
        $about = $this->addPage($f['siteId'], '/about', 'About');
        $definition['items'][] = [...$definition['items'][0], 'id' => 'about', 'pageId' => $about];
        $resolved = MenuService::withPaths($f['siteId'], [$about => '/about'], fn () => MenuService::withPaths($f['siteId'], [$f['pageId'] => '/'], fn () => MenuService::resolve($f['siteId'], $definition)));
        $this->assertSame('/', $resolved['items'][0]['resolvedHref']);
        $this->assertSame('/about', $resolved['items'][1]['resolvedHref']);
        $this->assertSame('#', MenuService::resolve($f['siteId'], $definition)['items'][1]['resolvedHref']);
    }

    public function test_section_duplication_allocates_a_unique_anchor(): void
    {
        $doc = Factories::pageDocument([['id' => 'services-section', 'type' => 'section', 'version' => 5, 'props' => ['anchor' => 'services'], 'children' => []]]);
        $copy = Duplicates::of($doc, 'services-section');
        $this->assertSame('services-2', $copy['nodes'][0]['props']['anchor']);
        $this->assertSame('services', $doc['nodes']['services-section']['props']['anchor']);
    }

    public function test_standalone_proposals_keep_shared_layout_unchanged(): void
    {
        $f = $this->siteFixture();
        $service = app(WebsiteProposalService::class);
        $snapshot = $service->snapshot($f['ctx']);
        $snapshot['includeLayout'] = false;
        $reply = ['summary' => 'Standalone', 'pages' => [['pageId' => $f['pageId'], 'title' => 'Home', 'path' => '/', 'seo' => ['title' => 'Home', 'description' => 'A standalone page'], 'proposal' => ['summary' => 'Keep', 'notes' => [], 'tokenChanges' => [], 'changes' => []]]], 'header' => null, 'footer' => null, 'menu' => null, 'form' => null, 'tokenChanges' => []];
        $compiled = $service->compile($snapshot, $reply);
        $this->assertSame([], $compiled['shared']);
        $this->assertNull($compiled['menu']);
    }

    public function test_local_section_repair_is_retry_safe_and_does_not_write_the_original_page(): void
    {
        $f = $this->siteFixture();
        $doc = Factories::pageDocument([Factories::heroNode(['heading' => 'Welcome']), ['id' => 'services-band', 'type' => 'section', 'version' => 5, 'props' => new \stdClass, 'children' => ['services-heading']]]);
        $doc['nodes']['services-heading'] = ['id' => 'services-heading', 'type' => 'text', 'version' => 3, 'props' => ['text' => 'Our services', 'element' => 'h2']];
        DB::table('page_drafts')->where('page_id', $f['pageId'])->update(['document' => Json::encode($doc)]);
        $service = app(WebsiteProposalService::class);
        $key = self::key();
        $proposal = $service->prepareLayout($f['ctx'], ['requestKey' => $key]);
        $this->assertSame($proposal['id'], $service->prepareLayout($f['ctx'], ['requestKey' => $key])['id']);
        $this->assertSame(1, DB::table('ai_proposals')->count());
        $this->assertSame('services', $proposal['result']['pages'][0]['document']['nodes']['services-band']['props']['anchor']);
        $this->assertContains('Services', array_column($proposal['result']['menu']['definition']['items'], 'label'));
        $stored = Json::decode(DB::table('page_drafts')->where('page_id', $f['pageId'])->value('document'));
        $this->assertArrayNotHasKey('anchor', Json::entries($stored['nodes']['services-band']['props']));
    }
}
