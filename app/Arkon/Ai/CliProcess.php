<?php

namespace App\Arkon\Ai;

/** Shared direct-process transport. No shell or application secrets; bounded lifetime and cancellation. */
final class CliProcess
{
    private const ENV_ALLOW = [
        'PATH', 'PATHEXT', 'SYSTEMROOT', 'SYSTEMDRIVE', 'WINDIR', 'COMSPEC', 'USERPROFILE', 'HOMEDRIVE', 'HOMEPATH', 'HOME',
        'APPDATA', 'LOCALAPPDATA', 'TEMP', 'TMP', 'TMPDIR', 'USERNAME', 'USERDOMAIN', 'COMPUTERNAME', 'PROGRAMDATA',
        'PROGRAMFILES', 'PROGRAMFILES(X86)', 'PROGRAMW6432', 'COMMONPROGRAMFILES', 'COMMONPROGRAMFILES(X86)',
        'NUMBER_OF_PROCESSORS', 'PROCESSOR_ARCHITECTURE', 'OS', 'LANG', 'LC_ALL', 'TZ', 'CLAUDE_CONFIG_DIR', 'CODEX_HOME',
    ];

    public function __construct(private readonly array $command, private readonly string $missingCode = AiException::CLAUDE_MISSING, private readonly string $timeoutCode = AiException::CLAUDE_TIMEOUT) {}

    public function execute(array $args, ?string $cwd, string $input, int $timeout, ?callable $keepGoing = null, ?callable $onOutput = null): array
    {
        $io = self::tempDir();
        $process = null;
        try {
            file_put_contents($in = $io.DIRECTORY_SEPARATOR.'stdin', $input);
            $descriptors = [0 => ['file', $in, 'r'], 1 => ['file', $out = $io.DIRECTORY_SEPARATOR.'stdout', 'w'], 2 => ['file', $err = $io.DIRECTORY_SEPARATOR.'stderr', 'w']];
            $process = @proc_open([...$this->command, ...$args], $descriptors, $pipes, $cwd ?? $io, self::environment(), ['bypass_shell' => true, 'suppress_errors' => true]);
            if (! is_resource($process)) {
                throw new AiException($this->missingCode, 'The AI executable could not be started.');
            }
            $started = microtime(true);
            $lastCheck = $started;
            $offset = 0;
            while (true) {
                clearstatcache(true, $out);
                clearstatcache(true, $err);
                if ((int) @filesize($out) + (int) @filesize($err) > 8 * 1024 * 1024) {
                    throw new AiException(AiException::INVALID_OUTPUT, 'The provider output exceeded the safe transport limit.');
                }
                if ($onOutput !== null) {
                    clearstatcache(true, $out);
                    $size = (int) @filesize($out);
                    if ($size > $offset) {
                        $chunk = file_get_contents($out, false, null, $offset, $size - $offset);
                        $offset = $size;
                        $onOutput($chunk);
                    }
                }
                $status = proc_get_status($process);
                if (! $status['running']) {
                    $code = (int) $status['exitcode'];
                    proc_close($process);
                    // Drain output written between the last poll and process exit.
                    clearstatcache(true, $out);
                    clearstatcache(true, $err);
                    $size = (int) @filesize($out);
                    if ($size + (int) @filesize($err) > 8 * 1024 * 1024) {
                        throw new AiException(AiException::INVALID_OUTPUT, 'The provider output exceeded the safe transport limit.');
                    }
                    if ($onOutput !== null && $size > $offset) {
                        $onOutput(file_get_contents($out, false, null, $offset, $size - $offset));
                    }
                    break;
                }
                if (microtime(true) - $started > $timeout) {
                    self::stop($process, $status['pid']);
                    throw new AiException($this->timeoutCode, "The AI executable did not finish within {$timeout} seconds. Try a smaller request.");
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
            if (is_resource($process)) {
                $status = proc_get_status($process);
                if ($status['running']) {
                    self::stop($process, $status['pid']);
                } else {
                    proc_close($process);
                }
            }
            self::removeDir($io);
        }
    }

    /** Ends the CLI; Windows also terminates its process tree. Generation tools are disabled. */
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
