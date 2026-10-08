<?php

namespace Tests\Feature;

use App\Arkon\Ai\AiConnections;
use App\Arkon\Ai\AiHelper;
use App\Arkon\Ai\Mcp\McpServer;
use App\Arkon\Ai\ProposalPrompt;
use App\Arkon\Ai\ProposalService;
use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Errors\ArkonException;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Pages\PageService;
use App\Arkon\Schema\Operations;
use App\Arkon\Support\Json;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;
use Tests\Support\FakeClaudeRunner;

/**
 * The builder capabilities through both AI paths (the editor's helper and MCP), with scripted
 * replies (no model quota): duplicating an existing block, five equal columns, and a fade-up
 * entrance on a section further down the page. Every proposal is compiled to the editor's own
 * operations, validated like a save, reviewed, applied explicitly as one AI revision (undoable),
 * stale-checked, and published only by the user.
 */
class AiBuilderTest extends DatabaseTestCase
{
    private array $f;

    private object $helper;

    private string $button;

    private string $section;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
        $this->addDomain($this->f['siteId'], 'ai-builder.test');
        $id = app(AiConnections::class)->create($this->f['siteId'], $this->f['ctx']->userId, 'helper', 'test helper')['id'];
        app(AiConnections::class)->heartbeat($id, FakeClaudeRunner::ready());
        $this->helper = DB::table('ai_connections')->where('id', $id)->first();
        // The hero gets a button; a section with a heading follows further down the page.
        $this->button = Operations::newNodeId();
        $this->section = Operations::newNodeId();
        $this->save([
            ['op' => 'insertNode', 'parentId' => $this->f['heroId'], 'index' => 0, 'nodes' => [$this->node($this->button, 'button', ['label' => 'Book now', 'href' => '/book'])]],
            ['op' => 'insertNode', 'parentId' => $this->root(), 'index' => 1, 'nodes' => [
                $this->node($this->section, 'section', [], ['sectText1']),
                $this->node('sectText1', 'text', ['text' => 'Our work', 'element' => 'h2']),
            ]],
        ]);
    }

    private function node(string $id, string $type, array $props, ?array $children = null): array
    {
        $definition = app(ComponentRegistry::class)->current($type);

        return ['id' => $id, 'type' => $type, 'version' => $definition->version, 'props' => [...$definition->defaultProps, ...$props], ...($children === null ? [] : ['children' => $children])];
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

    private function root(): string
    {
        return $this->draft()['root'];
    }

    private function save(array $ops): array
    {
        return $this->pages()->saveDraft($this->f['ctx'], ['pageId' => $this->f['pageId'], 'baseVersion' => $this->version(), 'saveKey' => self::key(), 'operations' => Json::decode(Json::encode($ops))]);
    }

    private function propose(array $reply, string $prompt, ?FakeClaudeRunner $runner = null): array
    {
        $request = $this->ai()->request($this->f['ctx'], $this->f['pageId'], ['prompt' => $prompt, 'baseVersion' => $this->version(), 'requestKey' => self::key()]);
        (new AiHelper($this->ai(), app(AiConnections::class), $runner ?? new FakeClaudeRunner([$reply])))->tick($this->helper);

        return $this->ai()->status($this->f['ctx'], $this->f['pageId'], $request['id'], new MediaSigner);
    }

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

    private static function reply(array $changes, string $summary = 'Done.'): array
    {
        return ['summary' => $summary, 'notes' => [], 'tokenChanges' => [], 'changes' => $changes];
    }

    private static function set(string $property, string $value, string $screen = 'base'): array
    {
        return ['slot' => 'root', 'screen' => $screen, 'property' => $property, 'value' => $value];
    }

    /** "Duplicate the Book now button and call the copy Call us" (the copy is named, then updated). */
    private function duplicateReply(): array
    {
        return self::reply([
            ['action' => 'duplicate', 'id' => $this->button, 'ref' => 'call'],
            ['action' => 'update', 'change' => ['id' => 'new:call', 'type' => 'button', 'props' => [
                'label' => 'Call us', 'href' => null, 'variant' => 'secondary', 'size' => null, 'newTab' => null, 'style' => null,
            ]]],
        ], 'A second button, “Call us”, follows “Book now”.');
    }

    /** "Add five equal columns to the Our work section". */
    private function columnsReply(): array
    {
        $changes = [['action' => 'add', 'parent' => $this->section, 'index' => null, 'ref' => 'grid', 'block' => ['type' => 'columns', 'props' => ['style' => []]]]];
        for ($i = 1; $i <= 5; $i++) {
            $changes[] = ['action' => 'add', 'parent' => 'new:grid', 'index' => null, 'ref' => "col{$i}", 'block' => ['type' => 'column', 'props' => ['style' => []]]];
            $changes[] = ['action' => 'add', 'parent' => "new:col{$i}", 'index' => null, 'ref' => null, 'block' => ['type' => 'text', 'props' => ['text' => "Project {$i}", 'element' => 'p', 'style' => []]]];
        }

        return self::reply($changes, 'Five equal columns, one project each; they stack on phones.');
    }

    /** "Make the Our work section fade up when it scrolls into view". */
    private function animationReply(string $duration = '700ms'): array
    {
        return self::reply([['action' => 'update', 'change' => ['id' => $this->section, 'type' => 'section', 'props' => [
            'element' => null, 'contentWidth' => null,
            'style' => [self::set('animation', 'fade-up'), self::set('animationTrigger', 'view'), self::set('animationDuration', $duration)],
        ]]]], 'The Our work section fades up the first time it scrolls into view.');
    }

    public function test_the_catalogue_offers_duplicate_columns_and_entrance_animations(): void
    {
        $instructions = app(ProposalPrompt::class)->instructions();
        $this->assertStringContainsString('duplicate {id, ref}', $instructions);
        $this->assertStringContainsString('animationTrigger (Animation trigger): one of load, view; base screen only', $instructions);
        $this->assertStringContainsString('animationDuration (Animation duration): a duration in milliseconds such as 600ms (150ms to 4000ms); base screen only', $instructions);
        $this->assertStringContainsString('section (Section, version 2)', $instructions);
        $this->assertStringContainsString('root (Section) accepts', $instructions);
        // No blanket "there are no animations" any more; other effects are still unavailable.
        $this->assertStringNotContainsString('carousels, animations or scripts', $instructions);
        $this->assertStringContainsString('animations other than the entrance animations above', $instructions);
        $schema = json_encode(app(ProposalPrompt::class)->schema(['assets' => [], 'components' => []]));
        $this->assertStringContainsString('"const":"duplicate"', $schema);
        $this->assertStringContainsString('"animationEasing"', $schema);
    }

    public function test_duplicating_a_button_is_reviewed_applied_as_one_revision_undone_and_published_explicitly(): void
    {
        $before = $this->draft();
        $view = $this->propose($this->duplicateReply(), 'Duplicate the Book now button and call the copy Call us');
        $this->assertSame('proposed', $view['status'], json_encode($view['error'] ?? null));
        $this->assertSame(['Duplicate Button “Book now” inside Hero “Original heading”', 'Change new Button “Book now”: label, variant'], $view['proposal']['changes']);
        [$insert, $update] = $view['proposal']['operations'];
        $this->assertSame(['insertNode', $this->f['heroId'], 1], [$insert['op'], $insert['parentId'], $insert['index']]);
        $copyId = $insert['nodes'][0]['id'];
        $this->assertNotSame($this->button, $copyId);
        $this->assertEquals(['label' => 'Book now', 'href' => '/book'], array_intersect_key($insert['nodes'][0]['props'], ['label' => 1, 'href' => 1]));
        $this->assertSame($copyId, $update['nodeId']);
        $this->assertEquals($before, $this->draft(), 'a proposal changes nothing until applied');

        $saved = $this->apply($view);
        $doc = $this->draft();
        $this->assertSame([$this->button, $copyId], $doc['nodes'][$this->f['heroId']]['children']);
        $this->assertSame(['Call us', '/book', 'secondary'], [$doc['nodes'][$copyId]['props']['label'], $doc['nodes'][$copyId]['props']['href'], $doc['nodes'][$copyId]['props']['variant']]);
        $this->assertSame('Book now', $doc['nodes'][$this->button]['props']['label'], 'the original is unchanged');
        $this->assertSame('ai', DB::table('page_revisions')->where('id', $saved['revision']['id'])->value('source'));
        $this->assertNull($this->pages()->livePage($this->f['siteId'], '/'), 'applying never publishes');

        // Undo (the editor saves the inverse operations as one edit), then redo by applying again.
        $inverse = Operations::apply($before, Json::decode(Json::encode($view['proposal']['operations'])))['inverse'];
        $this->save($inverse);
        $this->assertEquals($before['nodes'], $this->draft()['nodes']);
        $this->save($view['proposal']['operations']);
        $html = $this->publish();
        $this->assertStringContainsString('>Book now</a>', $html);
        $this->assertStringContainsString('>Call us</a>', $html);
    }

    public function test_a_proposal_made_before_a_manual_edit_is_refused_when_applied(): void
    {
        $view = $this->propose($this->duplicateReply(), 'Duplicate the Book now button');
        $this->save([['op' => 'updateProps', 'nodeId' => $this->button, 'set' => ['label' => 'Book a visit']]]);
        $before = $this->draft();
        try {
            $this->apply($view);
            $this->fail('a stale proposal must not apply');
        } catch (ArkonException $error) {
            $this->assertContains($error->code(), ['STALE_VERSION', 'STALE_PROPOSAL']);
        }
        $this->assertEquals($before, $this->draft());
    }

    public function test_five_equal_columns_are_real_column_blocks_that_stack_on_phones(): void
    {
        $view = $this->propose($this->columnsReply(), 'Add five equal columns to the Our work section, one project each');
        $this->assertSame('proposed', $view['status'], json_encode($view['error'] ?? null));
        $this->apply($view);
        $doc = $this->draft();
        $section = $doc['nodes'][$this->section];
        $columns = $doc['nodes'][end($section['children'])];
        $this->assertSame('columns', $columns['type']);
        $this->assertCount(5, $columns['children']);
        foreach ($columns['children'] as $i => $column) {
            $this->assertSame('column', $doc['nodes'][$column]['type']);
            $this->assertSame('Project '.($i + 1), $doc['nodes'][$doc['nodes'][$column]['children'][0]]['props']['text']);
        }
        // Equal widths need no setting; phones stack (the component default).
        $this->assertEquals(['root' => ['mobile' => ['columns' => '1']]], Json::toArray($columns['props']['style']));
        $html = $this->publish();
        $this->assertMatchesRegularExpression('#<div class="ak-cols ak-flow ak-cols--n5 (ak-s[0-9a-f]{10})">#', $html);
        $this->assertStringContainsString('@media (max-width:599px){', $html);
    }

    public function test_a_fade_up_entrance_on_a_section_further_down_the_page(): void
    {
        $view = $this->propose($this->animationReply(), 'Make the Our work section fade up when it scrolls into view');
        $this->assertSame('proposed', $view['status'], json_encode($view['error'] ?? null));
        $this->assertSame(['root' => ['base' => ['animation' => 'fade-up', 'animationTrigger' => 'view', 'animationDuration' => '700ms']]], Json::toArray($view['proposal']['operations'][0]['set']['style']));
        // The preview canvas renders it the editor way: final state, replayable.
        $this->assertStringContainsString('.ak-anim:not(.ak-replay){animation-name:none}', $view['proposal']['canvas']['css']);
        $this->apply($view);
        $html = $this->publish();
        $this->assertMatchesRegularExpression('#<section class="ak-section ak-anim ak-reveal (ak-m[0-9a-f]{10})">#', $html);
        $this->assertStringContainsString('animation-name:ak-a-up;animation-duration:700ms', $html);
        $this->assertStringContainsString('<script src="/_arkon/motion-3.js" integrity="sha384-', $html);

        // Served with a policy allowing exactly that script, and reproducible byte for byte.
        $response = $this->get('http://ai-builder.test/');
        $response->assertOk();
        $this->assertStringContainsString('script-src http://ai-builder.test/_arkon/motion-3.js;', $response->headers->get('Content-Security-Policy'));
        $live = $this->pages()->livePage($this->f['siteId'], '/');
        $this->assertSame('motion-3', json_decode(DB::table('publications')->where('id', $live->publication_id)->value('render_inputs'), true)['motion']);
        $this->assertTrue($this->pages()->reproducePublication($this->f['siteId'], $live->publication_id)['matches']);
    }

    public function test_out_of_range_animation_settings_are_refused_with_the_rule(): void
    {
        $bad = $this->animationReply('5000ms');
        $view = $this->propose($bad, 'Fade it up slowly', new FakeClaudeRunner([$bad, $bad]));
        $this->assertSame('failed', $view['status']);
        $this->assertStringContainsString('Animation duration: use 150 to 4000ms', json_encode($view['error'], JSON_UNESCAPED_UNICODE));
    }

    public function test_the_mcp_path_duplicates_adds_columns_and_animates_with_the_same_rules(): void
    {
        ['token' => $token] = app(AiConnections::class)->create($this->f['siteId'], $this->f['ctx']->userId, 'mcp', 'VS Code');
        $call = function (string $tool, array $arguments) use ($token): array {
            $result = app()->make(McpServer::class, ['token' => $token])->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]])['result'];

            return ['error' => $result['isError'], 'data' => json_decode($result['content'][0]['text'], true)];
        };
        $format = json_encode($call('arkon_get_proposal_format', ['pageId' => $this->f['pageId']])['data']);
        $this->assertStringContainsString('duplicate', $format);
        $this->assertStringContainsString('animationTrigger', $format);

        $changes = [...$this->duplicateReply()['changes'], ...$this->columnsReply()['changes'], ...$this->animationReply()['changes']];
        $submitted = $call('arkon_submit_proposal', [
            'pageId' => $this->f['pageId'], 'baseVersion' => $this->version(), 'request' => 'All three', 'requestKey' => 'vscode-builder-0001', 'proposal' => self::reply($changes),
        ]);
        $this->assertFalse($submitted['error'], json_encode($submitted['data']));
        $view = $this->ai()->status($this->f['ctx'], $this->f['pageId'], $submitted['data']['proposalId'], new MediaSigner);
        $this->apply($view);
        $html = $this->publish();
        $this->assertStringContainsString('>Call us</a>', $html);
        $this->assertStringContainsString('ak-cols--n5', $html);
        $this->assertStringContainsString('ak-anim ak-reveal', $html);

        // Invalid animation settings are a tool error the model can act on; nothing is recorded.
        $refused = $call('arkon_submit_proposal', [
            'pageId' => $this->f['pageId'], 'baseVersion' => $this->version(), 'request' => 'Slower', 'requestKey' => 'vscode-builder-0002', 'proposal' => $this->animationReply('9000ms'),
        ]);
        $this->assertTrue($refused['error']);
        $this->assertStringContainsString('Animation duration: use 150 to 4000ms', json_encode($refused['data'], JSON_UNESCAPED_UNICODE));
    }
}
