<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pages remember what their create request asked for (a fingerprint of the
 * normalised title and path), so a retried create is matched against the
 * original intent rather than the page's current, possibly renamed, title and
 * URL. Existing pages with a request key are backfilled from their first
 * revision by the data upgrade "2026-10-08-backfill-page-request-fingerprints".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER TABLE pages ADD COLUMN request_fingerprint text');
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE pages DROP COLUMN IF EXISTS request_fingerprint');
    }
};
