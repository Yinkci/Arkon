<?php

namespace Tests\Unit;

use App\Arkon\Ai\AiException;
use App\Arkon\Ai\AiRequest;
use App\Arkon\Ai\ClaudeCodeCli;
use App\Arkon\Ai\ProposalSchema;
use Tests\TestCase;

/**
 * The Claude Code CLI runner against a fake `claude` executable (tests/Support/fake-claude.php):
 * readiness and login mode, the exact flags, prompt through stdin, a clean environment, an empty
 * working directory, and the mapping of Claude Code's failures. No real Claude Code or login.
 */
class ClaudeCodeCliTest extends TestCase
{
    private function cli(string $scenario = 'echo', int $timeout = 60): ClaudeCodeCli
    {
        return new ClaudeCodeCli([PHP_BINARY, base_path('tests/Support/fake-claude.php'), "--scenario={$scenario}"], timeoutSeconds: $timeout);
    }

    private function request(string $prompt = 'Build a homepage'): AiRequest
    {
        return new AiRequest('ARKON INSTRUCTIONS', $prompt, ['type' => 'object', 'properties' => ['summary' => ['type' => 'string']], 'required' => ['summary'], 'additionalProperties' => false]);
    }

    private function assertAiError(string $code, callable $call): AiException
    {
        try {
            $call();
        } catch (AiException $error) {
            $this->assertSame($code, $error->code(), $error->getMessage());

            return $error;
        }
        $this->fail("Expected {$code}");
    }

    public function test_readiness_requires_a_recent_claude_code_signed_in_with_a_subscription(): void
    {
        $ready = $this->cli()->check();
        $this->assertTrue($ready->ready);
        $this->assertSame(['2.1.292', 'claude.ai', 'pro'], [$ready->version, $ready->authMethod, $ready->subscriptionType]);
        $this->assertStringNotContainsString('someone@example.com', json_encode($ready->toArray()), 'no account details are reported');

        $apiKey = $this->cli('apikey')->check();
        $this->assertSame([false, AiException::CLAUDE_BILLING_MODE], [$apiKey->ready, $apiKey->code]);
        $this->assertStringContainsString('api_key', $apiKey->message);
        $this->assertSame(AiException::CLAUDE_NOT_LOGGED_IN, $this->cli('loggedout')->check()->code);
        $this->assertSame(AiException::CLAUDE_OUTDATED, $this->cli('old')->check()->code);
        $this->assertSame(AiException::CLAUDE_MISSING, $this->cli('noversion')->check()->code);
        $this->assertSame(AiException::CLAUDE_MISSING, (new ClaudeCodeCli(null))->check()->code);
    }

    public function test_runs_print_mode_with_structured_output_no_tools_and_the_prompt_on_stdin(): void
    {
        $prompt = 'Build a homepage" & del /q *.* | echo $(whoami) `rm -rf /` %PATH% ünïcode';
        $out = $this->cli()->run($this->request($prompt), fn () => true)->output;

        $this->assertSame($prompt, $out['stdin'], 'the prompt arrives byte for byte through stdin, never through a shell');
        $args = $out['args'];
        foreach (['-p', '--output-format', 'json', '--json-schema', '--system-prompt-file', '--tools', '', '--restricted', '--strict-mcp-config', '--disallowedTools', 'mcp__*', '--permission-mode', 'dontAsk', '--permission-prompts', 'none', '--no-session-persistence', '--disable-slash-commands', '--max-turns'] as $expected) {
            $this->assertContains($expected, $args);
        }
        $this->assertNotContains('--bare', $args, '--bare would skip the subscription login');
        $this->assertNotContains('--dangerously-skip-permissions', $args);
        $this->assertSame('', $args[array_search('--tools', $args, true) + 1], 'all built-in tools disabled');
        $this->assertSame(['summary'], $out['schema']['required']);
        $this->assertSame('ARKON INSTRUCTIONS', $out['systemPrompt']);
        $this->assertSame(['instructions.txt'], $out['cwdFiles'], 'an empty temporary working directory: no project files');
        $this->assertStringNotContainsString(base_path(), $out['cwd']);
        $this->assertDirectoryDoesNotExist($out['cwd'], 'removed afterwards');
    }

