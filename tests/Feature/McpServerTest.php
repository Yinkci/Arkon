<?php

namespace Tests\Feature;

use App\Arkon\Ai\AiConnections;
use App\Arkon\Ai\Mcp\McpServer;
use App\Arkon\Ai\ProposalService;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Pages\PageService;
use App\Arkon\Support\Json;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\DatabaseTestCase;

/**
 * The MCP server Claude Code (VS Code) connects to: protocol basics, the narrow tool set,
 * authentication by a revocable paired token (never by ids in arguments), site isolation,
 * validation of submitted proposals, request keys, and the stdio process itself.
 */
class McpServerTest extends DatabaseTestCase
{
    private array $f;

    private string $token;

    private string $connectionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
        ['id' => $this->connectionId, 'token' => $this->token] = app(AiConnections::class)->create($this->f['siteId'], $this->f['ctx']->userId, 'mcp', 'VS Code');
    }

    private function server(?string $token = null): McpServer
    {
        return app()->make(McpServer::class, ['token' => $token ?? $this->token]);
    }

    private function tool(string $tool, array $arguments = [], ?string $token = null): array
    {
        $response = $this->server($token)->handle(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]]);
        $result = $response['result'];

        return ['error' => $result['isError'], 'data' => json_decode($result['content'][0]['text'], true)];
    }

    private function proposal(): array
    {
        return ['summary' => 'A landscaping hero and services.', 'notes' => [], 'changes' => [
            ['action' => 'update', 'change' => ['id' => $this->f['heroId'], 'type' => 'hero', 'props' => ['heading' => 'Gardens that grow with you', 'headingLevel' => null, 'text' => null, 'image' => null]]],
            ['action' => 'add', 'parent' => 'page', 'index' => null, 'block' => ['type' => 'text', 'props' => ['text' => 'Our services', 'element' => 'h2', 'align' => 'start']]],
        ]];
    }

    private function submit(array $overrides = [], ?string $token = null): array
    {
        return $this->tool('arkon_submit_proposal', [
            'pageId' => $this->f['pageId'], 'baseVersion' => 1, 'request' => 'Build a landscaping homepage', 'requestKey' => 'vscode-request-0001', 'proposal' => $this->proposal(), ...$overrides,
        ], $token);
    }

    public function test_protocol_basics_and_a_narrow_tool_set(): void
    {
        $server = $this->server();
        $init = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass, 'clientInfo' => ['name' => 'claude-code']]]);
        $this->assertSame(['2025-06-18', 'arkon'], [$init['result']['protocolVersion'], $init['result']['serverInfo']['name']]);
        $this->assertArrayHasKey('tools', $init['result']['capabilities']);
        $this->assertNull($server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
        $this->assertSame(-32601, $server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/list'])['error']['code']);
        $this->assertSame(-32700, json_decode($server->handleLine('{not json'), true)['error']['code']);

        $names = array_column($server->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list'])['result']['tools'], 'name');
        $this->assertSame(['arkon_list_pages', 'arkon_get_page', 'arkon_get_proposal_format', 'arkon_submit_proposal', 'arkon_get_proposal_status'], $names);
        foreach ($names as $name) {
            $this->assertDoesNotMatchRegularExpression('/publish|apply|sql|shell|exec|file|delete/i', $name);
        }
    }

    public function test_reads_pages_of_the_paired_site_only(): void
    {
        $other = $this->siteFixture();
        $list = $this->tool('arkon_list_pages');
        $this->assertFalse($list['error']);
        $this->assertSame([$this->f['pageId']], array_column($list['data']['pages'], 'id'));
        $this->assertSame(1, $list['data']['pages'][0]['draftVersion']);

        $page = $this->tool('arkon_get_page', ['pageId' => $this->f['pageId']]);
        $this->assertSame(1, $page['data']['draftVersion']);
        $this->assertSame($this->f['heroId'], $page['data']['blocks'][0]['id']);
        $format = $this->tool('arkon_get_proposal_format', ['pageId' => $this->f['pageId']]);
        $this->assertStringContainsString('- columns (Columns, version 1)', $format['data']['instructions']);
        $this->assertSame(['summary', 'notes', 'changes'], $format['data']['schema']['required']);

        // Another site's page is "not found", whatever ids are passed; there is no way to name a site or user.
        $cross = $this->tool('arkon_get_page', ['pageId' => $other['pageId'], 'siteId' => $other['siteId']]);
        $this->assertTrue($cross['error']);
        $this->assertSame('NOT_FOUND', $cross['data']['code']);
        $this->assertTrue($this->submit(['pageId' => $other['pageId']])['error']);
    }

    public function test_a_submitted_proposal_waits_in_arkon_for_review_without_changing_the_draft(): void
    {
        $draft = DB::table('page_drafts')->where('page_id', $this->f['pageId'])->value('document');
        $submitted = $this->submit();
        $this->assertFalse($submitted['error'], json_encode($submitted['data']));
        $this->assertSame('proposed', $submitted['data']['status']);
        $this->assertSame(['Change Hero “Original heading”: heading', 'Add Text “Our services”'], $submitted['data']['changes']);
        $this->assertStringEndsWith("/admin/editor/{$this->f['pageId']}", $submitted['data']['reviewUrl']);
        $this->assertSame($draft, DB::table('page_drafts')->where('page_id', $this->f['pageId'])->value('document'), 'the draft is unchanged');
        $this->assertSame(0, DB::table('page_revisions')->count());

        // In the editor: the user's waiting proposals, with a preview; applying is the normal save.
        $listed = app(ProposalService::class)->list($this->f['ctx'], $this->f['pageId'])['requests'];
        $this->assertSame([['mcp', 'proposed']], array_map(fn ($r) => [$r['source'], $r['status']], $listed));
        $view = app(ProposalService::class)->status($this->f['ctx'], $this->f['pageId'], $submitted['data']['proposalId'], new MediaSigner);
        $this->assertStringContainsString('Gardens that grow with you', $view['proposal']['canvas']['body']);
        app(PageService::class)->saveDraft($this->f['ctx'], ['pageId' => $this->f['pageId'], 'baseVersion' => 1, 'saveKey' => self::key(),
            'operations' => Json::decode(Json::encode($view['proposal']['operations'])), 'proposalId' => $view['id']]);
        $this->assertSame('ai', DB::table('page_revisions')->value('source'));
        $this->assertSame('applied', $this->tool('arkon_get_proposal_status', ['pageId' => $this->f['pageId'], 'proposalId' => $view['id']])['data']['status']);
    }

    public function test_untrusted_submissions_are_validated_and_bound_to_their_key(): void
    {
        $invalid = $this->submit(['proposal' => ['summary' => 'x', 'notes' => [], 'changes' => [['action' => 'add', 'parent' => 'page', 'index' => null, 'block' => ['type' => 'script', 'props' => []]]]]]);
        $this->assertTrue($invalid['error']);
        $this->assertSame('AI_INVALID_OUTPUT', $invalid['data']['code']);
        $this->assertStringContainsString('there is no "script" block', implode(' ', array_column($invalid['data']['issues'], 'message')));
        $this->assertSame(0, DB::table('ai_proposals')->count(), 'nothing is recorded for an invalid submission');

        $stale = $this->submit(['baseVersion' => 7]);
        $this->assertSame('STALE_VERSION', $stale['data']['code']);

        $first = $this->submit();
        $again = $this->submit();
        $this->assertSame($first['data']['proposalId'], $again['data']['proposalId'], 'a resent submission (lost acknowledgement) is the same proposal');
        $changed = $this->submit(['request' => 'Something else']);
        $this->assertSame('CONFLICT', $changed['data']['code']);
        $altered = $this->proposal();
        $altered['summary'] = 'Different';
        $this->assertSame('CONFLICT', $this->submit(['proposal' => $altered])['data']['code']);
        $this->assertSame(1, DB::table('ai_proposals')->count());
    }

    public function test_an_exact_retry_after_a_manual_edit_returns_the_original_proposal(): void
    {
        $first = $this->submit();
        app(PageService::class)->saveDraft($this->f['ctx'], ['pageId' => $this->f['pageId'], 'baseVersion' => 1, 'saveKey' => self::key(),
            'operations' => [['op' => 'updateProps', 'nodeId' => $this->f['heroId'], 'set' => ['text' => 'A manual edit']]]]);

        $retry = $this->submit();
        $this->assertFalse($retry['error'], json_encode($retry['data']));
        $this->assertSame([$first['data']['proposalId'], 'proposed', 1], [$retry['data']['proposalId'], $retry['data']['status'], $retry['data']['baseVersion']]);
        $this->assertSame(1, DB::table('ai_proposals')->count());
        // A changed payload under the same key is still a conflict, not a stale version.
        $this->assertSame('CONFLICT', $this->submit(['request' => 'Something else'])['data']['code']);
        // A new key against the old version is what gets STALE_VERSION.
        $this->assertSame('STALE_VERSION', $this->submit(['requestKey' => 'vscode-request-0002'])['data']['code']);
    }

    public function test_an_exact_retry_after_applying_returns_the_applied_proposal_without_applying_again(): void
    {
        $first = $this->submit();
        $view = app(ProposalService::class)->status($this->f['ctx'], $this->f['pageId'], $first['data']['proposalId']);
        app(PageService::class)->saveDraft($this->f['ctx'], ['pageId' => $this->f['pageId'], 'baseVersion' => 1, 'saveKey' => self::key(),
            'operations' => Json::decode(Json::encode($view['proposal']['operations'])), 'proposalId' => $view['id']]);
        $revisions = DB::table('page_revisions')->count();
        $draft = DB::table('page_drafts')->where('page_id', $this->f['pageId'])->first(['document', 'version']);

        $retry = $this->submit();
        $this->assertFalse($retry['error'], json_encode($retry['data']));
        $this->assertSame([$first['data']['proposalId'], 'applied'], [$retry['data']['proposalId'], $retry['data']['status']]);
        $this->assertSame(1, DB::table('ai_proposals')->count());
        $this->assertSame($revisions, DB::table('page_revisions')->count());
        $this->assertEquals($draft, DB::table('page_drafts')->where('page_id', $this->f['pageId'])->first(['document', 'version']));
    }

    public function test_the_token_is_the_only_identity_and_revocation_is_immediate(): void
    {
        $this->assertSame('AI_UNAUTHENTICATED', $this->tool('arkon_list_pages', token: 'arkon_mcp_wrong')['data']['code']);
        $helperToken = app(AiConnections::class)->create($this->f['siteId'], $this->f['ctx']->userId, 'helper', 'h')['token'];
        $this->assertSame('AI_UNAUTHENTICATED', $this->tool('arkon_list_pages', token: $helperToken)['data']['code'], 'a helper token is not an MCP token');

        // A user who lost edit rights can still read but not submit.
        DB::table('site_members')->where('site_id', $this->f['siteId'])->where('user_id', $this->f['ctx']->userId)->update(['role' => 'viewer']);
        $this->assertFalse($this->tool('arkon_list_pages')['error']);
        $this->assertSame('FORBIDDEN', $this->submit()['data']['code']);

        app(AiConnections::class)->revoke($this->connectionId);
        $this->assertSame('AI_UNAUTHENTICATED', $this->tool('arkon_list_pages')['data']['code']);
    }

    public function test_the_stdio_server_speaks_newline_delimited_json_rpc(): void
    {
        $process = new Process([PHP_BINARY, base_path('artisan'), 'arkon:mcp'], base_path(), [
            'ARKON_MCP_TOKEN' => $this->token,
            'APP_ENV' => 'testing',
            'DB_DATABASE' => config('database.connections.pgsql.database'),
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
        ]);
        $process->setInput(implode("\n", [
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => new \stdClass, 'clientInfo' => ['name' => 'test']]]),
            json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']),
            json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'arkon_list_pages', 'arguments' => new \stdClass]]),
        ])."\n");
        $process->setTimeout(60);
        $process->run();

        $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
        $this->assertCount(2, $lines, 'only protocol messages on stdout: '.$process->getOutput().$process->getErrorOutput());
        $this->assertSame('2025-11-25', json_decode($lines[0], true)['result']['protocolVersion']);
        $pages = json_decode($lines[1], true)['result']['structuredContent']['pages'];
        $this->assertSame([$this->f['pageId']], array_column($pages, 'id'));
    }
}
