<?php

namespace Tests\Support;

use App\Arkon\Database\Transactions;
use App\Arkon\Sites\SiteContext;

/**
 * Forces "the worker's call waits behind `$first`, then `$first` commits".
 *
 * 1. The worker process boots and connects (ready barrier, no startup timer).
 * 2. `$first` runs here and takes its locks.
 * 3. Only then is the worker released; `$first` keeps its locks until the
 *    database shows the worker blocked behind it (`pg_blocking_pids`), then commits.
 *
 * The test fails if the worker never waited, so a pass cannot come from luck.
 */
trait Interleaves
{
    /** @return array{0: mixed, 1: array} [result of $first, worker result] */
    protected function interleaveWith(callable $first, string $call, SiteContext $ctx, array $input): array
    {
        $parallel = (new Parallel)->add($call, $ctx, $input)->ready();
        $pausing = new PausingTransactions(whileHolding: fn () => $parallel->release());
        $this->app->instance(Transactions::class, $pausing);
        try {
            $firstResult = $first();
        } finally {
            $this->app->instance(Transactions::class, new Transactions);
        }
        $this->assertTrue($pausing->sawWaiter, 'the second operation really waited behind the first');

        return [$firstResult, $parallel->wait()[0]];
    }
}
