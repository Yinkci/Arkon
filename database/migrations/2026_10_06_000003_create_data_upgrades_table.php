<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bookkeeping for data upgrades: backfills that need component logic and run
 * after the SQL migrations (App\Arkon\Upgrades\DataUpgrades). Written only by
 * arkon:migrate as the schema owner; the runtime role has no access at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        CREATE TABLE data_upgrades (
            name text PRIMARY KEY,
            completed_at timestamptz NOT NULL DEFAULT now(),
            details jsonb NOT NULL DEFAULT '{}'::jsonb
        );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS data_upgrades');
    }
};
