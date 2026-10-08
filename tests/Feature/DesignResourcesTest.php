<?php

namespace Tests\Feature;

use App\Arkon\Database\MigrationConfig;
use App\Arkon\Design\ComponentService;
use App\Arkon\Design\PageRefreshes;
use App\Arkon\Design\TokenService;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\ForbiddenException;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Errors\StaleVersionException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Media\MediaService;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Pages\PageManagement;
use App\Arkon\Pages\PageService;
use App\Arkon\Schema\Operations;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Json;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\DatabaseTestCase;

/**
 * Shared design resources and their published dependants: design tokens and reusable
 * components have drafts that never reach live pages, immutable published versions that
 * publications record (and reproduce from), and publishing one re-renders the live pages
 * that use it from their live revisions, epoch-ordered, with status and retries.
 */
class DesignResourcesTest extends DatabaseTestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
        $this->addDomain($this->f['siteId'], 'design.test');
    }

    private function pages(): PageService
    {
        return app(PageService::class);
    }

    private function tokens(): TokenService
    {
        return app(TokenService::class);
    }

    private function components(): ComponentService
    {
        return app(ComponentService::class);
    }

    private function version(string $pageId): int
    {
        return (int) DB::table('page_drafts')->where('page_id', $pageId)->value('version');
    }

    private function save(array $ops, ?string $pageId = null, ?SiteContext $ctx = null): array
    {
        $pageId ??= $this->f['pageId'];

        return $this->pages()->saveDraft($ctx ?? $this->f['ctx'], [
            'pageId' => $pageId, 'baseVersion' => $this->version($pageId), 'saveKey' => self::key(), 'operations' => Json::decode(Json::encode($ops)),
        ]);
    }

    private function publishPage(?string $pageId = null): array
    {
        $pageId ??= $this->f['pageId'];

        return $this->pages()->publish($this->f['ctx'], ['pageId' => $pageId, 'expectedVersion' => $this->version($pageId), 'idempotencyKey' => self::key()]);
    }

    private function live(string $path = '/'): string
    {
        return (string) $this->pages()->livePage($this->f['siteId'], $path)?->html;
    }

    private function saveTokens(array $tokens, ?SiteContext $ctx = null): array
    {
        $state = $this->tokens()->state($ctx ?? $this->f['ctx']);

        return $this->tokens()->save($ctx ?? $this->f['ctx'], ['baseVersion' => $state['version'], 'tokens' => Json::decode(Json::encode($tokens)), 'saveKey' => self::key()]);
    }

    private function publishTokens(?SiteContext $ctx = null): array
    {
        $state = $this->tokens()->state($ctx ?? $this->f['ctx']);

        return $this->tokens()->publish($ctx ?? $this->f['ctx'], ['expectedVersion' => $state['version'], 'idempotencyKey' => self::key()]);
    }

    /** A component holding one text block, published once. */
    private function publishedComponent(string $text = 'Free delivery on every order', string $name = 'Banner'): string
    {
        $id = $this->components()->create($this->f['ctx'], ['name' => $name, 'nodes' => Json::decode(Json::encode([
            ['id' => 'bant0001', 'type' => 'text', 'version' => 3, 'props' => ['text' => $text]],
        ]))])['id'];
        $this->components()->publish($this->f['ctx'], $id, ['expectedVersion' => 1, 'idempotencyKey' => self::key()]);

        return $id;
    }

    private function editComponentText(string $id, string $text): int
    {
        $row = DB::table('reusable_components')->where('id', $id)->first();

        return $this->components()->save($this->f['ctx'], $id, ['baseVersion' => (int) $row->version, 'saveKey' => self::key(), 'operations' => [
            ['op' => 'updateProps', 'nodeId' => 'bant0001', 'set' => ['text' => $text]],
        ]])['version'];
    }

    private function addInstance(string $componentId, string $nodeId = 'inst0001', array $style = []): void
    {
        $root = Json::decode(DB::table('page_drafts')->where('page_id', $this->f['pageId'])->value('document'))['root'];
        $this->save([['op' => 'insertNode', 'parentId' => $root, 'index' => 1, 'nodes' => [
            ['id' => $nodeId, 'type' => 'instance', 'version' => 2, 'props' => ['componentId' => $componentId, 'style' => $style === [] ? new \stdClass : $style]],
        ]]]);
    }

    // ── Design tokens ──────────────────────────────────────────────────────

    public function test_token_drafts_are_private_and_publishing_refreshes_live_pages_from_their_live_revision(): void
    {
        $this->publishPage();
        $before = $this->live();
        $this->assertStringContainsString('--ak-t-color-primary:#4f46e5', $before, 'defaults until tokens are published');

        // A draft changes nothing live, nor the preview or canvas (they use published tokens).
        $this->saveTokens(['color' => ['primary' => '#0f766e', 'text' => '#1c1917']]);
        $this->assertSame($before, $this->live());
        $this->assertStringNotContainsString('#0f766e', $this->pages()->renderPreview($this->f['ctx'], $this->f['pageId']));
        $this->assertStringNotContainsString('#0f766e', $this->pages()->renderCanvas($this->f['ctx'], $this->f['pageId'], Json::decode(DB::table('page_drafts')->where('page_id', $this->f['pageId'])->value('document')), new MediaSigner)['css']);

        // An unpublished page edit must not go live with the token refresh.
        $this->save([['op' => 'updateProps', 'nodeId' => $this->f['heroId'], 'set' => ['heading' => 'Draft only']]]);
        $published = $this->publishTokens();
        $this->assertSame(1, $published['version']);
        $this->assertSame(['queued' => 1, 'done' => 1, 'skipped' => 0, 'failed' => 0], $published['refreshes']);

        $after = $this->live();
        $this->assertStringContainsString('--ak-t-color-primary:#0f766e', $after);
        $this->assertStringContainsString('--ak-t-color-text:#1c1917', $after);
        $this->assertStringContainsString('Original heading', $after);
        $this->assertStringNotContainsString('Draft only', $after);
        $live = DB::table('live_pages')->where('page_id', $this->f['pageId'])->first();
        $publication = DB::table('publications')->where('id', $live->publication_id)->first();
        $this->assertEquals(['version' => 1, 'values' => ['color' => ['primary' => '#0f766e', 'text' => '#1c1917']]], json_decode($publication->render_inputs, true)['tokens']);
        $this->assertSame(1, DB::table('publication_dependencies')->where('publication_id', $publication->id)->where('kind', 'tokens')->value('version'));
        $this->assertSame('done', DB::table('page_refreshes')->value('status'));
        $this->assertSame('changed', $this->pages()->listPages($this->f['ctx'])[0]['status'], 'the draft edit is still unpublished');
    }

    public function test_token_saves_are_validated_versioned_and_idempotent_and_publishing_needs_permission(): void
    {
        $this->assertThrows(fn () => $this->saveTokens(['color' => ['primary' => 'red;}body{display:none']]), ValidationException::class);
        $this->assertThrows(fn () => $this->saveTokens(['color' => ['brand' => '#fff']]), ValidationException::class);
        $key = self::key();
        $first = $this->tokens()->save($this->f['ctx'], ['baseVersion' => 1, 'tokens' => Json::decode('{"space":{"lg":"3rem"}}'), 'saveKey' => $key]);
        $this->assertSame(['version' => 2, 'replayed' => false], $first);
        $this->assertSame(['version' => 2, 'replayed' => true], $this->tokens()->save($this->f['ctx'], ['baseVersion' => 1, 'tokens' => Json::decode('{"space":{"lg":"3rem"}}'), 'saveKey' => $key]));
        $this->assertThrows(fn () => $this->tokens()->save($this->f['ctx'], ['baseVersion' => 1, 'tokens' => Json::decode('{"space":{"lg":"4rem"}}'), 'saveKey' => $key]), ConflictException::class);
        $this->assertThrows(fn () => $this->tokens()->save($this->f['ctx'], ['baseVersion' => 1, 'tokens' => Json::decode('{}'), 'saveKey' => self::key()]), StaleVersionException::class);

        $editor = $this->addMember($this->f['siteId'], 'editor');
        $this->saveTokens(['space' => ['lg' => '2.5rem']], $editor);
        $this->assertThrows(fn () => $this->publishTokens($editor), ForbiddenException::class);
        $publishKey = self::key();
        $state = $this->tokens()->state($this->f['ctx']);
        $first = $this->tokens()->publish($this->f['ctx'], ['expectedVersion' => $state['version'], 'idempotencyKey' => $publishKey]);
        $retry = $this->tokens()->publish($this->f['ctx'], ['expectedVersion' => $state['version'], 'idempotencyKey' => $publishKey]);
        $this->assertSame([1, false, 1, true], [$first['version'], $first['replayed'], $retry['version'], $retry['replayed']]);
        $this->assertSame(1, DB::table('site_token_versions')->where('site_id', $this->f['siteId'])->count());
        $this->assertSame(['space' => ['lg' => '2.5rem']], json_decode(DB::table('site_token_versions')->value('tokens'), true));
    }

    public function test_publications_from_before_tokens_existed_are_never_refreshed(): void
    {
        // A live page rendered with the previous renderer records no dependencies: tokens do not touch it.
        $published = $this->publishPage();
        $owner = MigrationConfig::connect('test');
        try {
            DB::connection($owner)->table('publication_dependencies')->where('publication_id', $published['publicationId'])->delete();
        } finally {
            MigrationConfig::disconnect();
        }
        $before = $this->live();
        $this->saveTokens(['color' => ['primary' => '#0f766e']]);
        $this->assertSame(0, $this->publishTokens()['refreshes']['queued']);
        $this->assertSame($before, $this->live());
    }

    public function test_a_published_page_reproduces_with_its_recorded_tokens_after_newer_tokens_are_published(): void
    {
        $this->saveTokens(['color' => ['primary' => '#0f766e']]);
        $this->publishTokens();
        $first = $this->publishPage();
        $html = $this->live();
        $this->saveTokens(['color' => ['primary' => '#b91c1c']]);
        $this->publishTokens(); // refreshes the live page to a new publication
        $this->assertStringContainsString('#b91c1c', $this->live());

        $result = $this->pages()->reproducePublication($this->f['siteId'], $first['publicationId']);
        $this->assertSame('reproduced', $result['status'], (string) $result['reason']);
        $this->assertTrue($result['matches']);
        $this->assertSame($html, $result['html']);
        $refreshed = DB::table('live_pages')->where('page_id', $this->f['pageId'])->value('publication_id');
        $this->assertTrue($this->pages()->reproducePublication($this->f['siteId'], $refreshed)['matches'], 'the refresh is reproducible too');
    }

    // ── Reusable components ────────────────────────────────────────────────

    public function test_instances_render_the_published_component_and_drafts_stay_private_until_published(): void
    {
        $id = $this->publishedComponent();
        $this->addInstance($id, style: ['root' => ['base' => ['marginTop' => '@space.xl']]]);
        $this->publishPage();
        $this->assertStringContainsString('Free delivery on every order', $this->live());
        $this->assertMatchesRegularExpression('#<div class="ak-instance ak-s[0-9a-f]{10}"><p class="ak-text2 ak-flow">Free delivery on every order</p></div>#', $this->live());
        $this->assertSame(1, DB::table('publication_dependencies')->where('kind', 'component')->where('resource_id', $id)->value('version'));

        // The component's draft changes nothing anywhere until it is published.
        $this->editComponentText($id, 'Free returns too');
        $this->assertStringNotContainsString('Free returns', $this->live());
        $this->assertStringNotContainsString('Free returns', $this->pages()->renderPreview($this->f['ctx'], $this->f['pageId']));
        $state = $this->components()->list($this->f['ctx'])[0];
        $this->assertSame([1, true, 1], [$state['published'], $state['changed'], $state['livePages']]);

        // Publishing it re-renders the live page (from its live revision) at the next epoch.
        $this->save([['op' => 'updateProps', 'nodeId' => $this->f['heroId'], 'set' => ['heading' => 'Unpublished heading']]]);
        $published = $this->components()->publish($this->f['ctx'], $id, ['expectedVersion' => 2, 'idempotencyKey' => self::key()]);
        $this->assertSame(['queued' => 1, 'done' => 1, 'skipped' => 0, 'failed' => 0], $published['refreshes']);
        $live = $this->live();
        $this->assertStringContainsString('Free returns too', $live);
        $this->assertStringNotContainsString('Unpublished heading', $live);
        $this->assertSame(2, (int) DB::table('publication_dependencies')->where('kind', 'component')->where('publication_id', DB::table('live_pages')->value('publication_id'))->value('version'));
    }

    public function test_a_page_cannot_publish_an_unpublished_or_foreign_component(): void
    {
        $draftOnly = $this->components()->create($this->f['ctx'], ['name' => 'Not yet'])['id'];
        $this->addInstance($draftOnly);
        try {
            $this->publishPage();
            $this->fail('Expected publishing to be blocked');
        } catch (ValidationException $error) {
            $this->assertContains('This reusable component has not been published', array_column($error->issues, 'message'));
        }

        $other = $this->siteFixture();
        $foreign = app(ComponentService::class)->create($other['ctx'], ['name' => 'Theirs'])['id'];
        try {
            $this->addInstance($foreign, 'inst0002');
            $this->fail('Expected the foreign component to be refused');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('does not exist', $error->getMessage());
        }
        // Another site's component is not visible either.
        $this->assertThrows(fn () => $this->components()->editorInit($this->f['ctx'], $foreign, new MediaSigner), NotFoundException::class);
    }

    public function test_components_are_validated_like_pages_and_cannot_nest_instances(): void
    {
        $this->assertThrows(fn () => $this->components()->create($this->f['ctx'], ['name' => '']), ValidationException::class);
        $id = $this->components()->create($this->f['ctx'], ['name' => 'Card'])['id'];
        $root = Json::decode(DB::table('reusable_components')->where('id', $id)->value('draft'))['root'];
        $save = fn (array $ops, int $base) => $this->components()->save($this->f['ctx'], $id, ['baseVersion' => $base, 'saveKey' => self::key(), 'operations' => Json::decode(Json::encode($ops))]);
        $this->assertThrows(fn () => $save([['op' => 'insertNode', 'parentId' => $root, 'index' => 0, 'nodes' => [['id' => 'inst0009', 'type' => 'instance', 'version' => 2, 'props' => ['componentId' => $id]]]]], 1), ValidationException::class);
        $this->assertThrows(fn () => $save([['op' => 'insertNode', 'parentId' => $root, 'index' => 0, 'nodes' => [['id' => 'text0009', 'type' => 'text', 'version' => 3, 'props' => ['text' => 'x', 'style' => ['root' => ['base' => ['height' => 'calc(1px)']]]]]]]], 1), ValidationException::class);
        $this->assertSame(2, $save([['op' => 'insertNode', 'parentId' => $root, 'index' => 0, 'nodes' => [['id' => 'text0009', 'type' => 'text', 'version' => 3, 'props' => ['text' => '']]]]], 1)['version']);
        $this->assertThrows(fn () => $save([['op' => 'removeNode', 'nodeId' => 'text0009']], 1), StaleVersionException::class);
        // Publishing applies the same publish checks as pages.
        $this->assertThrows(fn () => $this->components()->publish($this->f['ctx'], $id, ['expectedVersion' => 2, 'idempotencyKey' => self::key()]), ValidationException::class, 'Fix these problems');
        $this->assertSame(0, DB::table('reusable_component_versions')->count());
    }

    public function test_reproduction_uses_the_recorded_component_version(): void
    {
        $id = $this->publishedComponent('Version one');
        $this->addInstance($id);
        $first = $this->publishPage();
        $html = $this->live();
        $this->editComponentText($id, 'Version two');
        $this->components()->publish($this->f['ctx'], $id, ['expectedVersion' => 2, 'idempotencyKey' => self::key()]);
        $this->assertStringContainsString('Version two', $this->live());

        $result = $this->pages()->reproducePublication($this->f['siteId'], $first['publicationId']);
        $this->assertTrue($result['matches'], (string) $result['reason']);
        $this->assertStringContainsString('Version one', $result['html']);
        $this->assertSame($html, $result['html']);
    }

    public function test_images_inside_a_component_become_public_with_the_page(): void
    {
        $asset = app(MediaService::class)->upload($this->f['ctx'], self::png(), 'logo.png');
        $id = $this->components()->create($this->f['ctx'], ['name' => 'Logo', 'nodes' => Json::decode(Json::encode([
            ['id' => 'logo0001', 'type' => 'image', 'version' => 4, 'props' => ['image' => ['assetId' => $asset['id'], 'alt' => 'Logo']]],
        ]))])['id'];
        $this->components()->publish($this->f['ctx'], $id, ['expectedVersion' => 1, 'idempotencyKey' => self::key()]);
        $key = substr($asset['url'], 7);
        $this->assertNull(app(MediaService::class)->resolveAccess($key, 'design.test', null, null, new MediaSigner), 'private while no live page uses it');
        $this->addInstance($id);
        $this->publishPage();
        $this->assertSame('public', app(MediaService::class)->resolveAccess($key, 'design.test', null, null, new MediaSigner)['access'] ?? null);
    }

    // ── Refresh queue ──────────────────────────────────────────────────────

    public function test_a_failed_refresh_is_recorded_and_retried_and_never_publishes_twice(): void
    {
        $id = $this->publishedComponent();
        $this->addInstance($id);
        $this->publishPage();
        $this->editComponentText($id, 'Second version');

        // The first re-render attempts fail (e.g. the database went away): recorded, not lost.
        $failing = new class(app(PageService::class)) extends PageService
        {
            public int $failures = PageRefreshes::MAX_ATTEMPTS;

            public function __construct(private readonly PageService $inner) {}

            public function prepareRerender(string $siteId, string $pageId): ?array
            {
                if ($this->failures-- > 0) {
                    throw new RuntimeException('connection lost');
                }

                return $this->inner->prepareRerender($siteId, $pageId);
            }

            public function commitRerender(array $prepared): array
            {
                return $this->inner->commitRerender($prepared);
            }
        };
        $this->app->instance(PageService::class, $failing);
        $this->app->forgetInstance(PageRefreshes::class);
        $this->app->forgetInstance(ComponentService::class);
        $result = app(ComponentService::class)->publish($this->f['ctx'], $id, ['expectedVersion' => 2, 'idempotencyKey' => self::key()]);
        $this->assertSame(1, $result['refreshes']['failed']);
        $refresh = DB::table('page_refreshes')->first();
        $this->assertSame(['failed', PageRefreshes::MAX_ATTEMPTS], [$refresh->status, (int) $refresh->attempts]);
        $this->assertStringContainsString('could not be re-rendered', $refresh->last_error);
        $this->assertStringNotContainsString('Second version', $this->live(), 'the live page keeps the version it had');
        $status = app(PageRefreshes::class)->status($this->f['ctx']);
        $this->assertSame([0, 1], [$status['pending'], $status['failed']]);

        // Retry (button or arkon:refresh-pages --retry-failed) completes it once.
        $publications = DB::table('publications')->count();
        $this->assertSame(['done' => 1, 'skipped' => 0, 'failed' => 0], app(PageRefreshes::class)->retry($this->f['ctx']));
        $this->assertStringContainsString('Second version', $this->live());
        $this->assertSame($publications + 1, DB::table('publications')->count());
        $this->assertSame(['done' => 0, 'skipped' => 0, 'failed' => 0], app(PageRefreshes::class)->retry($this->f['ctx']));
        $this->assertSame($publications + 1, DB::table('publications')->count());
    }

    public function test_a_refresh_never_replaces_a_page_published_after_it_was_prepared(): void
    {
        $id = $this->publishedComponent();
        $this->addInstance($id);
        $this->publishPage();
        $this->editComponentText($id, 'Component v2');
        $runner = app(PageRefreshes::class);
        $epochBefore = (int) DB::table('sites')->where('id', $this->f['siteId'])->value('publish_epoch');
        $this->components()->publish($this->f['ctx'], $id, ['expectedVersion' => 2, 'idempotencyKey' => self::key()]);
        // The refresh ran; now prepare a stale one, publish the page meanwhile, then commit the stale one.
        $stale = $this->pages()->prepareRerender($this->f['siteId'], $this->f['pageId']);
        $this->save([['op' => 'updateProps', 'nodeId' => $this->f['heroId'], 'set' => ['heading' => 'Newer page']]]);
        $this->publishPage();
        $this->assertFalse($this->pages()->commitRerender($stale)['applied']);
        $this->assertStringContainsString('Newer page', $this->live());
        $this->assertGreaterThan($epochBefore, (int) DB::table('live_pages')->value('epoch'));
        $this->assertSame(['done' => 0, 'skipped' => 0, 'failed' => 0], $runner->run($this->f['siteId']));
    }

    public function test_pages_that_are_no_longer_live_are_skipped(): void
    {
        $id = $this->publishedComponent();
        $this->addInstance($id);
        $this->publishPage();
        app(PageManagement::class)->unpublish($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedPublicationId' => DB::table('live_pages')->where('page_id', $this->f['pageId'])->value('publication_id')]);
        $this->editComponentText($id, 'Nobody sees this');
        $result = $this->components()->publish($this->f['ctx'], $id, ['expectedVersion' => 2, 'idempotencyKey' => self::key()]);
        $this->assertSame(0, $result['refreshes']['queued'], 'an unpublished page has no live dependency');
        $this->assertNull($this->pages()->livePage($this->f['siteId'], '/'));
    }

    public function test_operations_on_instances_are_ordinary_page_edits(): void
    {
        $id = $this->publishedComponent();
        $this->addInstance($id);
        $doc = Json::decode(DB::table('page_drafts')->where('page_id', $this->f['pageId'])->value('document'));
        $this->assertSame('instance', $doc['nodes']['inst0001']['type']);
        // Detaching (as the editor does it): remove the instance, insert a copy of the published blocks.
        $this->save([
            ['op' => 'removeNode', 'nodeId' => 'inst0001'],
            ['op' => 'insertNode', 'parentId' => $doc['root'], 'index' => 1, 'nodes' => [['id' => Operations::newNodeId(), 'type' => 'text', 'version' => 3, 'props' => ['text' => 'Free delivery on every order']]]],
        ]);
        $this->publishPage();
        $this->assertStringNotContainsString('<div class="ak-instance', $this->live());
        $live = DB::table('live_pages')->where('page_id', $this->f['pageId'])->value('publication_id');
        $this->assertSame(0, DB::table('publication_dependencies')->where('publication_id', $live)->where('kind', 'component')->count());
    }
}
