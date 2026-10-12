<?php

namespace Tests\Unit;

use App\Arkon\Ai\AiException;
use App\Arkon\Ai\AiRequest;
use App\Arkon\Ai\CodexCli;
use App\Arkon\Ai\CodexRun;
use App\Arkon\Ai\StageExecutor;
use Illuminate\Support\Facades\Log;
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
        $this->assertSame("ARKON RULES\n\n".CodexRun::COMPACT."\n\ntest & $(secret)", $out['stdin']);
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

    public function test_a_rejected_schema_is_named_and_its_details_go_only_to_the_server_log(): void
    {
        Log::spy();
        try {
            $this->cli('schema')->run($this->request(), fn () => true);
            $this->fail();
        } catch (AiException $e) {
            $this->assertSame('PROVIDER_SCHEMA_REJECTED', $e->code());
            $this->assertStringContainsString('rejected the response format', $e->getMessage());
            $this->assertStringNotContainsString('SECRET_TOKEN', $e->getMessage());
        }
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $message === 'Codex run failed' && str_contains($context['error'], 'pageProposal'));
    }

    public function test_a_codex_that_never_starts_its_turn_fails_fast_instead_of_waiting_for_the_limit(): void
    {
        $cli = new CodexCli([PHP_BINARY, base_path('tests/Support/fake-codex.php'), '--scenario=silent'], 240, startupSeconds: 2);
        $started = microtime(true);
        try {
            $cli->run($this->request(), fn () => true);
            $this->fail('A silent Codex must not wait for the run limit');
        } catch (AiException $e) {
            $this->assertSame('PROVIDER_NOT_STARTED', $e->code());
        }
        $this->assertLessThan(10, microtime(true) - $started);
    }

    public function test_model_stream_reconnects_are_reported_not_hidden_and_retries_are_bounded(): void
    {
        $events = [];
        $request = new AiRequest('Rules', 'Test', ['type' => 'object'], function (array $e) use (&$events) {
            $events[] = $e['type'];
        });
        $reply = $this->cli('reconnect')->run($request, fn () => true);
        $this->assertTrue($reply->output['ok']);
        $this->assertSame(2, count(array_keys($events, 'reconnecting')));
        $this->assertContains('features.unbounded_connection_retries=false', $reply->output['args']);
    }

    public function test_a_run_limit_names_the_stage_and_what_the_model_was_doing(): void
    {
        try {
            (new CodexCli([PHP_BINARY, base_path('tests/Support/fake-codex.php'), '--scenario=timeout'], 1))->run(new AiRequest('Rules', 'Test', ['type' => 'object'], null, 1, 'Page About'), fn () => true);
            $this->fail();
        } catch (AiException $e) {
            $this->assertSame('EXECUTION_TIMEOUT', $e->code());
            $this->assertStringContainsString('Page About', $e->getMessage());
            $this->assertStringContainsString('nothing was applied', $e->getMessage());
        }
    }

    /** A temporary CODEX_HOME the fake writes what it was started with into. */
    private function codexHome(): string
    {
        $home = sys_get_temp_dir().DIRECTORY_SEPARATOR.'arkon-codex-'.bin2hex(random_bytes(4));
        mkdir($home);
        putenv('CODEX_HOME='.$home);

        return $home;
    }

    protected function tearDown(): void
    {
        putenv('CODEX_HOME');
        parent::tearDown();
    }

    /**
     * Regression (October 2026): `codex exec --json` is silent while the model writes its reply, so silence on the
     * event output is not a sign of a stuck model. A reply that keeps streaming finishes, is reported as activity,
     * and is requested compactly (the fix for the whitespace runaway).
     */
    public function test_a_reply_that_keeps_streaming_is_not_mistaken_for_a_stuck_model(): void
    {
        $home = $this->codexHome();
        config(['arkon.ai.stream_idle_seconds' => 2]);
        $events = [];
        $request = new AiRequest('Rules', 'Test', ['type' => 'object'], function (array $e) use (&$events) {
            $events[] = $e['type'];
        }, 30, 'Page Services (1/2)');
        Log::spy();
        $reply = $this->cli('streaming')->run($request, fn () => true);
        $this->assertTrue($reply->output['ok']);
        $this->assertGreaterThanOrEqual(2, count(array_keys($events, 'output')), 'streaming frames are reported as progress');
        $this->assertStringContainsString('tungstenite::protocol=trace', (string) file_get_contents($home.'/rust-log.txt'));
        Log::shouldHaveReceived('info')->withArgs(function ($message, $context) {
            return $message === 'AI provider run' && $context['stream']['traced'] === true && $context['stream']['frames'] >= 20
                && ! str_contains(json_encode($context), 'SECRET_TOKEN');
        });
    }

    public function test_the_reply_is_requested_as_compact_json(): void
    {
        $reply = $this->cli()->run($this->request(), fn () => true);
        $this->assertStringContainsString(CodexRun::COMPACT, $reply->output['stdin']);
    }

    /** The degenerate reply (endless whitespace) is stopped as soon as it is recognised, not at the step's ceiling. */
    public function test_a_runaway_reply_is_stopped_early_and_its_text_never_logged(): void
    {
        $this->codexHome();
        Log::spy();
        $started = microtime(true);
        try {
            $this->cli('runaway', 60)->run(new AiRequest('Rules', 'Test', ['type' => 'object'], null, 60, 'Page Services (1/2)'), fn () => true);
            $this->fail('A runaway reply must be stopped');
        } catch (AiException $e) {
            $this->assertSame('PROVIDER_RUNAWAY_OUTPUT', $e->code());
            $this->assertStringContainsString('Page Services (1/2)', $e->getMessage());
        }
        $this->assertLessThan(20, microtime(true) - $started);
        $this->assertContains('PROVIDER_RUNAWAY_OUTPUT', StageExecutor::TRANSIENT, 'retried once, that step only');
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $message === 'AI provider run' && ! str_contains(json_encode($context), 'SECRET_TOKEN') && $context['stream']['longestWhitespaceRun'] > 400);
    }

    /** A usage limit fails at once (never retried) and says when Codex can be used again. */
    public function test_a_usage_limit_says_when_to_try_again_and_is_not_retried(): void
    {
        try {
            $this->cli('limit')->run($this->request(), fn () => true);
            $this->fail('A usage limit must fail');
        } catch (AiException $e) {
            $this->assertSame('PROVIDER_LIMIT_REACHED', $e->code());
            $this->assertSame('Your Codex usage limit has been reached. Codex says to try again at 5:02 PM.', $e->getMessage());
            $this->assertNotContains($e->code(), StageExecutor::TRANSIENT);
        }
    }

    /** A reply that keeps growing far beyond a normal one (without whitespace) is a runaway too. */
    public function test_a_reply_that_grows_without_end_is_stopped_as_a_runaway(): void
    {
        $this->codexHome();
        config(['arkon.ai.runaway_chars' => 60]);
        try {
            $this->cli('streaming', 60)->run(new AiRequest('Rules', 'Test', ['type' => 'object'], null, 60, 'Page Gallery (2/2)'), fn () => true);
            $this->fail('An endlessly growing reply must be stopped');
        } catch (AiException $e) {
            $this->assertSame('PROVIDER_RUNAWAY_OUTPUT', $e->code());
            $this->assertStringContainsString('far beyond a normal reply', $e->getMessage());
        }
    }

    /** The frame trace never reaches a log: neither received text nor sent frames' payload (the prompt, as hex). */
    public function test_the_frame_trace_is_removed_from_logged_stderr(): void
    {
        $stderr = implode("\n", [
            '2026-10-11T05:00:00.000000Z TRACE tungstenite::protocol: Received message {"type":"response.output_text.delta","delta":"SECRET_TEXT"}',
            '2026-10-11T05:00:00.100000Z TRACE tungstenite::protocol::frame: Sending frame: Frame { header: FrameHeader { is_final: true }, payload:',
            '7b2274797065223a22726573706f6e73652e637265617465222c22696e70757422',
            '3a5b7b22726f6c65223a2275736572227d5d7d',
            '2026-10-11T05:00:01.000000Z  WARN codex_core::client: stream retry 1/5',
            'Error: unexpected status 401',
        ]);
        $kept = CodexRun::withoutFrames($stderr);
        $this->assertStringNotContainsString('SECRET_TEXT', $kept);
        $this->assertStringNotContainsString('7b2274', $kept);
        $this->assertStringNotContainsString('3a5b7b', $kept);
        $this->assertStringContainsString('stream retry 1/5', $kept);
        $this->assertStringContainsString('unexpected status 401', $kept);
    }

    /** A model stream that stops is caught by its own silence, well before the step's ceiling. */
    public function test_a_stalled_model_stream_is_stopped_by_stream_silence(): void
    {
        $this->codexHome();
        config(['arkon.ai.stream_idle_seconds' => 2]);
        $started = microtime(true);
        try {
            $this->cli('stall', 60)->run(new AiRequest('Rules', 'Test', ['type' => 'object'], null, 60, 'Page About'), fn () => true);
            $this->fail('A stalled stream must be stopped');
        } catch (AiException $e) {
            $this->assertSame('PROVIDER_STREAM_STALLED', $e->code());
        }
        $this->assertLessThan(12, microtime(true) - $started);
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
