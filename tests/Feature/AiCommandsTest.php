<?php

namespace Tests\Feature;

use App\Arkon\Ai\AiConnections;
use App\Arkon\Ai\ProposalService;
use App\Arkon\Media\MediaSigner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;
use Tests\Support\FakeClaudeRunner;

/** Pairing, revoking, and the helper command end to end with the real CLI runner and a fake `claude`. */
class AiCommandsTest extends DatabaseTestCase
{
    private array $f;

    private string $tokenFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
        $this->tokenFile = storage_path('testing/ai-helper-'.bin2hex(random_bytes(4)).'.token');
        config(['arkon.ai.helper_token_file' => $this->tokenFile]);
    }

    protected function tearDown(): void
    {
        @unlink($this->tokenFile);
        putenv('ARKON_HELPER_TOKEN');
        parent::tearDown();
    }

    private function email(): string
    {
        return DB::table('users')->where('id', $this->f['ctx']->userId)->value('email');
    }

    public function test_pairing_mcp_prints_the_token_once_with_the_registration_command_and_stores_only_a_hash(): void
    {
        $this->assertSame(0, Artisan::call('arkon:ai-pair', ['email' => $this->email(), '--mcp' => true]));
        $output = Artisan::output();
        $this->assertMatchesRegularExpression('/claude mcp add arkon --scope user -e ARKON_MCP_TOKEN=(arkon_mcp_[A-Za-z0-9_-]+) -- ".+php[^"]*" ".+artisan" arkon:mcp/', $output);
        preg_match('/ARKON_MCP_TOKEN=(\S+)/', $output, $m);
        $row = DB::table('ai_connections')->first();
        $this->assertSame(['mcp', hash('sha256', $m[1])], [$row->kind, $row->token_hash]);
        $this->assertStringNotContainsString($m[1], json_encode(DB::table('ai_connections')->get()), 'the token itself is not stored');
        $this->assertNotNull(app(AiConnections::class)->resolve($m[1], 'mcp'));

        $this->assertSame(0, Artisan::call('arkon:ai-revoke', ['id' => $row->id]));
        $this->assertNull(app(AiConnections::class)->resolve($m[1], 'mcp'));
    }

    public function test_pairing_needs_an_editor_of_one_site(): void
    {
        $viewer = $this->addMember($this->f['siteId'], 'viewer');
        $email = DB::table('users')->where('id', $viewer->userId)->value('email');
        $this->assertSame(1, Artisan::call('arkon:ai-pair', ['email' => $email, '--helper' => true]));
        $this->assertStringContainsString('cannot edit pages', Artisan::output());
        $this->assertSame(1, Artisan::call('arkon:ai-pair', ['email' => 'nobody@example.com', '--mcp' => true]));
        $this->assertSame(1, Artisan::call('arkon:ai-pair', ['email' => $this->email()]), 'choose --helper or --mcp');
        $this->assertSame(0, DB::table('ai_connections')->count());
    }

    public function test_the_helper_command_runs_a_queued_request_with_the_claude_code_cli(): void
    {
        // Pairing saves the helper token to a git-ignored file; pairing again revokes the previous helper.
        $this->assertSame(0, Artisan::call('arkon:ai-pair', ['email' => $this->email(), '--helper' => true]));
        $this->assertSame(0, Artisan::call('arkon:ai-pair', ['email' => $this->email(), '--helper' => true]));
        $this->assertSame(1, DB::table('ai_connections')->whereNull('revoked_at')->count());
        $helper = app(AiConnections::class)->resolve(trim(file_get_contents($this->tokenFile)), 'helper');
        $this->assertNotNull($helper);

        config(['arkon.ai.claude_command' => json_encode([PHP_BINARY, base_path('tests/Support/fake-claude.php'), '--scenario=empty'])]);
        app(AiConnections::class)->heartbeat($helper->id, FakeClaudeRunner::ready()); // as a running helper would have reported
        $request = app(ProposalService::class)->request($this->f['ctx'], $this->f['pageId'], ['prompt' => 'Add a contact form', 'baseVersion' => 1, 'requestKey' => self::key()]);

        $this->assertSame(0, Artisan::call('arkon:ai-helper', ['--once' => true]));
        $this->assertStringContainsString('signed in with a Claude Pro subscription', Artisan::output());
        $view = app(ProposalService::class)->status($this->f['ctx'], $this->f['pageId'], $request['id'], new MediaSigner);
        $this->assertSame('empty', $view['status']);
        $this->assertStringContainsString('Request:', DB::table('ai_proposals')->value('summary') ?? '', 'the prompt reached the CLI through stdin');
        $this->assertSame(['Answered by the fake CLI.'], $view['proposal']['notes']);
        $status = json_decode(DB::table('ai_connections')->where('id', $helper->id)->value('status'), true);
        $this->assertSame([true, '2.1.292', 'claude.ai'], [$status['ready'], $status['claudeVersion'], $status['authMethod']]);
    }

    public function test_the_helper_refuses_api_billing_and_reports_why(): void
    {
        $this->assertSame(0, Artisan::call('arkon:ai-pair', ['email' => $this->email(), '--helper' => true]));
        config(['arkon.ai.claude_command' => json_encode([PHP_BINARY, base_path('tests/Support/fake-claude.php'), '--scenario=apikey'])]);
        $this->assertSame(0, Artisan::call('arkon:ai-helper', ['--once' => true]));
        $this->assertStringContainsString('API or Console billing', Artisan::output());
        $connection = app(AiConnections::class)->helperStatus($this->f['siteId']);
        $this->assertFalse($connection['ready']);
        $this->assertStringContainsString('claude auth login', $connection['message']);
    }
}
