<?php

namespace Tests\Unit;

use App\Arkon\Ai\AiException;
use App\Arkon\Ai\CliProcess;
use App\Arkon\Ai\CodexCli;
use Tests\Support\FakeImageGenerator;
use Tests\TestCase;

/**
 * The Codex image adapter against a fake `codex` executable (no account, no model request): it is
 * off unless enabled, checks the feature without a model call, keeps the isolated sandbox with only
 * image generation turned on, and reads only the image this run's thread saved.
 */
class CodexImageGenerationTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = CliProcess::tempDir();
        file_put_contents($this->home.'/source.png', FakeImageGenerator::photo('320x200'));
        putenv('CODEX_HOME='.$this->home);
    }

    protected function tearDown(): void
    {
        putenv('CODEX_HOME');
        $remove = function (string $dir) use (&$remove) {
            foreach (scandir($dir) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    is_dir($dir.'/'.$name) ? $remove($dir.'/'.$name) : unlink($dir.'/'.$name);
                }
            }
            rmdir($dir);
        };
        $remove($this->home);
        parent::tearDown();
    }

    private function cli(string $scenario = 'ok', bool $enabled = true): CodexCli
    {
        return new CodexCli([PHP_BINARY, base_path('tests/Support/fake-codex.php'), '--scenario='.$scenario], 30, $enabled);
    }

    public function test_image_generation_is_off_until_enabled_and_checked_without_a_model_request(): void
    {
        $off = $this->cli(enabled: false)->imageStatus();
        $this->assertFalse($off->ready);
        $this->assertSame('IMAGE_GENERATION_DISABLED', $off->code);
        $this->assertTrue($this->cli()->check()->ready, 'text generation is unaffected');
        $this->assertTrue($this->cli()->imageStatus()->ready);
        $this->assertSame('IMAGE_GENERATION_UNAVAILABLE', $this->cli('noimages')->imageStatus()->code);
        $this->assertSame('PROVIDER_NOT_AUTHENTICATED', $this->cli('loggedout')->imageStatus()->code);
        $this->assertSame('PROVIDER_BILLING_MODE', $this->cli('apikey')->imageStatus()->code, 'only the ChatGPT account, never API-key billing');
        $this->assertFileDoesNotExist($this->home.'/last-args.json', 'no generation was started by any check');
        $this->expectException(AiException::class);
        $this->cli(enabled: false)->generate('A prompt', '1536x1024', fn () => true);
    }

    public function test_generation_keeps_the_sandbox_and_reads_only_this_runs_saved_image(): void
    {
        putenv('OPENAI_API_KEY=secret-test');
        try {
            $image = $this->cli()->generate('Subject: two adults in an office', '1536x1024', fn () => true);
        } finally {
            putenv('OPENAI_API_KEY');
        }
        $this->assertSame(file_get_contents($this->home.'/source.png'), $image->bytes);
        $this->assertSame('codex', $image->provider);
        $run = json_decode(file_get_contents($this->home.'/last-args.json'), true);
        foreach (['--ignore-user-config', '--ignore-rules', '--ephemeral', 'read-only', 'features.shell_tool=false', 'features.unified_exec=false', 'features.apps=false', 'features.unbounded_connection_retries=false', 'web_search="disabled"', 'features.image_generation=true'] as $flag) {
            $this->assertContains($flag, $run['args']);
        }
        $this->assertNotContains('danger-full-access', $run['args']);
        $this->assertNotContains('OPENAI_API_KEY', $run['env']);
        $this->assertStringContainsString('Subject: two adults in an office', $run['stdin']);
        $this->assertStringContainsString('exactly once', $run['stdin']);
    }

    public function test_missing_refused_and_limited_generations_fail_clearly(): void
    {
        foreach (['nosave' => 'IMAGE_NOT_SAVED', 'refuse' => 'IMAGE_NOT_GENERATED', 'limit' => 'PROVIDER_LIMIT_REACHED'] as $scenario => $code) {
            try {
                $this->cli($scenario)->generate('A prompt', '1024x1024', fn () => true);
                $this->fail($scenario);
            } catch (AiException $e) {
                $this->assertSame($code, $e->code(), $scenario);
            }
        }
    }
}
