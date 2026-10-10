<?php

namespace Tests\Unit;

use App\Arkon\Ai\AiException;
use App\Arkon\Ai\AiRequest;
use App\Arkon\Ai\CodexCli;
use Tests\TestCase;

class CodexCliTest extends TestCase
{
    private function cli(string $scenario = 'ok', int $timeout = 30): CodexCli
    {
        return new CodexCli([PHP_BINARY, base_path('tests/Support/fake-codex.php'), '--scenario='.$scenario], $timeout);
    }

    private function request(): AiRequest
    {
        return new AiRequest('ARKON RULES', 'test & $(secret)', ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']], 'required' => ['ok'], 'additionalProperties' => false]);
    }

    public function test_checks_installation_and_native_chatgpt_auth_without_copying_credentials(): void
    {
        $this->assertTrue($this->cli()->check()->ready);
        $this->assertSame('PROVIDER_NOT_INSTALLED', (new CodexCli(null))->check()->code);
        $this->assertSame('PROVIDER_NOT_AUTHENTICATED', $this->cli('loggedout')->check()->code);
        $this->assertSame('PROVIDER_BILLING_MODE', $this->cli('apikey')->check()->code);
    }

    public function test_isolated_generation_uses_stdin_schema_and_no_application_secrets(): void
    {
        putenv('OPENAI_API_KEY=secret-test');
        putenv('DATABASE_URL=secret-test');
        try {
            $out = $this->cli()->run($this->request(), fn () => true)->output;
        } finally {
            putenv('OPENAI_API_KEY');
            putenv('DATABASE_URL');
        }
        $this->assertSame("ARKON RULES\n\ntest & $(secret)", $out['stdin']);
        foreach (['--ignore-user-config', '--ignore-rules', '--ephemeral', '--sandbox', 'read-only', '--output-schema', 'features.shell_tool=false', 'features.unified_exec=false', 'features.apps=false'] as $flag) {
            $this->assertContains($flag, $out['args']);
        }
        $this->assertNotContains('OPENAI_API_KEY', $out['env']);
        $this->assertNotContains('DATABASE_URL', $out['env']);
        $this->assertDirectoryDoesNotExist($out['cwd']);
        $this->assertStringNotContainsString(base_path(), $out['cwd']);
    }

    public function test_process_failures_do_not_leak_raw_diagnostics(): void
    {
        try {
            $this->cli('failure')->run($this->request(), fn () => true);
            $this->fail();
        } catch (AiException $e) {
            $this->assertSame('EXECUTION_FAILED', $e->code());
            $this->assertStringNotContainsString('SECRET_TOKEN', $e->getMessage());
        }
    }

    public function test_invalid_output_is_refused(): void
    {
        $this->expectException(AiException::class);
        $this->cli('invalid')->run($this->request(), fn () => true);
    }

    public function test_timeout_stops_process(): void
    {
        try {
            $this->cli('timeout', 1)->run($this->request(), fn () => true);
            $this->fail();
        } catch (AiException $e) {
            $this->assertSame('EXECUTION_TIMEOUT', $e->code());
        }
    }

    public function test_normalizes_stream_events_and_only_actual_usage(): void
    {
        $events = [];
        $request = new AiRequest('Rules', 'Test', ['type' => 'object'], function (array $event) use (&$events) {
            $events[] = $event;
        });
        $reply = $this->cli()->run($request, fn () => true);
        $this->assertSame('codex', $reply->provider);
        $this->assertSame(['input_tokens' => 5, 'output_tokens' => 2], $reply->usage);
        $this->assertSame(['started', 'completed'], array_column($events, 'type'));
        $this->assertArrayNotHasKey('cost', $reply->usage);
    }

    public function test_cancellation_stops_process(): void
    {
        try {
            $this->cli('timeout')->run($this->request(), fn () => false);
            $this->fail();
        } catch (AiException $e) {
            $this->assertSame(AiException::CANCELLED, $e->code());
        }
    }
}
