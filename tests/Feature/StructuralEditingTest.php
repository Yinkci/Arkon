<?php

namespace Tests\Feature;

use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Components\DocumentValidator;
use App\Arkon\Components\Factories;
use App\Arkon\Errors\ForbiddenException;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Media\MediaService;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Pages\PageService;
use App\Arkon\Renderer\PageRenderer;
use App\Arkon\Schema\Operations;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Json;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\DatabaseTestCase;

/**
 * The visual builder's structural edits as the server sees them: add, move and
 * remove components (including inside Columns), nesting and link rules enforced
 * on save, and the published result.
 */
class StructuralEditingTest extends DatabaseTestCase
{
    private const HOST = 'builder.test';

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
        $this->addDomain($this->f['siteId'], self::HOST);
    }

    private function pages(): PageService
    {
        return app(PageService::class);
    }

    private function version(): int
    {
        return (int) DB::table('page_drafts')->where('page_id', $this->f['pageId'])->value('version');
    }

    private function save(array $ops, ?SiteContext $ctx = null): array
    {
        // Through JSON, exactly as the editor sends it.
        return $this->pages()->saveDraft($ctx ?? $this->f['ctx'], [
            'pageId' => $this->f['pageId'], 'baseVersion' => $this->version(), 'saveKey' => self::key(),
            'operations' => Json::decode(Json::encode($ops)),
        ]);
    }

    private function publish(?SiteContext $ctx = null): array
    {
        return $this->pages()->publish($ctx ?? $this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => $this->version(), 'idempotencyKey' => self::key()]);
    }

    private function node(string $id, string $type, array $props = [], ?array $children = null): array
    {
        $definition = app(ComponentRegistry::class)->current($type);
        $node = ['id' => $id, 'type' => $type, 'version' => $definition->version, 'props' => $props === [] && $definition->defaultProps === [] ? new \stdClass : [...$definition->defaultProps, ...$props]];
        if ($children !== null) {
            $node['children'] = $children;
        }

        return $node;
    }

    /** Adds: text and button after the hero; columns (col A: text, col B: image + button) at the end. */
    private function buildLayout(?string $assetId = null): void
    {
        $root = Json::entries(Json::decode(DB::table('page_drafts')->where('page_id', $this->f['pageId'])->value('document')))['root'];
        $this->save([
            ['op' => 'insertNode', 'parentId' => $root, 'index' => 1, 'nodes' => [$this->node('text0001', 'text', ['text' => 'Intro paragraph'])]],
            ['op' => 'insertNode', 'parentId' => $root, 'index' => 2, 'nodes' => [$this->node('butn0001', 'button', ['label' => 'Contact us', 'href' => '/contact'])]],
            ['op' => 'insertNode', 'parentId' => $root, 'index' => 3, 'nodes' => [
                $this->node('cols0001', 'columns', ['stackOn' => 'tablet'], ['colu0001', 'colu0002']),
                $this->node('colu0001', 'column', [], []),
                $this->node('colu0002', 'column', [], []),
            ]],
            ['op' => 'insertNode', 'parentId' => 'colu0001', 'index' => 0, 'nodes' => [$this->node('text0002', 'text', ['text' => 'Left', 'element' => 'h2'])]],
            ['op' => 'insertNode', 'parentId' => 'colu0002', 'index' => 0, 'nodes' => [$this->node('imag0001', 'image', ['image' => $assetId ? ['assetId' => $assetId, 'alt' => 'A dot'] : null, 'caption' => 'Dot'])]],
            ['op' => 'insertNode', 'parentId' => 'colu0002', 'index' => 1, 'nodes' => [$this->node('butn0002', 'button', ['label' => 'External', 'href' => 'https://example.com/', 'newTab' => true, 'style' => 'secondary'])]],
        ]);
    }

    private function draft(): array
    {
        return Json::decode(DB::table('page_drafts')->where('page_id', $this->f['pageId'])->value('document'));
    }

    private function assertRejected(array $ops, string $message): void
    {
        $before = $this->version();
        try {
            $this->save($ops);
            $this->fail('Expected the change to be rejected');
        } catch (ValidationException $error) {
            $messages = implode(' | ', [$error->getMessage(), ...array_column($error->issues, 'message')]);
            $this->assertStringContainsString($message, $messages);
        }
        $this->assertSame($before, $this->version(), 'a rejected change saves nothing');
    }

    public function test_components_are_added_nested_reordered_and_removed_through_saves(): void
    {
        $this->buildLayout();
        $doc = $this->draft();
        $root = $doc['nodes'][$doc['root']];
        $this->assertSame([$this->f['heroId'], 'text0001', 'butn0001', 'cols0001'], $root['children']);
        $this->assertSame(2, $root['version'], 'a page is upgraded to the version that allows the new sections');
        $this->assertSame(['imag0001', 'butn0002'], $doc['nodes']['colu0002']['children']);

        // Reorder at the top level, move a block between columns, swap the columns, remove one.
        $this->save([
            ['op' => 'moveNode', 'nodeId' => 'cols0001', 'parentId' => $doc['root'], 'index' => 0],
            ['op' => 'moveNode', 'nodeId' => 'butn0002', 'parentId' => 'colu0001', 'index' => 0],
            ['op' => 'moveNode', 'nodeId' => 'colu0002', 'parentId' => 'cols0001', 'index' => 0],
            ['op' => 'removeNode', 'nodeId' => 'text0001'],
        ]);
        $doc = $this->draft();
        $this->assertSame(['cols0001', $this->f['heroId'], 'butn0001'], $doc['nodes'][$doc['root']]['children']);
        $this->assertSame(['colu0002', 'colu0001'], $doc['nodes']['cols0001']['children']);
        $this->assertSame(['butn0002', 'text0002'], $doc['nodes']['colu0001']['children']);
        $this->assertArrayNotHasKey('text0001', $doc['nodes']);

        // Removing Columns removes its whole subtree; revisions record every step.
        $this->save([['op' => 'removeNode', 'nodeId' => 'cols0001']]);
        $doc = $this->draft();
        foreach (['cols0001', 'colu0001', 'colu0002', 'text0002', 'imag0001', 'butn0002'] as $gone) {
            $this->assertArrayNotHasKey($gone, $doc['nodes']);
        }
        $this->assertSame(3, DB::table('page_revisions')->where('page_id', $this->f['pageId'])->count());
    }

    public function test_the_server_applies_the_editors_undo_operations_back_to_the_original(): void
    {
        $this->buildLayout();
        $before = $this->draft();
        $ops = [
            ['op' => 'moveNode', 'nodeId' => 'butn0002', 'parentId' => 'colu0001', 'index' => 1],
            ['op' => 'removeNode', 'nodeId' => 'colu0002'],
        ];
        $applied = Operations::apply($before, Json::decode(Json::encode($ops)));
        $this->save($ops);
        $this->save($applied['inverse']); // what the editor sends after undo
        $this->assertSame(Json::canonical($before), Json::canonical($this->draft()));
    }

    public function test_invalid_nesting_is_rejected_by_the_server(): void
    {
        $this->buildLayout();
        $root = $this->draft()['root'];
        $this->assertRejected([['op' => 'insertNode', 'parentId' => $root, 'index' => 0, 'nodes' => [$this->node('colu0009', 'column', [], [])]]], 'column is not allowed inside page');
        $this->assertRejected([['op' => 'insertNode', 'parentId' => 'colu0001', 'index' => 0, 'nodes' => [$this->node('hero0009', 'hero', ['heading' => 'x'])]]], 'hero is not allowed inside column');
        $this->assertRejected([['op' => 'insertNode', 'parentId' => 'colu0001', 'index' => 0, 'nodes' => [
            $this->node('cols0009', 'columns', [], ['colu0009']), $this->node('colu0009', 'column', [], []),
        ]]], 'columns is not allowed inside column');
        $this->assertRejected([['op' => 'moveNode', 'nodeId' => 'text0001', 'parentId' => 'cols0001', 'index' => 0]], 'text is not allowed inside columns');
        $this->assertRejected([['op' => 'moveNode', 'nodeId' => 'cols0001', 'parentId' => 'colu0001', 'index' => 0]], 'cannot be moved into itself');
        $this->assertRejected([['op' => 'insertNode', 'parentId' => 'text0001', 'index' => 0, 'nodes' => [$this->node('text0009', 'text')]]], 'cannot have children');
        $this->assertRejected([['op' => 'removeNode', 'nodeId' => 'colu0001'], ['op' => 'removeNode', 'nodeId' => 'colu0002']], 'At least 1 children required');
        $five = [];
        foreach (['colu0005', 'colu0006', 'colu0007'] as $i => $id) {
            $five[] = ['op' => 'insertNode', 'parentId' => 'cols0001', 'index' => 2 + $i, 'nodes' => [$this->node($id, 'column', [], [])]];
        }
        $this->assertRejected($five, 'At most 4 children allowed');
    }

    public function test_unsafe_links_are_rejected_by_the_server_and_safe_ones_accepted(): void
    {
        $this->buildLayout();
        foreach (['javascript:alert(1)', 'JAVASCRIPT:alert(1)', 'data:text/html,<script>x</script>', '//evil.example', 'vbscript:x', ' /x', '/a b'] as $href) {
            $this->assertRejected([['op' => 'updateProps', 'nodeId' => 'butn0001', 'set' => ['href' => $href]]], 'Use a link starting with');
        }
        foreach (['/pricing#plans', '#top', '?q=1', 'https://example.com/a?b=c', 'mailto:hi@example.com', 'tel:+15551234'] as $href) {
            $this->save([['op' => 'updateProps', 'nodeId' => 'butn0001', 'set' => ['href' => $href]]]);
        }
    }

    public function test_publishing_renders_clean_semantic_html_and_makes_nested_images_public(): void
    {
        $asset = app(MediaService::class)->upload($this->f['ctx'], self::png(), 'dot.png');
        $this->buildLayout($asset['id']);
        $this->publish();
        $html = $this->pages()->livePage($this->f['siteId'], '/')->html;

        $this->assertStringContainsString('<p class="ak-text">Intro paragraph</p>', $html);
        $this->assertStringContainsString('<p class="ak-action"><a class="ak-button ak-button--primary" href="/contact">Contact us</a></p>', $html);
        $this->assertStringContainsString('<div class="ak-columns ak-columns--stack-tablet ak-columns--n2"><div class="ak-column"><h2 class="ak-text">Left</h2></div>', $html);
        $this->assertMatchesRegularExpression('#<figure class="ak-image ak-image--full"><img src="/media/[0-9a-f-]+\.png" alt="A dot" width="1" height="1" decoding="async" loading="lazy"><figcaption>Dot</figcaption></figure>#', $html);
        $this->assertStringContainsString('<a class="ak-button ak-button--secondary" href="https://example.com/" target="_blank" rel="noopener noreferrer">External</a>', $html);
        foreach (['data-ak-', '<script', 'contenteditable', 'ak-image__empty', 'Empty column', 'draggable', '/build/'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $html);
        }
        $this->assertSame(['button@1', 'column@1', 'columns@1', 'hero@1', 'image@1', 'page@2', 'text@1'], json_decode(DB::table('publications')->value('render_inputs'), true)['components']);
        // The image nested two levels deep is linked to the publication and therefore public on the site's host.
        $access = app(MediaService::class)->resolveAccess(substr($asset['url'], 7), self::HOST, null, null, new MediaSigner);
        $this->assertSame('public', $access['access'] ?? null);
    }

    public function test_publish_checks_cover_the_new_components(): void
    {
        $this->buildLayout(); // the image block has no image yet
        $this->save([['op' => 'updateProps', 'nodeId' => 'butn0001', 'set' => ['label' => ' ', 'href' => '']], ['op' => 'updateProps', 'nodeId' => 'text0002', 'set' => ['text' => '']]]);
        try {
            $this->publish();
            $this->fail('Expected publishing to be blocked');
        } catch (ValidationException $error) {
            $this->assertEqualsCanonicalizing(
                ['Image block has no image', 'Button needs a label', 'Button needs a link', 'Text block is empty'],
                array_column($error->issues, 'message'),
            );
        }
        $this->assertSame(0, DB::table('publications')->count());
    }

    public function test_editors_may_build_viewers_may_not_and_only_publishers_publish(): void
    {
        $editor = $this->addMember($this->f['siteId'], 'editor');
        $viewer = $this->addMember($this->f['siteId'], 'viewer');
        $root = $this->draft()['root'];
        $this->save([['op' => 'insertNode', 'parentId' => $root, 'index' => 1, 'nodes' => [$this->node('text0001', 'text')]]], $editor);
        $this->assertThrows(fn () => $this->save([['op' => 'removeNode', 'nodeId' => 'text0001']], $viewer), ForbiddenException::class);
        $this->assertThrows(fn () => $this->publish($editor), ForbiddenException::class);
        $outsider = $this->siteFixture()['ctx'];
        $this->assertThrows(fn () => $this->save([['op' => 'removeNode', 'nodeId' => 'text0001']], new SiteContext($this->f['siteId'], $outsider->userId)), NotFoundException::class);
        $this->assertArrayHasKey('text0001', $this->draft()['nodes']);
    }

    public function test_a_nested_image_cannot_use_another_sites_asset(): void
    {
        $other = $this->siteFixture();
        $foreign = app(MediaService::class)->upload($other['ctx'], self::png(), 'theirs.png');
        $this->buildLayout();
        $this->assertRejected([['op' => 'updateProps', 'nodeId' => 'imag0001', 'set' => ['image' => ['assetId' => $foreign['id'], 'alt' => 'x']]]], 'does not exist');
    }

    public function test_pages_published_before_this_milestone_still_reproduce_and_then_migrate_forward(): void
    {
        // The registry as it was before page v2 and the new components.
        $dir = storage_path('testing/components-'.uniqid());
        File::copyDirectory(resource_path('arkon/components'), $dir);
        File::delete(["{$dir}/page/v2.json", "{$dir}/page/v2.css"]);
        File::deleteDirectory("{$dir}/text");
        $this->app->instance(ComponentRegistry::class, new ComponentRegistry($dir, ComponentRegistry::RENDERERS));
        $this->app->forgetInstance(DocumentValidator::class);
        $this->app->forgetInstance(PageRenderer::class);
        // Created under the old registry: page v1.
        $pageId = $this->addPage($this->f['siteId'], '/old', 'Old', Factories::pageDocument([Factories::heroNode(['heading' => 'Old page'])]));
        $old = $this->pages()->publish($this->f['ctx'], ['pageId' => $pageId, 'expectedVersion' => 1, 'idempotencyKey' => self::key()]);

        // Deploy this milestone.
        $this->app->instance(ComponentRegistry::class, ComponentRegistry::default());
        $this->app->forgetInstance(DocumentValidator::class);
        $this->app->forgetInstance(PageRenderer::class);
        File::deleteDirectory($dir);

        $result = $this->pages()->reproducePublication($this->f['siteId'], $old['publicationId']);
        $this->assertSame('reproduced', $result['status'], (string) $result['reason']);
        $this->assertTrue($result['matches']);
        $this->assertContains('page@1', json_decode(DB::table('publications')->where('id', $old['publicationId'])->value('render_inputs'), true)['components']);

        // Editing moves it to page v2, so the new components can be added.
        $state = $this->pages()->editorState($this->f['ctx'], $pageId);
        $doc = $state['draft']['document'];
        $this->assertSame(2, $doc['nodes'][$doc['root']]['version']);
        $this->pages()->saveDraft($this->f['ctx'], ['pageId' => $pageId, 'baseVersion' => 1, 'saveKey' => self::key(), 'operations' => Json::decode(Json::encode([
            ['op' => 'insertNode', 'parentId' => $doc['root'], 'index' => 1, 'nodes' => [$this->node('text0001', 'text', ['text' => 'New block'])]],
        ]))]);
        $this->assertTrue($this->pages()->reproducePublication($this->f['siteId'], $old['publicationId'])['matches'], 'the old publication still reproduces after editing');
    }
}
