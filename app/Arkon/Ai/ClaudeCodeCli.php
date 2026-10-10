<?php

namespace App\Arkon\Ai;

/**
 * Runs the official Claude Code CLI under the current Windows account and its normal
 * login (Claude subscription). Verified against Claude Code 2.1.289/2.1.292 and the CLI reference:
 *
 *   claude -p --output-format json --json-schema <schema>      structured output in `structured_output`
 *          --system-prompt-file <file>                         Arkon's instructions replace Claude Code's
 *          --tools "" --restricted --strict-mcp-config         no built-in tools, no user/project settings or
 *          --disallowedTools "mcp__*"                          hooks, no MCP servers
 *          --permission-mode dontAsk --permission-prompts none anything that would ask is denied
 *          --no-session-persistence --disable-slash-commands --max-turns 4
 *
 * `--bare` is not used: it never reads the subscription login (API key only).
 *
 * The program is started directly (PHP's proc_open with an argument array: no cmd.exe, no
 * shell, nothing interpolated); the prompt is fed to stdin from a file. The child runs in an
 * empty temporary directory and gets only an allow-listed environment: no database settings,
 * APP_KEY, Anthropic API keys or OAuth tokens, nor any other variable of this app. Claude's
 * stored login is never read here; `claude auth status` reports the mode, and anything other
 * than a claude.ai (subscription) login is refused.
 */
final class ClaudeCodeCli implements AiProvider, ClaudeRunner
{
    /** @param list<string>|null $command the CLI as an argument list; null when Claude Code was not found */
    public function __construct(
        private readonly ?array $command,
        private readonly ?string $model = null,
        private readonly int $timeoutSeconds = 240,
        private readonly string $minVersion = '2.1.259',
    ) {}

    public static function fromConfig(): self
    {
        $ai = config('arkon.ai');

        return new self(self::resolveCommand($ai['claude_command'] ?? null), $ai['model'] ?: null, (int) $ai['run_timeout_seconds'], (string) $ai['min_claude_version']);
    }

