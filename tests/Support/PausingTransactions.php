<?php

namespace Tests\Support;

use App\Arkon\Database\Transactions;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Runs the transaction's work, then holds its locks (does not commit) until
 * another database session is blocked waiting on them. That forces an exact
 * interleaving: "B waits behind A, then A commits". Fails loudly instead of
 * passing by luck if no waiter ever shows up.
 */
class PausingTransactions extends Transactions
{
    public bool $sawWaiter = false;

    public function __construct(private readonly float $timeoutSeconds = 30.0) {}

    public function run(Closure $callback, ?string $isolation = null, bool $readOnly = false): mixed
    {
        return parent::run(function () use ($callback) {
            $result = $callback();
            $deadline = microtime(true) + $this->timeoutSeconds;
            while (microtime(true) < $deadline) {
                // Activity statistics are snapshotted once per transaction; take a fresh look each time.
                DB::select('select pg_stat_clear_snapshot()');
                $waiters = DB::selectOne('select count(*) as n from pg_stat_activity where pg_backend_pid() = any(pg_blocking_pids(pid))')->n;
                if ($waiters > 0) {
                    $this->sawWaiter = true;
                    usleep(50_000); // let the waiter settle on the lock before we release it

                    return $result;
                }
                usleep(20_000);
            }
            throw new RuntimeException('No other session blocked on this transaction; the interleaving was not reproduced');
        }, $isolation, $readOnly);
    }
}