    public function test_the_real_proposal_schema_arrives_intact_with_its_quotes_and_backslashes(): void
    {
        // Through cmd.exe (Symfony Process on Windows) this schema arrived corrupted; the CLI is started directly now.
        $schema = app(ProposalSchema::class)->schema(['01890a5d-ac96-774b-bcce-b302099a8057']);
        $tricky = ['type' => 'object', 'description' => 'quotes " \\" backslash \\ percent %PATH% bang !x! caret ^ amp & pipe | <>'];
        $out = $this->cli()->run(new AiRequest('i', 'p', [...$schema, '$defs' => [...$schema['$defs'], 'tricky' => $tricky]]), fn () => true)->output;
        $this->assertSame(json_decode(json_encode($schema), true)['$defs']['block_columns'], $out['schema']['$defs']['block_columns']);
        $this->assertSame($tricky, $out['schema']['$defs']['tricky']);
    }

    public function test_the_child_gets_no_database_settings_app_key_or_anthropic_credentials(): void
    {
        $secrets = ['ANTHROPIC_API_KEY' => 'sk-ant-should-not-leak', 'ANTHROPIC_AUTH_TOKEN' => 'x', 'CLAUDE_CODE_OAUTH_TOKEN' => 'x', 'DB_PASSWORD' => 'x', 'MIGRATION_DB_PASSWORD' => 'x', 'APP_KEY' => 'x', 'ARKON_HELPER_TOKEN' => 'x'];
        foreach ($secrets as $name => $value) {
            putenv("{$name}={$value}");
            $_SERVER[$name] = $value;
            $_ENV[$name] = $value;
        }
        try {
            $env = array_map('strtoupper', $this->cli()->run($this->request(), fn () => true)->output['env']);
        } finally {
            foreach (array_keys($secrets) as $name) {
                putenv($name);
                unset($_SERVER[$name], $_ENV[$name]);
            }
        }
        foreach (array_keys($secrets) as $name) {
            $this->assertNotContains($name, $env);
        }
        $this->assertSame([], array_values(array_filter($env, fn ($n) => str_starts_with($n, 'DB_') || str_starts_with($n, 'ANTHROPIC') || str_starts_with($n, 'ARKON'))));
        $this->assertContains('PATH', $env);
        $this->assertContains('USERPROFILE', PHP_OS_FAMILY === 'Windows' ? $env : ['USERPROFILE']);
    }

    public function test_claude_code_failures_are_mapped_to_understandable_errors(): void
    {
        $this->assertStringContainsString('usage limit', $this->assertAiError(AiException::CLAUDE_LIMIT, fn () => $this->cli('limit')->run($this->request(), fn () => true))->getMessage());
        $this->assertStringContainsString('claude auth login', $this->assertAiError(AiException::CLAUDE_NOT_LOGGED_IN, fn () => $this->cli('login')->run($this->request(), fn () => true))->getMessage());
        $this->assertAiError(AiException::INVALID_OUTPUT, fn () => $this->cli('garbage')->run($this->request(), fn () => true));
        $this->assertAiError(AiException::INVALID_OUTPUT, fn () => $this->cli('noschema')->run($this->request(), fn () => true));
        $this->assertAiError(AiException::CLAUDE_MISSING, fn () => (new ClaudeCodeCli(null))->run($this->request(), fn () => true));
    }

    public function test_slow_runs_time_out_and_cancelled_runs_stop(): void
    {
        $started = microtime(true);
        $this->assertAiError(AiException::CLAUDE_TIMEOUT, fn () => $this->cli('slow', timeout: 2)->run($this->request(), fn () => true));
        $this->assertLessThan(12, microtime(true) - $started, 'the process was stopped, not waited for');

        $started = microtime(true);
        $this->assertAiError(AiException::CANCELLED, fn () => $this->cli('slow')->run($this->request(), fn () => false));
        $this->assertLessThan(12, microtime(true) - $started);
    }

    public function test_finds_the_cli_from_configuration_or_standard_places(): void
    {
        $this->assertSame(['node', 'fake.mjs'], ClaudeCodeCli::resolveCommand('["node","fake.mjs"]'));
        $this->assertSame(['C:\\Tools\\claude.exe'], ClaudeCodeCli::resolveCommand('C:\\Tools\\claude.exe'));
        $found = ClaudeCodeCli::resolveCommand(null);
        $this->assertTrue($found === null || str_contains(strtolower($found[0]), 'claude'));
    }
}
