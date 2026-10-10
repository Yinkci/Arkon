<?php

namespace App\Arkon\Ai;

use App\Arkon\Sites\Membership;
use App\Arkon\Sites\Permissions;
use App\Arkon\Support\Json;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The local helper's work, one step at a time (the artisan command loops over tick()):
 * report readiness, recover interrupted runs, lease the next queued request of the helper's
 * site, run the selected provider for it, and keep the connection alive while the provider works.
 */
final class AiHelper
{
    private ?RunnerStatus $status = null;

    private float $checkedAt = 0;

    public function __construct(
        private readonly ProposalService $proposals,
        private readonly AiConnections $connections,
        private readonly AiRunner $runner,
        private readonly int $recheckSeconds = 60,
    ) {}

    /** Provider readiness, checked at start and then every minute (or after a login/limit failure). */
    public function status(bool $force = false): RunnerStatus
    {
        if ($force || $this->status === null || microtime(true) - $this->checkedAt > $this->recheckSeconds) {
            $this->status = $this->runner->check();
            $this->checkedAt = microtime(true);
        }

        return $this->status;
    }

    /**
     * @param  callable(string): void  $say  progress lines for the terminal
     * @return string|null what happened (null when idle)
     */
    public function tick(object $connection, ?callable $say = null): ?string
    {
        $say ??= fn () => null;
        // A direct tick must be as safe as the command's token resolution.
        $active = DB::table('ai_connections')->where('id', $connection->id)->whereNull('revoked_at')->first();
        if (! $active || ! Permissions::allows(app(Membership::class)->roleOf($active->site_id, $active->user_id), 'page.edit')) {
            return null;
        }
        $connection = $active;
        $force = $connection->check_requested_at && (! $connection->checked_at || strtotime($connection->check_requested_at) > strtotime($connection->checked_at));
        $status = $this->status($force);
        if ($force) {
            DB::table('ai_connections')->where('id', $connection->id)->whereNull('revoked_at')->update(['checked_at' => DB::raw('now()')]);
        }
        if ($connection->test_requested_at && $connection->test_result === null) {
            $lock = 'arkon-ai-test:'.$connection->id;
            if (! DB::selectOne('SELECT pg_try_advisory_lock(hashtextextended(?,0)) AS acquired', [$lock])->acquired) {
                return null;
            }
            try {
                $current = DB::table('ai_connections')->where('id', $connection->id)->whereNull('revoked_at')->first();
                if (! $current || $current->test_result !== null || $current->test_requested_at !== $connection->test_requested_at) {
                    return null;
                }
                $started = microtime(true);
                try {
                    $status = $this->status(force: true);
                    if (! $status->ready) {
                        throw new AiException($status->code ?? 'PROVIDER_UNAVAILABLE', $status->message);
                    }
                    $reply = $this->runner->run(new AiRequest('Return only the requested JSON. No tools are needed.', 'Return {"ok":true}.', ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']], 'required' => ['ok'], 'additionalProperties' => false]), function () use ($connection, $status) {
                        $row = DB::table('ai_connections')->where('id', $connection->id)->whereNull('revoked_at')->first();
                        if (! $row || ! Permissions::allows(app(Membership::class)->roleOf($row->site_id, $row->user_id), 'page.edit')) {
                            return false;
                        }
                        $this->connections->heartbeat($connection->id, $status);

                        return true;
                    });
                    $test = ['ok' => ($reply->output['ok'] ?? false) === true, 'message' => ($reply->output['ok'] ?? false) === true ? 'The provider responded successfully.' : 'The provider returned an unexpected response.'];
                } catch (AiException $error) {
                    $test = ['ok' => false, 'code' => $error->code(), 'message' => $error->getMessage()];
                }
                $test['durationMs'] = (int) ((microtime(true) - $started) * 1000);
                DB::table('ai_connections')->where('id', $connection->id)->whereNull('revoked_at')->where('test_requested_at', $connection->test_requested_at)->update(['test_result' => Json::encode($test)]);
                $this->connections->heartbeat($connection->id, $status);

                return 'connection_test';
            } finally {
                DB::select('SELECT pg_advisory_unlock(hashtextextended(?,0))', [$lock]);
            }
        }
        $this->connections->heartbeat($connection->id, $status);
        if (! $status->ready) {
            return null;
        }
        $claim = $this->proposals->claimNext($connection);
        if ($claim === null) {
            return null;
        }
        // Native login can change after a readiness report. Recheck without a model call before execution.
        $status = $this->status(force: true);
        $this->connections->heartbeat($connection->id, $status);
        if (! $status->ready) {
            app(ProposalLedger::class)->failRun($claim->id, $claim->lease_token, 'PROVIDER_UNAVAILABLE', $status->message);

            return 'failed';
        }
        $started = microtime(true);
        Log::info('AI execution started', ['provider' => $claim->provider, 'request' => $claim->id]);
        $say("Working on request {$claim->id} (attempt {$claim->attempts}): ".mb_substr(preg_replace('/\s+/', ' ', $claim->prompt), 0, 80));
        $outcome = $this->proposals->execute($claim, $this->runner, fn () => $this->connections->heartbeat($connection->id, $status));
        Log::info('AI execution finished', ['provider' => $claim->provider, 'request' => $claim->id, 'outcome' => $outcome, 'durationMs' => (int) ((microtime(true) - $started) * 1000)]);
        $say("Request {$claim->id}: {$outcome}");
        if ($outcome === 'failed') {
            $this->status(force: true); // a login or limit problem shows in the panel right away
            $this->connections->heartbeat($connection->id, $this->status);
        }

        return $outcome;
    }
}
