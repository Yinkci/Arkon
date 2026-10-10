<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER TABLE ai_connections ADD COLUMN check_requested_at timestamptz, ADD COLUMN checked_at timestamptz, ADD COLUMN test_requested_at timestamptz, ADD COLUMN test_result jsonb;');
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE ai_connections DROP COLUMN check_requested_at, DROP COLUMN checked_at, DROP COLUMN test_requested_at, DROP COLUMN test_result;');
    }
};
