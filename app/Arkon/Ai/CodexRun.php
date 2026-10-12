<?php

namespace App\Arkon\Ai;

use Illuminate\Support\Facades\Log;

/**
 * One started `codex exec` generation (see CodexCli::start).
 *
 * `codex exec --json` writes nothing while the model writes its reply (the message arrives whole at the end), so
 * the event output is silent for the whole reply and cannot tell slow from stuck. The model stream itself is
 * observed instead: Codex is run with a trace of its websocket frames on stderr (one line per received model
 * event), which this class only counts and measures — the frame contents (model text, and the prompt in sent
 * frames) are never stored or logged. From them:
 *
 *  - liveness: a run whose model stream has stopped for `stream_idle_seconds` is stalled and stopped;
 *  - runaway replies: a reply that degenerates into an endless run of whitespace (a failure mode of schema-
 *    constrained output, reproduced October 2026) is stopped as soon as the run exceeds `runaway_whitespace`
 *    characters, instead of streaming until the step's time limit;
 *  - activity: every few seconds of streaming is reported as progress.
 *
 * Without a frame trace (another transport or Codex version) only the startup watchdog and the step's time limit
 * apply. Two changes prevent the runaway: the reply schema is ordered with its long parts last (SchemaOrder, the
 * cause: a required property after a long list), and the reply is requested as compact JSON (COMPACT).
 */
final class CodexRun implements PendingRun
{
    /** Measured: the failing step ran away 3 of 3 times pretty-printed, and finished 4 of 4 times compact (23-31 s). */
    public const COMPACT = 'Write the JSON reply compactly on a single line: no indentation, no line breaks and no spaces between tokens.';

    private const FRAME = 'tungstenite::protocol: Received message ';

    private CliRun $run;

    private string $dir;

    private string $output;

    private RunTrace $trace;

    private string $buffer = '';

    private array $usage = [];

    private string $failure = '';

    private bool $turnStarted = false;

    private int $reconnects = 0;

    private bool $closed = false;

    private float $started;

    private int $frames = 0;

    private ?float $firstFrame = null;

    private ?float $lastFrame = null;

    private float $longestFrameGap = 0.0;

    private int $replyChars = 0;

    private int $whitespaceRun = 0;

    private int $longestWhitespaceRun = 0;

    private float $lastActivityEmit = 0.0;

    private ?AiException $verdict = null;

    public function __construct(CliProcess $process, private readonly AiRequest $request, callable $keepGoing, private readonly int $timeout, private readonly int $startupSeconds)
    {
        $this->dir = CliProcess::tempDir();
        $schema = $this->dir.'/schema.json';
        $this->output = $this->dir.'/result.json';
        // Long parts last, so the model can close the reply right after them (see SchemaOrder).
        file_put_contents($schema, json_encode(SchemaOrder::tailHeavy($request->schema), JSON_THROW_ON_ERROR));
        $this->trace = new RunTrace('codex', ['stage' => $request->label, 'instructionsChars' => strlen($request->instructions), 'promptChars' => strlen($request->prompt), 'schemaBytes' => filesize($schema)]);
        $this->emit('started');
        $this->started = microtime(true);
        $env = ['RUST_LOG' => 'warn,tungstenite::protocol=trace'.(filter_var(getenv('ARKON_AI_PROVIDER_DEBUG'), FILTER_VALIDATE_BOOL) ? ',codex_core=debug,codex_api=debug' : '')];
        try {
            $this->run = $process->start(CodexCli::isolatedArgs($schema, $this->output, imageGeneration: false), $this->dir, $request->instructions."\n\n".self::COMPACT."\n\n".$request->prompt, $timeout, $keepGoing,
                fn (string $chunk) => $this->read($chunk), fn (float $elapsed) => $this->watch($elapsed), fn (string $lines) => $this->frames($lines), $env);
        } catch (AiException $e) {
            CliProcess::removeDir($this->dir);
            throw $e;
        }
    }

