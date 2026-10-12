<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Staged website generation (plan → shared layout and pages in parallel → checks):
 * - progress: the stages of a run with their state and timing, for the review screen;
 * - stage_cache: replies of finished stages, so a resumed request never generates them again;
 * - diagnostics: what the last run did (stages, timings, provider activity, failure), never prompt text or secrets.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER TABLE ai_proposals ADD COLUMN progress jsonb, ADD COLUMN stage_cache jsonb, ADD COLUMN diagnostics jsonb');
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE ai_proposals DROP COLUMN progress, DROP COLUMN stage_cache, DROP COLUMN diagnostics');
    }
};
