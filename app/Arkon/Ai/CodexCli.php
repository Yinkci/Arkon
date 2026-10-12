<?php

namespace App\Arkon\Ai;

use App\Arkon\Ai\Images\GeneratedImage;
use App\Arkon\Ai\Images\GeneratedImageImporter;
use App\Arkon\Ai\Images\ImageGenerator;

/**
 * Native ChatGPT login stays with Codex. Isolated generation, not repository access.
 *
 * Image generation uses the same isolated `codex exec` (read-only sandbox; shell, patching, apps,
 * skills and web search off) with Codex's built-in image_generation feature on. `exec --json`
 * reports the thread id but not the image, so the image is read from the file Codex itself saves
 * at <CODEX_HOME>/generated_images/<thread id>/: only that thread's folder, only files written
 * during this run. The model gets no file access for this. It is off unless the site owner enables
 * it (arkon.ai.codex_image_generation) after a verified test.
 */
final class CodexCli implements AiProvider, ImageGenerator, StagedRunner
{
    public function __construct(private readonly ?array $command, private readonly int $timeoutSeconds = 240, private readonly bool $imagesEnabled = false, private readonly ?string $codexHome = null, private readonly int $imageTimeoutSeconds = 300, private readonly int $startupSeconds = 60) {}

    public function id(): string
    {
        return 'codex';
    }

    public static function fromConfig(): self
    {
        return new self(self::resolveCommand(config('arkon.ai.codex_command')), (int) config('arkon.ai.run_timeout_seconds'), (bool) config('arkon.ai.codex_image_generation'), null, (int) config('arkon.ai.image_timeout_seconds'), (int) config('arkon.ai.startup_timeout_seconds', 60));
    }

    public static function resolveCommand(?string $configured): ?array
    {
        if ($configured) {
            // Command arrays are test fixtures only; production accepts executable paths.
            if (str_starts_with($configured, '[') && app()->environment('testing')) {
                $list = json_decode($configured, true);
                if (is_array($list) && $list !== [] && count(array_filter($list, 'is_string')) === count($list)) {
                    return array_values($list);
                }
            }
            if (! is_file($configured)) {
                return null;
            }

            return self::nativeCommand($configured);
        }
        $home = getenv('USERPROFILE') ?: getenv('HOME');
        $dirs = [...explode(PATH_SEPARATOR, (string) getenv('PATH')), $home.'/.local/bin'];
        foreach ($dirs as $dir) {
            foreach (PHP_OS_FAMILY === 'Windows' ? ['codex.exe', 'codex.cmd'] : ['codex'] as $name) {
                $candidate = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$name;
                if (is_file($candidate) && ($command = self::nativeCommand($candidate))) {
                    return $command;
                }
            }
        }
        // Desktop's native CLI is available without copying its credentials.
        $bundled = glob($home.'/AppData/Local/OpenAI/Codex/bin/*/codex.exe') ?: [];
        usort($bundled, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $bundled ? [$bundled[0]] : null;
    }

    private static function nativeCommand(string $file): ?array
    {
        if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['cmd', 'ps1'], true)) {
            // Never execute shell wrappers. Resolve the official npm package's native binary.
            $bins = glob(dirname($file).'/node_modules/@openai/codex*/vendor/*/codex/codex.exe') ?: [];

            return $bins ? [$bins[0]] : null;
        }