    public function poll(): ?AiCompletion
    {
        try {
            $result = $this->run->poll();
        } catch (AiException $e) {
            $this->trace->finish($e->code(), null, self::withoutFrames($this->run->stderr()), [], $this->streamStats());
            $this->close();
            throw $this->explain($e);
        }
        if ($result === null) {
            return null;
        }
        $stderr = self::withoutFrames($result['err']);
        $this->trace->finish($result['code'] === 0 ? 'completed' : 'failed', $result['code'], $stderr, $this->usage, $this->streamStats());
        try {
            if ($result['code'] !== 0) {
                $text = $this->failure.' '.$result['out'].' '.$stderr;
                $code = match (true) {
                    preg_match('/not logged in|unauthoriz|authentication|401/i', $text) === 1 => 'PROVIDER_NOT_AUTHENTICATED',
                    preg_match('/rate.limit|usage.limit|quota/i', $text) === 1 => 'PROVIDER_LIMIT_REACHED',
                    preg_match('/invalid_json_schema|response_format|text\.format|invalid schema|output.?schema/i', $text) === 1 => 'PROVIDER_SCHEMA_REJECTED',
                    preg_match('/stream disconnected|reconnect|connection (reset|closed|error)|timed out|network/i', $text) === 1 => 'PROVIDER_CONNECTION_FAILED',
                    default => 'EXECUTION_FAILED',
                };
                // The provider's own words stay in the server log (bounded); the user gets a classified message.
                Log::warning('Codex run failed', ['stage' => $this->request->label, 'exit' => $result['code'], 'code' => $code, 'error' => mb_substr(trim($this->failure !== '' ? $this->failure : $stderr), 0, 600)]);
                throw new AiException($code, match ($code) {
                    'PROVIDER_NOT_AUTHENTICATED' => 'Codex needs sign-in. Run codex login.',
                    'PROVIDER_LIMIT_REACHED' => 'Your Codex usage limit has been reached.'.(preg_match('/try again at ([0-9]{1,2}:[0-9]{2}\s*[AP]M)/i', $text, $m) === 1 ? ' Codex says to try again at '.$m[1].'.' : ''),
                    'PROVIDER_SCHEMA_REJECTED' => 'Codex rejected the response format Arkon sent, so nothing was generated. This is an Arkon problem, not your connection; the details are in the server log.',
                    'PROVIDER_CONNECTION_FAILED' => 'Codex lost its connection to the model while '.$this->stageText().' and stopped after retrying. Nothing was applied; try again.',
                    default => 'Codex could not finish '.$this->stageText().'. The details are in the server log; check the Codex helper, then try again.',
                });
            }
            $text = is_file($this->output) ? (string) file_get_contents($this->output) : '';
            $data = json_decode($text, true);
            if (! is_array($data)) {
                throw new AiException(AiException::INVALID_OUTPUT, 'Codex returned no complete structured reply while '.$this->stageText().'.');
            }
            $this->emit('completed', ['usage' => $this->usage]);

            return new AiCompletion($data, $text, 'codex', $this->usage);
        } finally {
            $this->close();
        }
    }

    public function close(): void
    {
        if (! $this->closed) {
            $this->closed = true;
            $this->run->close();
            CliProcess::removeDir($this->dir);
        }
    }

    public function diagnostics(): array
    {
        return ['stage' => $this->request->label, 'elapsed' => round($this->trace->elapsed(), 1), 'firstOutput' => $this->trace->firstOutput() === null ? null : round($this->trace->firstOutput(), 1),
            'silence' => round($this->trace->silence(), 1), 'turnStarted' => $this->turnStarted, 'reconnects' => $this->reconnects, 'pid' => $this->run->pid(), ...$this->streamStats()];
    }

    /** What the model stream did, for the run log and diagnostics (counts and timings only). */
    private function streamStats(): array
    {
        if ($this->frames === 0) {
            return ['traced' => false];
        }

        return ['traced' => true, 'frames' => $this->frames, 'firstFrame' => round((float) $this->firstFrame, 1), 'longestFrameGap' => round($this->longestFrameGap, 1),
            'sinceLastFrame' => round($this->elapsed() - (float) $this->lastFrame, 1), 'replyChars' => $this->replyChars, 'longestWhitespaceRun' => $this->longestWhitespaceRun];
    }

    private function elapsed(): float
    {
        return microtime(true) - $this->started;
    }

    /** Received model frames on stderr: counted and timed; only the length and whitespace of reply text are looked at. */
    private function frames(string $lines): void
    {
        $now = $this->elapsed();
        foreach (explode("\n", $lines) as $line) {
            $at = strpos($line, self::FRAME);
            if ($at === false) {
                continue;
            }
            $this->frames++;
            $this->firstFrame ??= $now;
            if ($this->lastFrame !== null) {
                $this->longestFrameGap = max($this->longestFrameGap, $now - $this->lastFrame);
            }
            $this->lastFrame = $now;
            if (! str_contains($line, '"type":"response.output_text.delta"')) {
                continue;
            }
            $event = json_decode(substr($line, $at + strlen(self::FRAME)), true);
            $delta = is_array($event) && is_string($event['delta'] ?? null) ? $event['delta'] : '';
            $this->replyChars += strlen($delta);
            if ($delta !== '' && trim($delta) === '') {
                $this->whitespaceRun += strlen($delta);
            } elseif ($delta !== '') {
                $this->whitespaceRun = strlen($delta) - strlen(rtrim($delta));
            }
            $this->longestWhitespaceRun = max($this->longestWhitespaceRun, $this->whitespaceRun);
            if ($this->verdict === null && $this->replyChars > (int) config('arkon.ai.runaway_chars', 20000)) {
                $this->verdict = new AiException('PROVIDER_RUNAWAY_OUTPUT', 'Codex’s reply for '.$this->stageText().' went wrong: it kept growing far beyond a normal reply ('.number_format($this->replyChars).' characters). It was stopped after '.round($now).' seconds; nothing was applied.');
            }
            if ($this->verdict === null && $this->whitespaceRun > (int) config('arkon.ai.runaway_whitespace', 400)) {
                $this->verdict = new AiException('PROVIDER_RUNAWAY_OUTPUT', 'Codex’s reply for '.$this->stageText().' went wrong: the model kept writing empty space instead of finishing. It was stopped after '.round($now).' seconds; nothing was applied.');
            }
        }
        if ($this->frames > 0 && $now - $this->lastActivityEmit >= 2) {
            // Real model activity, reported as progress (the event output is silent while the reply is written).
            $this->lastActivityEmit = $now;
            $this->emit('output', ['stage' => 'generating']);
        }
    }

