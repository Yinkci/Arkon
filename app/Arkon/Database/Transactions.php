<?php

namespace App\Arkon\Database;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Every service transaction goes through here, so tests can simulate a
 * connection lost at COMMIT (the client sees an error, the server rolled back).
 */
class Transactions
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Closure $callback, ?string $isolation = null, bool $readOnly = false): mixed
    {
        return DB::transaction(function () use ($callback, $isolation, $readOnly) {
            if ($isolation !== null || $readOnly) {
                $mode = trim(($isolation !== null ? "ISOLATION LEVEL {$isolation}" : '').($readOnly ? ', READ ONLY' : ''), ', ');
                DB::statement("SET TRANSACTION {$mode}");
            }

            return $callback();
        });
    }
}
