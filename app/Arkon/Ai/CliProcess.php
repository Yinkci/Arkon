<?php

namespace App\Arkon\Ai;

/**
 * Shared direct-process transport. No shell or application secrets; bounded lifetime and cancellation.
 *
 * The child reads its input from a file that ends (it can never wait on a terminal) and writes stdout and
 * stderr to files that are read while it runs (no pipe can fill up and block it). start() returns a CliRun
 * that is polled, so several runs can share one loop (parallel stages); execute() runs one to the end.
 */
final class CliProcess
{
    private const ENV_ALLOW = [
        'PATH', 'PATHEXT', 'SYSTEMROOT', 'SYSTEMDRIVE', 'WINDIR', 'COMSPEC', 'USERPROFILE', 'HOMEDRIVE', 'HOMEPATH', 'HOME',
        'APPDATA', 'LOCALAPPDATA', 'TEMP', 'TMP', 'TMPDIR', 'USERNAME', 'USERDOMAIN', 'COMPUTERNAME', 'PROGRAMDATA',
        'PROGRAMFILES', 'PROGRAMFILES(X86)', 'PROGRAMW6432', 'COMMONPROGRAMFILES', 'COMMONPROGRAMFILES(X86)',
        'NUMBER_OF_PROCESSORS', 'PROCESSOR_ARCHITECTURE', 'OS', 'LANG', 'LC_ALL', 'TZ', 'CLAUDE_CONFIG_DIR', 'CODEX_HOME',
    ];

    public function __construct(private readonly array $command, private readonly string $missingCode = AiException::CLAUDE_MISSING, private readonly string $timeoutCode = AiException::CLAUDE_TIMEOUT) {}

    /**
     * @param  callable(float): ?AiException|null  $watchdog  called on every poll with the elapsed seconds; an exception stops the run
     * @param  callable(string): void|null  $onError  receives stderr in whole lines while the provider runs
     * @param  array<string, string>  $env  extra environment for this run only
     */
    public function start(array $args, ?string $cwd, string $input, int $timeout, ?callable $keepGoing = null, ?callable $onOutput = null, ?callable $watchdog = null, ?callable $onError = null, array $env = []): CliRun
    {
        $closure = fn (?callable $c) => $c === null ? null : \Closure::fromCallable($c);

        return new CliRun([...$this->command, ...$args], $cwd, $input, $timeout, $closure($keepGoing), $closure($onOutput), $closure($watchdog), $this->missingCode, $this->timeoutCode, $closure($onError), $env);
    }

    public function execute(array $args, ?string $cwd, string $input, int $timeout, ?callable $keepGoing = null, ?callable $onOutput = null, ?callable $watchdog = null): array
    {
        $run = $this->start($args, $cwd, $input, $timeout, $keepGoing, $onOutput, $watchdog);
        try {
            while (($result = $run->poll()) === null) {
                usleep(100_000);
            }

            return $result;
        } finally {
            $run->close();
        }
    }

    /** Ends the CLI; Windows also terminates its whole process tree (no orphaned children). */
    public static function stop($process, int $pid): void
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

    public static function environment(): array
    {
        $env = [];
        foreach (getenv() as $key => $value) {
            if (in_array(strtoupper((string) $key), self::ENV_ALLOW, true)) {
                $env[$key] = $value;
            }
        }
        // Opt-in provider tracing for diagnosis (ARKON_AI_PROVIDER_DEBUG=1): the provider logs its network
        // activity to stderr, which the run trace keeps (bounded). Never on by default.
        if (filter_var(getenv('ARKON_AI_PROVIDER_DEBUG'), FILTER_VALIDATE_BOOL)) {
            $env['RUST_LOG'] = 'codex_core=debug,codex_api=debug,codex_client=debug,codex_http_client=debug';
        }

        return $env;
    }

    public static function tempDir(): string
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'arkon-ai-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700, true);

        return $dir;
    }

    public static function removeDir(string $dir): void
    {
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