    private function read(string $chunk): void
    {
        $this->buffer .= $chunk;
        while (($end = strpos($this->buffer, "\n")) !== false) {
            $event = json_decode(substr($this->buffer, 0, $end), true);
            $this->buffer = substr($this->buffer, $end + 1);
            if (! is_array($event)) {
                continue;
            }
            $this->trace->event($event);
            $type = $event['type'] ?? '';
            $item = is_array($event['item'] ?? null) ? $event['item'] : [];
            if ($type === 'turn.started') {
                $this->turnStarted = true;
                $this->emit('output', ['stage' => 'generating']);
            } elseif ($type === 'turn.completed') {
                foreach (['input_tokens', 'cached_input_tokens', 'output_tokens', 'reasoning_output_tokens'] as $key) {
                    if (isset($event['usage'][$key]) && is_int($event['usage'][$key])) {
                        $this->usage[$key] = $event['usage'][$key];
                    }
                }
            } elseif (($item['type'] ?? '') === 'error' && preg_match('/reconnect|stream disconnected|retrying/i', (string) ($item['message'] ?? '')) === 1) {
                // A dropped model stream that Codex retries: visible progress, never a silent wait.
                $this->reconnects++;
                $this->emit('reconnecting', ['attempt' => $this->reconnects]);
            } elseif (in_array($type, ['item.started', 'item.completed'], true)) {
                $this->emit('output', ['stage' => 'generating']);
            } elseif (in_array($type, ['turn.failed', 'error'], true)) {
                $this->failure = (string) ($event['error']['message'] ?? $event['message'] ?? '');
                if ($type === 'turn.failed') {
                    $this->emit('failed');
                }
            }
        }
    }

    /**
     * Three separate limits besides the step's own ceiling: Codex never starting its model turn, a model stream
     * that has stopped (no frame for `stream_idle_seconds`), and a runaway reply (detected while reading frames).
     */
    private function watch(float $elapsed): ?AiException
    {
        if ($this->verdict !== null) {
            return $this->verdict;
        }
        if (! $this->turnStarted && $elapsed > $this->startupSeconds) {
            return new AiException('PROVIDER_NOT_STARTED', 'Codex did not start working within '.$this->startupSeconds.' seconds (no model turn began). Nothing was generated; check the Codex helper and your connection, then try again.');
        }
        $idle = (int) config('arkon.ai.stream_idle_seconds', 60);
        if ($this->lastFrame !== null && $this->elapsed() - $this->lastFrame > $idle) {
            return new AiException('PROVIDER_STREAM_STALLED', 'The model stopped responding while '.$this->stageText().' (nothing received for '.$idle.' seconds). It was stopped; nothing was applied.');
        }

        return null;
    }

    private function explain(AiException $e): AiException
    {
        if ($e->code() !== 'EXECUTION_TIMEOUT') {
            return $e;
        }

        return new AiException('EXECUTION_TIMEOUT', 'Codex did not finish '.$this->stageText().' within '.$this->timeout.' seconds'
            .($this->frames > 0 ? ' although the model was still writing' : ($this->turnStarted ? ' (no reply from the model)' : ''))
            .'. It was stopped; nothing was applied.');
    }

    /**
     * Stderr without the frame trace: a traced frame is dropped with its continuation lines (sent frames are
     * printed with their payload, the prompt, as hex on the lines that follow). Model text and the prompt never
     * reach a log; Codex's own warnings and errors are kept.
     */
    public static function withoutFrames(string $stderr): string
    {
        $kept = [];
        $inFrame = false;
        foreach (explode("\n", $stderr) as $line) {
            if (preg_match('/^\d{4}-\d\d-\d\dT[\d:.]+Z\s/', $line) === 1) {
                $inFrame = str_contains($line, 'tungstenite::');
            }
            if (! $inFrame && ! str_contains($line, 'tungstenite::')) {
                $kept[] = $line;
            }
        }

        return implode("\n", $kept);
    }

    private function stageText(): string
    {
        return $this->request->label === 'generation' ? 'this request' : $this->request->label;
    }

    private function emit(string $type, array $data = []): void
    {
        if ($this->request->onEvent) {
            ($this->request->onEvent)(['provider' => 'codex', 'type' => $type, 'stage' => $this->request->label, ...$data]);
        }
    }
}
