<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Items published before first_published_at existed get the time of their first publication,
 * so their "published" date is stable from now on. Safe to run again (only fills NULLs).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('UPDATE pages SET first_published_at = f.first FROM (SELECT page_id, min(created_at) AS first FROM publications GROUP BY page_id) f
            WHERE f.page_id = pages.id AND pages.first_published_at IS NULL');
    }

    public function down(): void {}
};