        return [$file];
    }

    private function process(): CliProcess
    {
        return new CliProcess($this->command, 'PROVIDER_NOT_INSTALLED', 'EXECUTION_TIMEOUT');
    }

    public function check(): RunnerStatus
    {
        if (! $this->command) {
            return new RunnerStatus(false, 'PROVIDER_NOT_INSTALLED', 'Codex CLI was not found. Install Codex CLI, then run codex login.');
        }
        try {
            $version = $this->process()->execute(['--version'], null, '', 30);
            if ($version['code'] !== 0 || ! preg_match('/(\d+\.\d+\.\d+)/', $version['out'], $m)) {
                return new RunnerStatus(false, 'PROVIDER_UNAVAILABLE', 'Codex could not report its version.');
            }
            $help = $this->process()->execute(['exec', '--help'], null, '', 30);
            if (! str_contains($help['out'], '--ignore-user-config') || ! str_contains($help['out'], '--ephemeral')) {
                return new RunnerStatus(false, 'PROVIDER_UNAVAILABLE', 'Update Codex: this version does not support isolated execution.', $m[1]);
            }
            $auth = $this->process()->execute(['login', 'status'], null, '', 30);
            if ($auth['code'] !== 0) {
                return new RunnerStatus(false, 'PROVIDER_NOT_AUTHENTICATED', 'Codex needs sign-in. Run codex login, then check again.', $m[1]);
            }
            if (! str_contains(strtolower($auth['out'].' '.$auth['err']), 'chatgpt')) {
                return new RunnerStatus(false, 'PROVIDER_BILLING_MODE', 'Sign in to Codex with ChatGPT. API-key billing is not used by this connection.', $m[1]);
            }

            return new RunnerStatus(true, null, 'Codex '.$m[1].', signed in with ChatGPT.', $m[1], 'chatgpt');
        } catch (AiException $e) {
            return new RunnerStatus(false, $e->code(), $e->getMessage());
        }
    }

    /** One isolated, non-interactive run: no user config or rules, no session files, no shell, patches, apps, skills or web. */
    public static function isolatedArgs(string $schema, string $output, bool $imageGeneration): array
    {
        return ['exec', '--ignore-user-config', '--ignore-rules', '--ephemeral', '--skip-git-repo-check', '--sandbox', 'read-only', '--json', '--color', 'never', '--output-schema', $schema, '--output-last-message', $output,
            '-c', 'approval_policy="never"', '-c', 'features.shell_tool=false', '-c', 'features.unified_exec=false', '-c', 'features.apply_patch_freeform=false', '-c', 'features.shell_snapshot=false', '-c', 'features.apps=false',
            '-c', 'features.image_generation='.($imageGeneration ? 'true' : 'false'), '-c', 'features.unbounded_connection_retries=false', '-c', 'web_search="disabled"', '-c', 'project_doc_max_bytes=0', '-'];
    }

    public function imageStatus(?RunnerStatus $text = null): RunnerStatus
    {
        if (! $this->imagesEnabled) {
            return new RunnerStatus(false, 'IMAGE_GENERATION_DISABLED', 'Image generation through Codex is turned off in Arkon. It is enabled only after you approve one real test generation (ARKON_CODEX_IMAGE_GENERATION; see AI setup).');
        }
        $text ??= $this->check();
        if (! $text->ready) {
            return new RunnerStatus(false, $text->code, $text->message, $text->version);
        }
        try {
            $features = $this->process()->execute(['features', 'list', '-c', 'features.image_generation=true'], null, '', 30);
        } catch (AiException) {
            return new RunnerStatus(false, 'IMAGE_GENERATION_UNAVAILABLE', 'Codex could not report its features.', $text->version);
        }
        if (preg_match('/^image_generation\s+(.+?)\s+(true|false)\s*$/m', $features['out'], $m) !== 1 || trim($m[1]) === 'removed' || $m[2] !== 'true') {
            return new RunnerStatus(false, 'IMAGE_GENERATION_UNAVAILABLE', 'This Codex version does not offer image generation. Update Codex, then check again.', $text->version);
        }

        return new RunnerStatus(true, null, 'Codex '.$text->version.' image generation with your ChatGPT account.', $text->version, 'chatgpt');
    }

    public function generate(string $prompt, string $size, callable $keepGoing): GeneratedImage
    {
        if (! $this->imagesEnabled || ! $this->command) {
            throw new AiException('IMAGE_GENERATION_DISABLED', 'Image generation through Codex is not enabled.');
        }
        $dir = CliProcess::tempDir();
        try {
            $schema = $dir.'/schema.json';
            $output = $dir.'/result.json';
            file_put_contents($schema, json_encode(['type' => 'object', 'additionalProperties' => false, 'required' => ['generated', 'problem'], 'properties' => ['generated' => ['type' => 'boolean'], 'problem' => ['type' => ['string', 'null']]]], JSON_THROW_ON_ERROR));
            $instructions = "Use your image generation tool exactly once to create the image described below at {$size}. Do not run commands, read or write files, or browse. "
                .'If the request cannot be generated (for example a safety refusal), do not generate anything else instead. '
                .'Then reply only with JSON: {"generated": true, "problem": null} after generating, or {"generated": false, "problem": "<short reason>"}.';
            $thread = null;
            $buffer = '';
            $limit = false;
            $stream = function (string $chunk) use (&$buffer, &$thread, &$limit) {
                $buffer .= $chunk;
                while (($end = strpos($buffer, "\n")) !== false) {
                    $event = json_decode(substr($buffer, 0, $end), true);
                    $buffer = substr($buffer, $end + 1);
                    if (! is_array($event)) {
                        continue;
                    }
                    if (($event['type'] ?? '') === 'thread.started' && is_string($event['thread_id'] ?? null)) {
                        $thread = $event['thread_id'];
                    } elseif (in_array($event['type'] ?? '', ['turn.failed', 'error'], true) && preg_match('/usage.?limit|rate.?limit|quota/i', (string) json_encode($event)) === 1) {
                        $limit = true;
                    }
                }
            };
            $started = time();
            $result = $this->process()->execute(self::isolatedArgs($schema, $output, imageGeneration: true), $dir, $instructions."\n\n".$prompt, $this->imageTimeoutSeconds, $keepGoing, $stream);
            if ($result['code'] !== 0) {
                $text = $result['out'].' '.$result['err'];
                throw match (true) {
                    $limit || preg_match('/usage.?limit|rate.?limit|quota/i', $text) === 1 => new AiException('PROVIDER_LIMIT_REACHED', 'Your Codex image generation limit has been reached. Try again after it resets.'),
                    preg_match('/not logged in|unauthoriz|authentication|401/i', $text) === 1 => new AiException('PROVIDER_NOT_AUTHENTICATED', 'Codex needs sign-in. Run codex login.'),
                    default => new AiException('IMAGE_FAILED', 'Codex could not generate the image. Check the Codex helper, then retry the image.'),
                };
            }
            $reply = json_decode(is_file($output) ? (string) file_get_contents($output) : '', true);
            if (is_array($reply) && ($reply['generated'] ?? null) === false) {
                throw new AiException('IMAGE_NOT_GENERATED', 'Codex did not generate the image'.(is_string($reply['problem'] ?? null) ? ': '.mb_substr($reply['problem'], 0, 300) : '.'));
            }
            $file = $this->savedImage($thread, $started);
            if ($file === null) {
                throw new AiException('IMAGE_NOT_SAVED', 'Codex finished but no generated image file was found for this run. This Codex version may not save images from non-interactive runs, so it cannot be used for image generation.');
            }
            $bytes = filesize($file);
            if ($bytes === false || $bytes > GeneratedImageImporter::MAX_SOURCE_BYTES) {
                throw new AiException('IMAGE_INVALID', 'The generated image file is too large to import.');
            }

            return new GeneratedImage((string) file_get_contents($file), $this->id());
        } finally {
            CliProcess::removeDir($dir);
        }
    }

    /** The newest image Codex saved for this thread during this run, or null. Never looks outside that folder. */
    private function savedImage(?string $thread, int $startedAt): ?string
    {
        if ($thread === null || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $thread) !== 1) {
            return null;
        }
        $home = $this->codexHome ?? (getenv('CODEX_HOME') ?: (getenv('USERPROFILE') ?: getenv('HOME')).DIRECTORY_SEPARATOR.'.codex');
        $dir = rtrim($home, '\\/').DIRECTORY_SEPARATOR.'generated_images'.DIRECTORY_SEPARATOR.$thread;
        if (! is_dir($dir) || is_link($dir)) {
            return null;
        }
        $files = [];
        foreach (scandir($dir) ?: [] as $name) {
            $path = $dir.DIRECTORY_SEPARATOR.$name;
            if (preg_match('/\.(png|jpe?g|webp)$/i', $name) === 1 && is_file($path) && ! is_link($path) && filemtime($path) >= $startedAt - 5) {
                $files[] = $path;
            }
        }
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $files[0] ?? null;
    }

    public function run(AiRequest $request, callable $keepGoing): AiCompletion
    {
        $run = $this->start($request, $keepGoing);
        try {
            while (($done = $run->poll()) === null) {
                usleep(100_000);
            }

            return $done;
        } finally {
            $run->close();
        }
    }

    /**
     * Starts one isolated generation without waiting. A watchdog fails it early when Codex never starts
     * its turn; retries of a dropped model stream are bounded (Codex would otherwise retry without end)
     * and reported as activity; the stage's own time limit is the ceiling.
     */
    public function start(AiRequest $request, callable $keepGoing): PendingRun
    {
        if (! $this->command) {
            throw new AiException('PROVIDER_NOT_INSTALLED', 'Codex CLI was not found.');
        }

        return new CodexRun($this->process(), $request, $keepGoing, $request->timeout ?? $this->timeoutSeconds, $this->startupSeconds);
    }
}