    /** @return list<string>|null */
    public static function resolveCommand(?string $configured): ?array
    {
        $configured = trim((string) $configured);
        if ($configured !== '') {
            $decoded = str_starts_with($configured, '[') ? json_decode($configured, true) : null;

            return is_array($decoded) && $decoded !== [] ? array_values(array_map('strval', $decoded)) : [$configured];
        }
        $windows = PHP_OS_FAMILY === 'Windows';
        $name = $windows ? 'claude.exe' : 'claude';
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_file($candidate = rtrim($dir, '\\/').DIRECTORY_SEPARATOR.$name)) {
                return [$candidate];
            }
        }
        $home = (string) (getenv('USERPROFILE') ?: getenv('HOME'));
        if ($home !== '' && is_file($candidate = $home.DIRECTORY_SEPARATOR.'.local'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.$name)) {
            return [$candidate];
        }
        // The VS Code extension bundles the native CLI; its folder name carries the version.
        $bundled = glob($home.'/.vscode/extensions/anthropic.claude-code-*/resources/native-binary/'.$name) ?: [];
        usort($bundled, fn ($a, $b) => version_compare(self::extensionVersion($b), self::extensionVersion($a)));

        return $bundled === [] ? null : [str_replace('/', DIRECTORY_SEPARATOR, $bundled[0])];
    }

    private static function extensionVersion(string $path): string
    {
        return preg_match('/anthropic\.claude-code-(\d+\.\d+\.\d+)/', $path, $m) === 1 ? $m[1] : '0.0.0';
    }

    public function check(): RunnerStatus
    {
        if ($this->command === null) {
            return new RunnerStatus(false, AiException::CLAUDE_MISSING, 'Claude Code was not found. Install it (VS Code extension or `claude install`), or set ARKON_CLAUDE_COMMAND to claude.exe.');
        }
        try {
            $version = $this->execute(['--version'], null, '', 30);
        } catch (AiException) {
            return new RunnerStatus(false, AiException::CLAUDE_MISSING, 'Claude Code could not be started. Check ARKON_CLAUDE_COMMAND.');
        }
        if ($version['code'] !== 0 || preg_match('/(\d+\.\d+\.\d+)/', $version['out'], $m) !== 1) {
            return new RunnerStatus(false, AiException::CLAUDE_MISSING, 'Claude Code did not report its version. Check that `claude --version` works.');
        }
        $version = $m[1];
        if (version_compare($version, $this->minVersion, '<')) {
            return new RunnerStatus(false, AiException::CLAUDE_OUTDATED, "Claude Code {$version} is too old; {$this->minVersion} or later is needed. Update the VS Code extension or run `claude update`.", $version);
        }

        $auth = json_decode($this->execute(['auth', 'status', '--json'], null, '', 30)['out'], true);
        $method = is_array($auth) && is_string($auth['authMethod'] ?? null) ? $auth['authMethod'] : null;
        $plan = is_array($auth) && is_string($auth['subscriptionType'] ?? null) ? $auth['subscriptionType'] : null;
        if (! is_array($auth) || ($auth['loggedIn'] ?? false) !== true || $method === 'none' || $method === null) {
            return new RunnerStatus(false, AiException::CLAUDE_NOT_LOGGED_IN, 'Claude Code is not signed in. Run `claude auth login` and sign in with your Claude account, then restart the helper.', $version, $method);
        }
        if ($method !== 'claude.ai') {
            return new RunnerStatus(false, AiException::CLAUDE_BILLING_MODE, "Claude Code is signed in with \"{$method}\" (API or Console billing). This workflow only uses a Claude subscription: run `claude auth logout`, then `claude auth login` with your Claude account.", $version, $method);
        }

        return new RunnerStatus(true, null, "Claude Code {$version}, signed in with a Claude ".($plan ? ucfirst($plan).' ' : '').'subscription.', $version, $method, $plan);
    }

    public function run(AiRequest $request, callable $keepGoing): AiCompletion
    {
        if ($this->command === null) {
            throw new AiException(AiException::CLAUDE_MISSING, 'Claude Code was not found on this computer.');
        }
        $dir = self::tempDir();
        try {
            $instructions = $dir.DIRECTORY_SEPARATOR.'instructions.txt';
            file_put_contents($instructions, $request->instructions);
            $schemaJson = json_encode($request->schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            // Windows limits the complete process command line. Share identical rules without weakening them.
            if (PHP_OS_FAMILY === 'Windows' && strlen($schemaJson) > 24000) {
                $schemaJson = json_encode(SchemaCompactor::compact($request->schema), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }
            if (PHP_OS_FAMILY === 'Windows' && (strlen($schemaJson) + 1500) * 1.1 > 32000) {
                throw new AiException(AiException::INVALID_OUTPUT, 'The registered component schema exceeds the local CLI transport limit. No model request was started.');
            }
            $args = [
                '-p', '--output-format', 'json',
                '--json-schema', $schemaJson,
                '--system-prompt-file', $instructions,
                '--tools', '', '--restricted', '--strict-mcp-config', '--disallowedTools', 'mcp__*',
                '--permission-mode', 'dontAsk', '--permission-prompts', 'none',
                '--no-session-persistence', '--disable-slash-commands', '--max-turns', '4',
                ...($this->model ? ['--model', $this->model] : []),
            ];
            if (strlen(implode(' ', [...$this->command, ...$args])) * 1.1 > 32000) {
                throw new AiException(AiException::CLAUDE_FAILED, 'This page is too large to send to Claude Code in one request.');
            }
            if ($request->onEvent) {
                ($request->onEvent)(['provider' => $this->id(), 'type' => 'started']);
            }
            $result = $this->execute($args, $dir, $request->prompt, $this->timeoutSeconds, $keepGoing);

            $completion = $this->completion($result['code'], $result['out'], $result['err']);
            if ($request->onEvent) {
                ($request->onEvent)(['provider' => $this->id(), 'type' => 'completed']);
            }

            return $completion;
        } finally {
            self::removeDir($dir);
        }
    }

    /**
     * Starts the CLI directly (no shell) with the allow-listed environment, stdin from a file and
     * output to files (Windows pipes cannot be polled without blocking), and waits for it.
     *
     * @param  callable(): bool|null  $keepGoing  checked every 2 s; false stops the process
     * @return array{code: int, out: string, err: string}
     *
     * @throws AiException CLAUDE_MISSING (cannot start), CLAUDE_TIMEOUT, CANCELLED
     */
    private function execute(array $args, ?string $cwd, string $input, int $timeout, ?callable $keepGoing = null): array
    {
        return (new CliProcess($this->command))->execute($args, $cwd, $input, $timeout, $keepGoing);
    }

    private function completion(int $exitCode, string $stdout, string $stderr): AiCompletion
    {
        $result = json_decode(trim($stdout), true);
        if (! is_array($result)) {
            throw self::classify($stdout."\n".$stderr, null, $exitCode === 0
                ? new AiException(AiException::INVALID_OUTPUT, 'Claude Code returned no readable result.')
                : null);
        }
        if (($result['is_error'] ?? false) === true || ($result['subtype'] ?? 'success') !== 'success') {
            throw self::classify((string) ($result['result'] ?? $result['subtype'] ?? ''), $result['api_error_status'] ?? null);
        }
        $output = $result['structured_output'] ?? null;
        if (! is_array($output) && is_string($result['result'] ?? null)) {
            $output = json_decode($result['result'], true);
        }
        if (! is_array($output)) {
            throw new AiException(AiException::INVALID_OUTPUT, 'Claude Code returned no structured proposal.');
        }

        $usage = [];
        foreach (['input_tokens', 'output_tokens', 'cache_read_input_tokens'] as $key) {
            if (is_int($result['usage'][$key] ?? null)) {
                $usage[$key] = $result['usage'][$key];
            }
        }

        return new AiCompletion($output, json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $this->id(), $usage);
    }

    /** Maps Claude Code's own failure text to an understandable code (never echoes more than an excerpt). */
    private static function classify(string $text, mixed $status, ?AiException $fallback = null): AiException
    {
        $excerpt = mb_substr(trim(preg_replace('/\s+/u', ' ', $text) ?? ''), 0, 200);

        return match (true) {
            $status === 429 || preg_match('/usage limit|limit reached|limit will reset|rate.?limit|out of (extra )?usage/i', $text) === 1 => new AiException(AiException::CLAUDE_LIMIT, 'Your Claude subscription’s usage limit has been reached. Try again after it resets.'),
            in_array($status, [401, 403], true) || preg_match('/not logged in|please (run )?\/?login|log in|authenticat|invalid (api )?key|oauth/i', $text) === 1 => new AiException(AiException::CLAUDE_NOT_LOGGED_IN, 'Claude Code is not signed in (or its login expired). Run `claude auth login`, then restart the helper.'),
            $fallback !== null => $fallback,
            default => new AiException(AiException::CLAUDE_FAILED, 'Claude Code failed'.($excerpt !== '' ? ": {$excerpt}" : '.')),
        };
    }

    /**
     * The child's whole environment (proc_open replaces it entirely): allow-listed variables only.
     *
     * @return array<string, string>
     */
    public static function environment(): array
    {
        return CliProcess::environment();
    }

    private static function tempDir(): string
    {
        return CliProcess::tempDir();
    }

    private static function removeDir(string $dir): void
    {
        CliProcess::removeDir($dir);
    }

    public function id(): string
    {
        return 'claude-code';
    }
}
