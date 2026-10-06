<?php

namespace Tests\Support;

use App\Arkon\Database\Transactions;
use Closure;
use RuntimeException;

/**
 * Transactions that run completely and then fail to commit, like a connection
 * lost at COMMIT: the client sees an error, the server rolled back.
 */
class FailingCommitTransactions extends Transactions
{
    public function run(Closure $callback, ?string $isolation = null, bool $readOnly = false): mixed
    {
        return parent::run(function () use ($callback) {
            $callback();
            throw new RuntimeException('connection lost before commit');
        }, $isolation, $readOnly);
    }
}
