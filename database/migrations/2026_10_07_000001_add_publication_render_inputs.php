<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every publication records what it was rendered from besides its immutable
 * revision: renderer version, component versions, site name/language and the
 * metadata of each image. Together they make a publication auditable and
 * reproducible. Publications made before this have NULL (inputs not recorded).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER TABLE publications ADD COLUMN render_inputs jsonb');
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE publications DROP COLUMN IF EXISTS render_inputs');
    }
};
