<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER TABLE ai_proposals ADD COLUMN activity text, ADD COLUMN heartbeat_at timestamptz, ADD COLUMN validation_issues jsonb, ADD COLUMN website_candidate jsonb');
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE ai_proposals DROP COLUMN activity, DROP COLUMN heartbeat_at, DROP COLUMN validation_issues, DROP COLUMN website_candidate');
    }
};
