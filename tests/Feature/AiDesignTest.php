<?php

namespace Tests\Feature;

use App\Arkon\Ai\AiConnections;
use App\Arkon\Ai\AiException;
use App\Arkon\Ai\AiHelper;
use App\Arkon\Ai\Mcp\McpServer;
use App\Arkon\Ai\ProposalService;
use App\Arkon\Design\ComponentService;
use App\Arkon\Design\TokenService;
use App\Arkon\Media\MediaService;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Pages\PageService;
use App\Arkon\Support\Json;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;
use Tests\Support\FakeClaudeRunner;

/**
 * Both AI paths (the editor's helper running Claude Code, and Claude Code in VS Code over
 * MCP) with the design catalogue: layouts, responsive design settings, edits that keep
 * other settings, tokens and reusable components. Replies are scripted (no model quota);
 * every proposal is validated, previewed, stale-checked and applied explicitly, and
 * site-wide token changes are separate from page changes.
 */
class AiDesignTest extends DatabaseTestCase
{
    /** The browser acceptance request. */
    public const ACCEPTANCE = 'Keep the hero text on the left. Put its image on the right, make the image 500px tall with cover cropping, and stack the image below the text on mobile.';

    private array $f;

    private object $helper;

    private array $asset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
        $this->addDomain($this->f['siteId'], 'ai-design.test');
        $id = app(AiConnections::class)->create($this->f['siteId'], $this->f['ctx']->userId, 'helper', 'test helper')['id'];
        app(AiConnections::class)->heartbeat($id, FakeClaudeRunner::ready());
        $this->helper = DB::table('ai_connections')->where('id', $id)->first();
        // The hero already has its image (images on the page are what the AI may use).
        $this->asset = app(MediaService::class)->upload($this->f['ctx'], self::png(), 'garden.png');
        $this->save([['op' => 'updateProps', 'nodeId' => $this->f['heroId'], 'set' => ['image' => ['assetId' => $this->asset['id'], 'alt' => 'A garden']]]]);
    }

    private function pages(): PageService
    {
        return app(PageService::class);
    }

    private function ai(): ProposalService
    {
        return app(ProposalService::class);
    }

    private function version(): int
    {
        return (int) DB::table('page_drafts')->where('page_id', $this->f['pageId'])->value('version');
    }

    private function draft(): array
    {
        return Json::decode(DB::table('page_drafts')->where('page_id', $this->f['pageId'])->value('document'));
    }

    private function save(array $ops): array
    {
        return $this->pages()->saveDraft($this->f['ctx'], ['pageId' => $this->f['pageId'], 'baseVersion' => $this->version(), 'saveKey' => self::key(), 'operations' => Json::decode(Json::encode($ops))]);
    }

    /** Ask through the editor's panel; the helper runs it with this scripted reply. */
    private function propose(array $reply, string $prompt = self::ACCEPTANCE, ?FakeClaudeRunner $runner = null): array
    {
        $request = $this->ai()->request($this->f['ctx'], $this->f['pageId'], ['prompt' => $prompt, 'baseVersion' => $this->version(), 'requestKey' => self::key()]);
        $runner ??= new FakeClaudeRunner([$reply]);
        (new AiHelper($this->ai(), app(AiConnections::class), $runner))->tick($this->helper);

        return $this->ai()->status($this->f['ctx'], $this->f['pageId'], $request['id'], new MediaSigner);
    }

    /** "Apply to draft": exactly the proposed operations, through JSON. */
    private function apply(array $view): array
    {
        return $this->pages()->saveDraft($this->f['ctx'], [
            'pageId' => $this->f['pageId'], 'baseVersion' => $view['baseVersion'], 'saveKey' => self::key(),
            'operations' => Json::decode(Json::encode($view['proposal']['operations'])), 'proposalId' => $view['id'],
        ]);
    }

    private function publish(): string
    {
        $this->pages()->publish($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => $this->version(), 'idempotencyKey' => self::key()]);

        return (string) $this->pages()->livePage($this->f['siteId'], '/')->html;
    }

    /** What Claude answers to the acceptance request (scripted). */
    private function acceptanceReply(): array
    {
        $set = fn (string $slot, string $screen, string $property, string $value) => compact('slot', 'screen', 'property', 'value');

        return [
            'summary' => 'The hero keeps its text on the left with the image on the right at 500px tall, cropped to fill; on phones the image stacks below the text.',
            'notes' => [],
            'tokenChanges' => [],
            'changes' => [['action' => 'update', 'change' => ['id' => $this->f['heroId'], 'type' => 'hero', 'props' => [
                'heading' => null, 'headingLevel' => null, 'text' => null, 'image' => null,
                'style' => [
                    $set('root', 'base', 'direction', 'row'),
                    $set('media', 'base', 'height', '500px'),
                    $set('media', 'base', 'objectFit', 'cover'),
                    $set('root', 'mobile', 'direction', 'column'),
                ],
            ]]]],
        ];
    }

    private static function heroClasses(string $html): array
    {
        preg_match('#<section class="ak-hero3 (ak-s[0-9a-f]{10})"#', $html, $root);
        preg_match('#<img class="ak-hero3__media (ak-s[0-9a-f]{10})"#', $html, $media);

        return [$root[1] ?? null, $media[1] ?? null];
    }

    public function test_the_acceptance_request_becomes_design_settings_that_save_reload_edit_and_publish(): void
    {
        $runner = new FakeClaudeRunner([$this->acceptanceReply()]);
        $view = $this->propose([], runner: $runner);

        // What Claude was given: the shared catalogue with design settings, no obsolete "no heights/backgrounds" rule.
        $sent = $runner->requests[0];
        $this->assertStringContainsString('- height (Height): a length in px', $sent->instructions);
        $this->assertStringContainsString('- objectFit (Fit): one of cover, contain, fill', $sent->instructions);
        $this->assertStringContainsString('media (Image) accepts width, minWidth, maxWidth, height', $sent->instructions);
        $this->assertStringContainsString('row puts the hero text left and its image right', $sent->instructions);
        $this->assertStringNotContainsString('heights, custom styles', $sent->instructions);
        $this->assertStringNotContainsString('background colours, heights', $sent->instructions);
        $this->assertLessThan(28000, strlen(json_encode($sent->schema)), 'the schema fits the Claude Code command line');

        // The proposal expresses exactly these settings, merged with the hero's defaults (tablet stacking).
        $this->assertSame('proposed', $view['status'], json_encode($view['error']));
        $op = $view['proposal']['operations'][0];
        $this->assertSame(['op' => 'updateProps', 'nodeId' => $this->f['heroId']], ['op' => $op['op'], 'nodeId' => $op['nodeId']]);
        $this->assertEquals([
            'root' => ['base' => ['direction' => 'row'], 'tablet' => ['direction' => 'column', 'align' => 'stretch'], 'mobile' => ['direction' => 'column']],
            'media' => ['base' => ['height' => '500px', 'objectFit' => 'cover']],
        ], $op['set']['style']);
        $this->assertSame(['Change Hero “Original heading”: style'], $view['proposal']['changes']);
        $this->assertStringContainsString('height:500px;object-fit:cover', $view['proposal']['canvas']['css']);
        $this->assertEquals($this->draft()['nodes'][$this->f['heroId']]['props']['image'], ['assetId' => $this->asset['id'], 'alt' => 'A garden'], 'nothing changed before applying');
        $this->assertArrayNotHasKey('media', (array) ($this->draft()['nodes'][$this->f['heroId']]['props']['style'] ?? []));

        // Applying is a normal save (one AI revision); it survives a reload.
        $this->apply($view);
        $reloaded = $this->pages()->editorState($this->f['ctx'], $this->f['pageId'])['draft']['document']['nodes'][$this->f['heroId']]['props']['style'];
        $this->assertSame('500px', $reloaded['media']['base']['height']);
        $this->assertSame('column', $reloaded['root']['mobile']['direction']);
        $this->assertSame('ai', DB::table('page_revisions')->orderByDesc('number')->value('source'));

        // Published output: image right of the text on wide screens, 500px with cover, stacked below the text on phones.
        $html = $this->publish();
        [$root, $media] = self::heroClasses($html);
        $this->assertMatchesRegularExpression('#<section class="ak-hero3 '.$root.'"><div class="ak-hero3__content"><h1[^>]*>Original heading</h1><p[^>]*>Original text</p></div><img class="ak-hero3__media '.$media.'"#', $html, 'text first, image second: reading order kept');
        $this->assertStringContainsString(".{$root}{flex-direction:row;--ak-basis:0%}", $html);
        $this->assertStringContainsString(".{$media}{height:500px;object-fit:cover}", $html);
        $this->assertMatchesRegularExpression('#@media \(max-width:599px\)\{[^@]*\.'.$root.'\{flex-direction:column;--ak-basis:auto\}#', $html);

        // Manually changeable afterwards: the mobile override is reset in the inspector (a normal edit).
        $style = $reloaded;
        unset($style['root']['mobile']);
        $style['media']['tablet'] = ['height' => '320px'];
        $this->save([['op' => 'updateProps', 'nodeId' => $this->f['heroId'], 'set' => ['style' => $style]]]);
        $html = $this->publish();
        [$root, $media] = self::heroClasses($html);
        $this->assertDoesNotMatchRegularExpression('#@media \(max-width:599px\)\{[^@]*\.'.$root.'\{#', $html);
        $this->assertMatchesRegularExpression('#@media \(max-width:899px\)\{[^@]*\.'.$media.'\{height:320px\}#', $html);
    }

    public function test_follow_up_edits_change_one_setting_and_keep_the_others(): void
    {
        $this->apply($this->propose($this->acceptanceReply()));
        $reply = ['summary' => 'Image is now 420px tall.', 'notes' => [], 'tokenChanges' => [], 'changes' => [['action' => 'update', 'change' => ['id' => $this->f['heroId'], 'type' => 'hero', 'props' => [
            'heading' => null, 'headingLevel' => null, 'text' => null, 'image' => null,
            'style' => [['slot' => 'media', 'screen' => 'base', 'property' => 'height', 'value' => '420px'], ['slot' => 'root', 'screen' => 'mobile', 'property' => 'direction', 'value' => null]],
        ]]]]];
        $view = $this->propose($reply, 'Make the image a bit shorter, and do not stack it on phones');
        $style = $view['proposal']['operations'][0]['set']['style'];
        $this->assertSame(['height' => '420px', 'objectFit' => 'cover'], $style['media']['base']);
        $this->assertSame(['direction' => 'row'], $style['root']['base']);
        $this->assertArrayNotHasKey('mobile', $style['root'], 'null removes a setting');
        $this->assertCount(1, $this->draft()['nodes'][$this->draft()['root']]['children'], 'edited, not duplicated');
    }

    public function test_invalid_design_settings_are_refused_with_the_rule_that_was_broken(): void
    {
        $bad = $this->acceptanceReply();
        $bad['changes'][0]['change']['props']['style'][] = ['slot' => 'media', 'screen' => 'base', 'property' => 'height', 'value' => '500px; position: fixed'];
        $bad['changes'][0]['change']['props']['style'][] = ['slot' => 'heading', 'screen' => 'mobile', 'property' => 'objectFit', 'value' => 'cover'];
        $runner = new FakeClaudeRunner([$bad, $bad]);
        $view = $this->propose([], runner: $runner);
        $this->assertSame(['failed', AiException::INVALID_OUTPUT], [$view['status'], $view['error']['code']]);
        // The repair run was told exactly what was wrong.
        $this->assertCount(2, $runner->requests);
        $this->assertStringContainsString('style.media.base.height', $runner->requests[1]->prompt);
        $this->assertStringContainsString('Fit cannot be set here', $runner->requests[1]->prompt);
    }

    public function test_nested_layouts_are_built_with_named_new_blocks(): void
    {
        $reply = ['summary' => 'A services section with two columns.', 'notes' => [], 'tokenChanges' => [], 'changes' => [
            ['action' => 'add', 'parent' => 'page', 'index' => null, 'ref' => 'services', 'block' => ['type' => 'section', 'props' => ['element' => 'section', 'contentWidth' => 'default', 'style' => [['slot' => 'root', 'screen' => 'base', 'property' => 'backgroundColor', 'value' => '@color.surface']]]]],
            ['action' => 'add', 'parent' => 'new:services', 'index' => null, 'ref' => null, 'block' => ['type' => 'text', 'props' => ['text' => 'What we do', 'element' => 'h2', 'style' => []]]],
            ['action' => 'add', 'parent' => 'new:services', 'index' => null, 'ref' => 'cols', 'block' => ['type' => 'columns', 'props' => ['style' => [['slot' => 'root', 'screen' => 'base', 'property' => 'columns', 'value' => '1fr 2fr']]]]],
            ['action' => 'add', 'parent' => 'new:cols', 'index' => null, 'ref' => 'first', 'block' => ['type' => 'column', 'props' => ['style' => []]]],
            ['action' => 'add', 'parent' => 'new:cols', 'index' => null, 'ref' => 'second', 'block' => ['type' => 'column', 'props' => ['style' => []]]],
            ['action' => 'add', 'parent' => 'new:first', 'index' => null, 'ref' => null, 'block' => ['type' => 'text', 'props' => ['text' => 'Design', 'element' => 'h3', 'style' => []]]],
            ['action' => 'add', 'parent' => 'new:second', 'index' => null, 'ref' => null, 'block' => ['type' => 'text', 'props' => ['text' => 'Planting and care', 'element' => 'p', 'style' => []]]],
        ]];
        $view = $this->propose($reply, 'Add a services section with a narrow and a wide column');
        $this->assertSame('proposed', $view['status'], json_encode($view['error']));
        $this->apply($view);
        $doc = $this->draft();
        $section = collect($doc['nodes'])->firstWhere('type', 'section');
        $this->assertCount(2, $section['children']);
        $columns = $doc['nodes'][$section['children'][1]];
        $this->assertSame(['columns', ['root' => ['base' => ['columns' => '1fr 2fr'], 'mobile' => ['columns' => '1']]]], [$columns['type'], $columns['props']['style']]);

        $unknown = $reply;
        $unknown['changes'][1]['parent'] = 'new:nothing';
        $this->assertSame('failed', $this->propose($unknown, 'Again', new FakeClaudeRunner([$unknown, $unknown]))['status']);
    }

    public function test_site_wide_token_changes_are_separate_explicit_and_only_reach_the_draft(): void
    {
        $this->publish();
        $live = (string) $this->pages()->livePage($this->f['siteId'], '/')->html;
        $reply = ['summary' => 'Switch the brand colour to teal.', 'notes' => [], 'tokenChanges' => [['token' => '@color.primary', 'value' => '#0f766e']], 'changes' => []];
        $view = $this->propose($reply, 'Make our brand colour teal across the site');
        $this->assertSame('proposed', $view['status']);
        $this->assertSame([], $view['proposal']['operations']);
        $this->assertSame([['token' => '@color.primary', 'value' => '#0f766e']], $view['proposal']['tokenChanges']);
        $this->assertEquals([], Json::toArray(app(TokenService::class)->state($this->f['ctx'])['draft']), 'nothing applied by proposing');

        // Applying them writes the token draft only: live pages change when tokens are published, separately.
        $applied = $this->ai()->applyTokenChanges($this->f['ctx'], $this->f['pageId'], $view['id'], app(TokenService::class));
        $this->assertSame($applied, $this->ai()->applyTokenChanges($this->f['ctx'], $this->f['pageId'], $view['id'], app(TokenService::class)), 'once per proposal');
        $state = app(TokenService::class)->state($this->f['ctx']);
        $this->assertEquals(['color' => ['primary' => '#0f766e']], Json::toArray($state['draft']));
        $this->assertNull($state['published']['version']);
        $this->assertSame($live, (string) $this->pages()->livePage($this->f['siteId'], '/')->html);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'tokens.ai.apply')->count());

        $invalid = ['summary' => 'x', 'notes' => [], 'tokenChanges' => [['token' => '@color.primary', 'value' => 'teal;}']], 'changes' => []];
        $failed = $this->propose($invalid, 'Teal again', new FakeClaudeRunner([$invalid, $invalid]));
        $this->assertSame(['failed', AiException::INVALID_OUTPUT], [$failed['status'], $failed['error']['code']]);
    }

    public function test_instances_use_only_published_components_listed_to_the_ai(): void
    {
        $components = app(ComponentService::class);
        $banner = $components->create($this->f['ctx'], ['name' => 'Delivery banner', 'nodes' => Json::decode('[{"id":"bant0001","type":"text","version":3,"props":{"text":"Free delivery"}}]')])['id'];
        $components->publish($this->f['ctx'], $banner, ['expectedVersion' => 1, 'idempotencyKey' => self::key()]);
        $draftOnly = $components->create($this->f['ctx'], ['name' => 'Unfinished'])['id'];

        $add = fn (string $id) => ['summary' => 'Added the banner.', 'notes' => [], 'tokenChanges' => [], 'changes' => [
            ['action' => 'add', 'parent' => 'page', 'index' => null, 'ref' => null, 'block' => ['type' => 'instance', 'props' => ['componentId' => $id, 'style' => []]]],
        ]];
        $runner = new FakeClaudeRunner([$add($banner)]);
        $view = $this->propose([], 'Add the delivery banner below the hero', $runner);
        $this->assertStringContainsString("- {$banner}: \"Delivery banner\" (blocks: text)", $runner->requests[0]->prompt);
        $this->assertStringNotContainsString($draftOnly, $runner->requests[0]->prompt);
        $this->assertSame([$banner], $runner->requests[0]->schema['$defs']['block_instance']['properties']['props']['properties']['componentId']['enum']);
        $this->assertSame('proposed', $view['status']);
        $this->assertStringContainsString('Free delivery', $view['proposal']['canvas']['body']);

        $refused = $this->propose($add($draftOnly), 'Add the unfinished one', new FakeClaudeRunner([$add($draftOnly), $add($draftOnly)]));
        $this->assertSame('failed', $refused['status']);
        $this->assertStringContainsString('not one of the published components', $refused['error']['message']);
    }

    public function test_the_mcp_path_uses_the_same_catalogue_and_validation(): void
    {
        ['token' => $token] = app(AiConnections::class)->create($this->f['siteId'], $this->f['ctx']->userId, 'mcp', 'VS Code');
        $call = function (string $tool, array $arguments) use ($token): array {
            $result = app()->make(McpServer::class, ['token' => $token])->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]])['result'];

            return ['error' => $result['isError'], 'data' => json_decode($result['content'][0]['text'], true)];
        };
        $page = $call('arkon_get_page', ['pageId' => $this->f['pageId']]);
        $this->assertFalse($page['error']);
        $this->assertSame('#4f46e5', $page['data']['designTokens']['color']['primary']);
        $format = json_encode($call('arkon_get_proposal_format', ['pageId' => $this->f['pageId']])['data']);
        $this->assertStringContainsString('objectFit (Fit)', $format);
        $this->assertStringContainsString('tokenChanges', $format);

        $submitted = $call('arkon_submit_proposal', [
            'pageId' => $this->f['pageId'], 'baseVersion' => $this->version(), 'request' => self::ACCEPTANCE, 'requestKey' => 'vscode-design-0001', 'proposal' => $this->acceptanceReply(),
        ]);
        $this->assertFalse($submitted['error'], json_encode($submitted['data']));
        $view = $this->ai()->status($this->f['ctx'], $this->f['pageId'], $submitted['data']['proposalId'], new MediaSigner);
        $this->assertSame('500px', $view['proposal']['operations'][0]['set']['style']['media']['base']['height']);
        $this->apply($view);
        [$root, $media] = self::heroClasses($this->publish());
        $this->assertNotNull($root);
        $this->assertNotNull($media);

        // A stale submission (the draft moved on) is refused.
        $stale = $call('arkon_submit_proposal', [
            'pageId' => $this->f['pageId'], 'baseVersion' => 1, 'request' => 'Again', 'requestKey' => 'vscode-design-0002', 'proposal' => $this->acceptanceReply(),
        ]);
        $this->assertTrue($stale['error']);
        $this->assertSame('STALE_VERSION', $stale['data']['code']);
    }
}
