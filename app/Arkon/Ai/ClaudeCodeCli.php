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
final class ClaudeCodeCli implements ClaudeRunner
{
    /** Variables the CLI needs to find its executable, home, settings and temp folders. Nothing else is passed. */
    private const ENV_ALLOW = [
        'PATH', 'PATHEXT', 'SYSTEMROOT', 'SYSTEMDRIVE', 'WINDIR', 'COMSPEC', 'USERPROFILE', 'HOMEDRIVE', 'HOMEPATH', 'HOME',
        'APPDATA', 'LOCALAPPDATA', 'TEMP', 'TMP', 'TMPDIR', 'USERNAME', 'USERDOMAIN', 'COMPUTERNAME', 'PROGRAMDATA',
        'PROGRAMFILES', 'PROGRAMFILES(X86)', 'PROGRAMW6432', 'COMMONPROGRAMFILES', 'COMMONPROGRAMFILES(X86)',
        'NUMBER_OF_PROCESSORS', 'PROCESSOR_ARCHITECTURE', 'OS', 'LANG', 'LC_ALL', 'TZ', 'CLAUDE_CONFIG_DIR',
    ];

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
            $args = [
                '-p', '--output-format', 'json',
                '--json-schema', json_encode($request->schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                '--system-prompt-file', $instructions,
                '--tools', '', '--restricted', '--strict-mcp-config', '--disallowedTools', 'mcp__*',
                '--permission-mode', 'dontAsk', '--permission-prompts', 'none',
                '--no-session-persistence', '--disable-slash-commands', '--max-turns', '4',
                ...($this->model ? ['--model', $this->model] : []),
            ];
            if (strlen(implode(' ', [...$this->command, ...$args])) * 1.1 > 32000) {
                throw new AiException(AiException::CLAUDE_FAILED, 'This page is too large to send to Claude Code in one request.');
            }
            $result = $this->execute($args, $dir, $request->prompt, $this->timeoutSeconds, $keepGoing);

            return $this->completion($result['code'], $result['out'], $result['err']);
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
        $io = self::tempDir();
        try {
            file_put_contents($in = $io.DIRECTORY_SEPARATOR.'stdin', $input);
            $descriptors = [0 => ['file', $in, 'r'], 1 => ['file', $out = $io.DIRECTORY_SEPARATOR.'stdout', 'w'], 2 => ['file', $err = $io.DIRECTORY_SEPARATOR.'stderr', 'w']];
            $process = @proc_open([...$this->command, ...$args], $descriptors, $pipes, $cwd ?? $io, self::environment(), ['bypass_shell' => true, 'suppress_errors' => true]);
            if (! is_resource($process)) {
                throw new AiException(AiException::CLAUDE_MISSING, 'Claude Code could not be started.');
            }
            $started = microtime(true);
            $lastCheck = $started;
            while (true) {
                $status = proc_get_status($process);
                if (! $status['running']) {
                    $code = (int) $status['exitcode'];
                    proc_close($process);
                    break;
                }
                if (microtime(true) - $started > $timeout) {
                    self::stop($process, $status['pid']);
                    throw new AiException(AiException::CLAUDE_TIMEOUT, "Claude Code did not finish within {$timeout} seconds. Try a smaller request.");
                }
                if ($keepGoing !== null && microtime(true) - $lastCheck >= 2) {
                    $lastCheck = microtime(true);
                    if (! $keepGoing()) {
                        self::stop($process, $status['pid']);
                        throw new AiException(AiException::CANCELLED, 'The request was cancelled.');
                    }
                }
                usleep(100_000);
            }

            return ['code' => $code, 'out' => (string) @file_get_contents($out), 'err' => (string) @file_get_contents($err)];
        } finally {
            self::removeDir($io);
        }
    }

    /** Ends the CLI and anything it started. */
    private static function stop($process, int $pid): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $kill = @proc_open(['taskkill', '/PID', (string) $pid, '/T', '/F'], [1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes, null, null, ['bypass_shell' => true]);
            if (is_resource($kill)) {
                proc_close($kill);
            }
        } else {
            proc_terminate($process, 9);
        }
        proc_close($process);
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

        return new AiCompletion($output, json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
        $env = [];
        foreach (getenv() as $key => $value) {
            if (in_array(strtoupper((string) $key), self::ENV_ALLOW, true)) {
                $env[$key] = $value;
            }
        }

        return $env;
    }

    private static function tempDir(): string
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'arkon-claude-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700, true);

        return $dir;
    }

    private static function removeDir(string $dir): void
    {
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
