<?php

namespace Tests\Feature;

use App\Arkon\Ai\AiConnections;
use App\Arkon\Ai\AiHelper;
use App\Arkon\Ai\Mcp\McpServer;
use App\Arkon\Ai\ProposalService;
use App\Arkon\Components\ColumnLayout;
use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Pages\PageService;
use App\Arkon\Renderer\PageRenderer;
use App\Arkon\Support\Json;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;
use Tests\Support\FakeClaudeRunner;

/**
 * The Columns layout contract: a list of fraction widths has one width per Column, on every
 * screen (counts such as "1" for stacking or "2" per row stay free; Group grids are not
 * affected). Saves, AI proposals (both paths compile the same way), existing drafts that break
 * it (recovery, nothing rewritten on load) and historical publications (reproduced as they were).
 */
class ColumnLayoutTest extends DatabaseTestCase
{
    private array $f;

    private object $helper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
        $id = app(AiConnections::class)->create($this->f['siteId'], $this->f['ctx']->userId, 'helper', 'test helper')['id'];
        app(AiConnections::class)->heartbeat($id, FakeClaudeRunner::ready());
        $this->helper = DB::table('ai_connections')->where('id', $id)->first();
    }

    private function pages(): PageService
    {
        return app(PageService::class);
    }

    private function version(): int
    {
        return (int) DB::table('page_drafts')->where('page_id', $this->f['pageId'])->value('version');
    }

    private function draft(): array
    {
        return Json::decode(DB::table('page_drafts')->where('page_id', $this->f['pageId'])->value('document'));
    }

    private function node(string $id, string $type, array $props = [], ?array $children = null): array
    {
        $definition = app(ComponentRegistry::class)->current($type);

        return ['id' => $id, 'type' => $type, 'version' => $definition->version, 'props' => [...$definition->defaultProps, ...$props], ...($children === null ? [] : ['children' => $children])];
    }

    private function save(array $ops): array
    {
        return $this->pages()->saveDraft($this->f['ctx'], ['pageId' => $this->f['pageId'], 'baseVersion' => $this->version(), 'saveKey' => self::key(), 'operations' => Json::decode(Json::encode($ops))]);
    }

    /** Columns "cols0001" with two columns (A, B) and the given root style; a second, three-column one "cols0002". */
    private function addColumns(array $root = ['base' => ['columns' => '1fr 2fr'], 'mobile' => ['columns' => '1']]): void
    {
        $this->save([
            ['op' => 'insertNode', 'parentId' => $this->draft()['root'], 'index' => 1, 'nodes' => [
                $this->node('cols0001', 'columns', ['style' => ['root' => $root]], ['colu000A', 'colu000B']),
                $this->node('colu000A', 'column', [], ['textA001']),
                $this->node('textA001', 'text', ['text' => 'A']),
                $this->node('colu000B', 'column', [], []),
            ]],
            ['op' => 'insertNode', 'parentId' => $this->draft()['root'], 'index' => 2, 'nodes' => [
                $this->node('cols0002', 'columns', ['style' => ['root' => ['base' => ['columns' => '2fr 1fr 1fr'], 'mobile' => ['columns' => '1']]]], ['colu000C', 'colu000D', 'colu000E']),
                $this->node('colu000C', 'column', [], []),
                $this->node('colu000D', 'column', [], []),
                $this->node('colu000E', 'column', [], []),
            ]],
        ]);
    }

    private function propose(array $changes, ?FakeClaudeRunner $runner = null): array
    {
        $reply = ['summary' => 'Columns.', 'notes' => [], 'tokenChanges' => [], 'changes' => $changes];
        $ai = app(ProposalService::class);
        $request = $ai->request($this->f['ctx'], $this->f['pageId'], ['prompt' => 'Change the columns', 'baseVersion' => $this->version(), 'requestKey' => self::key()]);
        (new AiHelper($ai, app(AiConnections::class), $runner ?? new FakeClaudeRunner([$reply])))->tick($this->helper);

        return $ai->status($this->f['ctx'], $this->f['pageId'], $request['id'], new MediaSigner);
    }

    private function apply(array $view): void
    {
        $this->pages()->saveDraft($this->f['ctx'], [
            'pageId' => $this->f['pageId'], 'baseVersion' => $view['baseVersion'], 'saveKey' => self::key(),
            'operations' => Json::decode(Json::encode($view['proposal']['operations'])), 'proposalId' => $view['id'],
        ]);
    }

    private function widths(string $id): array
    {
        return Json::toArray($this->draft()['nodes'][$id]['props']['style'] ?? []);
    }

    private static function addColumn(string $parent, ?string $ref = null): array
    {
        return ['action' => 'add', 'parent' => $parent, 'index' => null, 'ref' => $ref, 'block' => ['type' => 'column', 'props' => ['style' => []]]];
    }

    private static function setWidths(string $id, array $settings): array
    {
        return ['action' => 'update', 'change' => ['id' => $id, 'type' => 'columns', 'props' => ['style' => array_map(fn ($s) => ['slot' => 'root', 'screen' => $s[0], 'property' => 'columns', 'value' => $s[1]], $settings)]]];
    }

    public function test_saves_refuse_fraction_widths_that_do_not_match_the_columns(): void
    {
        $this->addColumns();
        foreach ([['base', '1fr 1fr 1fr 1fr 1fr'], ['tablet', '1fr 2fr 1fr'], ['mobile', '2fr 1fr 1fr']] as [$screen, $value]) {
            try {
                $this->save([['op' => 'updateProps', 'nodeId' => 'cols0001', 'set' => ['style' => ['root' => [$screen => ['columns' => $value]]]]]]);
                $this->fail("{$screen} {$value} must be refused");
            } catch (ValidationException $error) {
                $this->assertStringContainsString('one width per column', json_encode($error->issues, JSON_UNESCAPED_UNICODE));
            }
        }
        // Adding a third column without changing "1fr 2fr" leaves two widths for three columns: refused too.
        $this->assertThrows(fn () => $this->save([['op' => 'insertNode', 'parentId' => 'cols0001', 'index' => 2, 'nodes' => [$this->node('colu000F', 'column', [], [])]]]), ValidationException::class);
        // Counts stay free: stacking, wrapping, and side by side.
        $this->save([['op' => 'updateProps', 'nodeId' => 'cols0002', 'set' => ['style' => ['root' => ['base' => ['columns' => '1fr 1fr 2fr'], 'tablet' => ['columns' => '2'], 'mobile' => ['columns' => '1']]]]]]);
        $this->save([['op' => 'updateProps', 'nodeId' => 'cols0001', 'set' => ['style' => ['root' => ['base' => ['columns' => '2'], 'mobile' => ['columns' => '1']]]]]]);
        // Group grids are not Columns: any valid track list.
        $this->save([['op' => 'insertNode', 'parentId' => $this->draft()['root'], 'index' => 1, 'nodes' => [$this->node('grup0001', 'group', ['style' => ['root' => ['base' => ['display' => 'grid', 'columns' => '1fr 1fr 1fr 1fr 1fr']]]], [])]]]);
        $this->assertSame('1fr 1fr 1fr 1fr 1fr', $this->draft()['nodes']['grup0001']['props']['style']['root']['base']['columns']);
    }

    public function test_an_ai_column_added_without_widths_is_reconciled_in_the_reviewed_operations(): void
    {
        $this->addColumns();
        $view = $this->propose([self::addColumn('cols0001')]);
        $this->assertSame('proposed', $view['status'], json_encode($view['error'] ?? null));
        // The reviewed proposal already holds the width change: it is exactly what gets applied.
        $this->assertContains('Columns widths on all screens went back to equal: 2 widths no longer fit 3 columns', $view['proposal']['changes']);
        $last = end($view['proposal']['operations']);
        $this->assertSame(['updateProps', 'cols0001'], [$last['op'], $last['nodeId']]);
        $this->apply($view);
        $this->assertEquals(['root' => ['mobile' => ['columns' => '1']]], $this->widths('cols0001'), 'equal widths, phones still stack');
        $this->assertCount(3, $this->draft()['nodes']['cols0001']['children']);
    }

    public function test_explicit_final_widths_in_the_same_proposal_are_kept_and_wrong_ones_refused(): void
    {
        $this->addColumns();
        $view = $this->propose([self::addColumn('cols0001'), self::addColumn('cols0001'), self::setWidths('cols0001', [['base', '1fr 2fr 1fr 1fr'], ['tablet', '2']])]);
        $this->assertSame('proposed', $view['status'], json_encode($view['error'] ?? null));
        $this->apply($view);
        $this->assertEquals(['root' => ['base' => ['columns' => '1fr 2fr 1fr 1fr'], 'tablet' => ['columns' => '2'], 'mobile' => ['columns' => '1']]], $this->widths('cols0001'));

        // Widths set for an intermediate count, then another column: the final state is what counts.
        $bad = [self::setWidths('cols0002', [['base', '1fr 1fr 1fr']]), self::addColumn('cols0002'), self::setWidths('cols0002', [['tablet', '1fr 1fr']])];
        $refused = $this->propose($bad, new FakeClaudeRunner([['summary' => 'x', 'notes' => [], 'tokenChanges' => [], 'changes' => $bad], ['summary' => 'x', 'notes' => [], 'tokenChanges' => [], 'changes' => $bad]]));
        $this->assertSame('failed', $refused['status']);
        $this->assertStringContainsString('one width per column', json_encode($refused['error'], JSON_UNESCAPED_UNICODE));
    }

    private static function style(string $id, string $type, array $settings, array $extra = []): array
    {
        return ['action' => 'update', 'change' => ['id' => $id, 'type' => $type, 'props' => [...$extra, 'style' => array_map(fn ($s) => ['slot' => 'root', 'screen' => $s[0], 'property' => $s[1], 'value' => $s[2]], $settings)]]];
    }

    public function test_other_style_changes_on_a_columns_block_never_stop_its_widths_being_reconciled(): void
    {
        $this->addColumns();
        // Adding a column and changing the gap: the gap is kept, the widths are reconciled (the widths were never requested).
        $view = $this->propose([self::addColumn('cols0001'), self::style('cols0001', 'columns', [['base', 'gap', '20px']])]);
        $this->assertSame('proposed', $view['status'], json_encode($view['error'] ?? null));
        $this->assertContains('Columns widths on all screens went back to equal: 2 widths no longer fit 3 columns', $view['proposal']['changes']);
        $this->apply($view);
        $this->assertEquals(['root' => ['base' => ['gap' => '20px'], 'mobile' => ['columns' => '1']]], $this->widths('cols0001'));

        // Removing a column with padding; moving one in with a background; adding one with an animation.
        $this->save([['op' => 'updateProps', 'nodeId' => 'cols0002', 'set' => ['style' => ['root' => ['base' => ['columns' => '2fr 1fr 1fr'], 'mobile' => ['columns' => '1']]]]]]);
        $view = $this->propose([['action' => 'remove', 'id' => 'colu000C'], self::style('cols0002', 'columns', [['base', 'paddingTop', '@space.lg']])]);
        $this->assertSame('proposed', $view['status'], json_encode($view['error'] ?? null));
        $this->apply($view);
        $this->assertEquals(['root' => ['base' => ['columns' => '1fr 1fr', 'paddingTop' => '@space.lg'], 'mobile' => ['columns' => '1']]], $this->widths('cols0002'));

        $view = $this->propose([['action' => 'move', 'id' => 'colu000A', 'parent' => 'cols0002', 'index' => null], self::style('cols0002', 'columns', [['base', 'backgroundColor', '#f5f5f4']])]);
        $this->assertSame('proposed', $view['status'], json_encode($view['error'] ?? null));
        $this->apply($view);
        $this->assertEquals(['root' => ['base' => ['backgroundColor' => '#f5f5f4', 'paddingTop' => '@space.lg'], 'mobile' => ['columns' => '1']]], $this->widths('cols0002'));

        $this->save([['op' => 'updateProps', 'nodeId' => 'cols0002', 'set' => ['style' => ['root' => ['base' => ['columns' => '1fr 3fr 1fr'], 'mobile' => ['columns' => '1']]]]]]);
        $view = $this->propose([self::addColumn('cols0002'), self::style('cols0002', 'columns', [['base', 'animation', 'fade-up'], ['base', 'animationTrigger', 'view']])]);
        $this->assertSame('proposed', $view['status'], json_encode($view['error'] ?? null));
        $this->apply($view);
        $this->assertEquals(['root' => ['base' => ['animation' => 'fade-up', 'animationTrigger' => 'view'], 'mobile' => ['columns' => '1']]], $this->widths('cols0002'));
    }

    public function test_explicit_widths_count_per_screen(): void
    {
        $this->addColumns(['base' => ['columns' => '1fr 2fr'], 'tablet' => ['columns' => '2fr 1fr']]);
        // Only phones are set explicitly (stacking): base and tablet still need reconciling.
        $view = $this->propose([self::addColumn('cols0001'), self::style('cols0001', 'columns', [['mobile', 'columns', '1']])]);
        $this->assertSame('proposed', $view['status'], json_encode($view['error'] ?? null));
        $this->assertContains('Columns widths on all screens and tablets went back to equal: 2 widths no longer fit 3 columns', $view['proposal']['changes']);
        $this->apply($view);
        $this->assertEquals(['root' => ['mobile' => ['columns' => '1']]], $this->widths('cols0001'));

        // Valid explicit final proportions (with another setting) are kept exactly; an intentional reset stays a reset.
        $view = $this->propose([self::addColumn('cols0001'), self::style('cols0001', 'columns', [['base', 'columns', '1fr 1fr 1fr 2fr'], ['base', 'gap', '24px'], ['tablet', 'columns', '2']])]);
        $this->assertSame('proposed', $view['status'], json_encode($view['error'] ?? null));
        $this->apply($view);
        $this->assertEquals(['root' => ['base' => ['columns' => '1fr 1fr 1fr 2fr', 'gap' => '24px'], 'tablet' => ['columns' => '2'], 'mobile' => ['columns' => '1']]], $this->widths('cols0001'));
        $view = $this->propose([['action' => 'remove', 'id' => 'colu000A'], self::style('cols0001', 'columns', [['base', 'columns', null]])]);
        $this->assertSame('proposed', $view['status'], json_encode($view['error'] ?? null));
        $this->apply($view);
        $this->assertEquals(['root' => ['base' => ['gap' => '24px'], 'tablet' => ['columns' => '2'], 'mobile' => ['columns' => '1']]], $this->widths('cols0001'));
    }

    public function test_php_and_typescript_reconcile_the_shared_cases_alike(): void
    {
        foreach (json_decode((string) file_get_contents(base_path('tests/Conformance/column-layouts.json')), true) as $case) {
            $this->assertEquals($case['expected'], ColumnLayout::reconcile($case['style'], $case['oldCount'], $case['mapping'], $case['keep'] ?? []), $case['name']);
        }
    }

    public function test_ai_moves_and_removals_keep_widths_with_their_columns(): void
    {
        $this->addColumns();
        // Moving column B (2fr) out of the first block and into the second: the first keeps nothing to share
        // (one column left), the second gains a column it has no width for (back to equal, said so).
        $view = $this->propose([['action' => 'move', 'id' => 'colu000B', 'parent' => 'cols0002', 'index' => 0]]);
        $this->assertSame('proposed', $view['status'], json_encode($view['error'] ?? null));
        $this->apply($view);
        $this->assertEquals(['root' => ['mobile' => ['columns' => '1']]], $this->widths('cols0001'));
        $this->assertEquals(['root' => ['mobile' => ['columns' => '1']]], $this->widths('cols0002'));
        // Reordering within a block: widths follow their columns; removing one drops its width.
        $this->save([['op' => 'updateProps', 'nodeId' => 'cols0002', 'set' => ['style' => ['root' => ['base' => ['columns' => '3fr 2fr 1fr 1fr'], 'mobile' => ['columns' => '1']]]]]]);
        $view = $this->propose([['action' => 'move', 'id' => 'colu000B', 'parent' => 'cols0002', 'index' => null], ['action' => 'remove', 'id' => 'colu000C']]);
        $this->apply($view);
        $this->assertSame(['colu000D', 'colu000E', 'colu000B'], $this->draft()['nodes']['cols0002']['children']);
        $this->assertEquals(['root' => ['base' => ['columns' => '1fr 1fr 3fr'], 'mobile' => ['columns' => '1']]], $this->widths('cols0002'));
    }

    public function test_the_mcp_path_reconciles_and_validates_the_same_way(): void
    {
        $this->addColumns();
        ['token' => $token] = app(AiConnections::class)->create($this->f['siteId'], $this->f['ctx']->userId, 'mcp', 'VS Code');
        $submit = function (array $changes, string $key) use ($token): array {
            $result = app()->make(McpServer::class, ['token' => $token])->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'arkon_submit_proposal', 'arguments' => [
                'pageId' => $this->f['pageId'], 'baseVersion' => $this->version(), 'request' => 'Columns', 'requestKey' => $key,
                'proposal' => ['summary' => 'Columns.', 'notes' => [], 'tokenChanges' => [], 'changes' => $changes],
            ]]])['result'];

            return ['error' => $result['isError'], 'data' => json_decode($result['content'][0]['text'], true)];
        };
        $refused = $submit([self::addColumn('cols0001'), self::setWidths('cols0001', [['base', '1fr 1fr']])], 'vscode-columns-0001');
        $this->assertTrue($refused['error']);
        $this->assertStringContainsString('2 widths for 3 columns; give one width per column', json_encode($refused['data'], JSON_UNESCAPED_UNICODE));
        $submitted = $submit([self::addColumn('cols0001')], 'vscode-columns-0002');
        $this->assertFalse($submitted['error'], json_encode($submitted['data']));
        $view = app(ProposalService::class)->status($this->f['ctx'], $this->f['pageId'], $submitted['data']['proposalId'], new MediaSigner);
        $this->apply($view);
        $this->assertEquals(['root' => ['mobile' => ['columns' => '1']]], $this->widths('cols0001'));
    }

    public function test_an_existing_draft_that_breaks_the_contract_opens_in_recovery_and_is_never_rewritten_on_load(): void
    {
        $this->addColumns();
        $doc = $this->draft();
        $doc['nodes']['cols0001']['props']['style']['root']['tablet'] = ['columns' => '1fr 1fr 1fr 1fr 1fr'];
        DB::table('page_drafts')->where('page_id', $this->f['pageId'])->update(['document' => Json::encode($doc)]);
        $stored = DB::table('page_drafts')->where('page_id', $this->f['pageId'])->value('document');

        $state = $this->pages()->editorState($this->f['ctx'], $this->f['pageId']);
        $this->assertSame([['nodeId' => 'cols0001', 'type' => 'columns', 'path' => 'style.root.tablet.columns', 'value' => '1fr 1fr 1fr 1fr 1fr', 'message' => 'Columns: 5 widths for 2 columns; give one width per column']], $state['recovery']);
        $this->assertSame($stored, DB::table('page_drafts')->where('page_id', $this->f['pageId'])->value('document'), 'nothing rewritten on load');
        // The repair (that screen back to equal widths) saves normally.
        $this->save([['op' => 'updateProps', 'nodeId' => 'cols0001', 'set' => ['style' => ['root' => ['base' => ['columns' => '1fr 2fr'], 'mobile' => ['columns' => '1']]]]]]);
        $this->assertNull($this->pages()->editorState($this->f['ctx'], $this->f['pageId'])['recovery']);
    }

    public function test_historical_documents_with_mismatched_widths_still_render_as_published(): void
    {
        // Published before the contract (validated with each node's own version): pinned rendering accepts it.
        $doc = ['schemaVersion' => 1, 'root' => 'root0001', 'seo' => new \stdClass, 'nodes' => [
            'root0001' => ['id' => 'root0001', 'type' => 'page', 'version' => 3, 'props' => new \stdClass, 'children' => ['cols0001']],
            'cols0001' => ['id' => 'cols0001', 'type' => 'columns', 'version' => 2, 'props' => ['style' => ['root' => ['base' => ['columns' => '1fr 2fr']]]], 'children' => ['colu0001', 'colu0002', 'colu0003']],
            'colu0001' => ['id' => 'colu0001', 'type' => 'column', 'version' => 2, 'props' => new \stdClass, 'children' => []],
            'colu0002' => ['id' => 'colu0002', 'type' => 'column', 'version' => 2, 'props' => new \stdClass, 'children' => []],
            'colu0003' => ['id' => 'colu0003', 'type' => 'column', 'version' => 2, 'props' => new \stdClass, 'children' => []],
        ]];
        $out = app(PageRenderer::class)->render(Json::decode(Json::encode($doc)), 'production', ['title' => 'T', 'path' => '/'], ['name' => 'S'], [], pinned: true);
        $this->assertStringContainsString('grid-template-columns:minmax(0,1fr) minmax(0,2fr)', $out['html']);
    }
}
