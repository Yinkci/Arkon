<?php

namespace App\Arkon\Ai;

/** Native ChatGPT login stays with Codex. Isolated generation, not repository access. */
final class CodexCli implements AiProvider
{
    public function __construct(private readonly ?array $command, private readonly int $timeoutSeconds = 240) {}

    public function id(): string
    {
        return 'codex';
    }

    public static function fromConfig(): self
    {
        return new self(self::resolveCommand(config('arkon.ai.codex_command')), (int) config('arkon.ai.run_timeout_seconds'));
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

    public function run(AiRequest $request, callable $keepGoing): AiCompletion
    {
        if (! $this->command) {
            throw new AiException('PROVIDER_NOT_INSTALLED', 'Codex CLI was not found.');
        }
        $dir = CliProcess::tempDir();
        try {
            $schema = $dir.'/schema.json';
            $output = $dir.'/result.json';
            file_put_contents($schema, json_encode($request->schema, JSON_THROW_ON_ERROR));
            $args = ['exec', '--ignore-user-config', '--ignore-rules', '--ephemeral', '--skip-git-repo-check', '--sandbox', 'read-only', '--json', '--color', 'never', '--output-schema', $schema, '--output-last-message', $output,
                '-c', 'approval_policy="never"', '-c', 'features.shell_tool=false', '-c', 'features.unified_exec=false', '-c', 'features.apply_patch_freeform=false', '-c', 'features.shell_snapshot=false', '-c', 'features.skills=false', '-c', 'features.apps=false', '-c', 'web_search="disabled"', '-c', 'project_doc_max_bytes=0', '-'];
            $usage = [];
            $buffer = '';
            $emit = function (string $type, array $data = []) use ($request) {
                if ($request->onEvent) {
                    ($request->onEvent)(['provider' => $this->id(), 'type' => $type, ...$data]);
                }
            };
            $emit('started');
            $stream = function (string $chunk) use (&$buffer, &$usage, $emit) {
                $buffer .= $chunk;
                while (($end = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $end);
                    $buffer = substr($buffer, $end + 1);
                    $event = json_decode($line, true);
                    if (! is_array($event)) {
                        continue;
                    } if (($event['type'] ?? '') === 'turn.completed') {
                        foreach (['input_tokens', 'cached_input_tokens', 'output_tokens'] as $key) {
                            if (isset($event['usage'][$key]) && is_int($event['usage'][$key])) {
                                $usage[$key] = $event['usage'][$key];
                            }
                        }
                    } elseif (in_array($event['type'] ?? '', ['item.started', 'item.completed'], true)) {
                        $emit('output', ['stage' => 'generating']);
                    } elseif (($event['type'] ?? '') === 'turn.failed') {
                        $emit('failed');
                    }
                }
            };
            $result = $this->process()->execute($args, $dir, $request->instructions."\n\n".$request->prompt, $this->timeoutSeconds, $keepGoing, $stream);
            if ($result['code'] !== 0) {
                $text = $result['out'].' '.$result['err'];
                $code = preg_match('/not logged in|unauthoriz|authentication|401/i', $text) ? 'PROVIDER_NOT_AUTHENTICATED' : (preg_match('/rate.limit|usage.limit|quota/i', $text) ? 'PROVIDER_LIMIT_REACHED' : 'EXECUTION_FAILED');
                throw new AiException($code, match ($code) {
                    'PROVIDER_NOT_AUTHENTICATED' => 'Codex needs sign-in. Run codex login.', 'PROVIDER_LIMIT_REACHED' => 'Your Codex usage limit has been reached.', default => 'Codex could not finish this request. Check your local connection and try again.'
                });
            }
            $text = is_file($output) ? file_get_contents($output) : '';
            $data = json_decode($text, true);
            if (! is_array($data)) {
                throw new AiException(AiException::INVALID_OUTPUT, 'Codex returned no structured proposal.');
            }

            $emit('completed', ['usage' => $usage]);

            return new AiCompletion($data, $text, $this->id(), $usage);
        } finally {
            CliProcess::removeDir($dir);
        }
    }
}
