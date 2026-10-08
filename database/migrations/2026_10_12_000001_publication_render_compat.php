<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Render compatibility records: which renderer build produced a publication when its
 * recorded renderer version name alone does not say so (publications made while that
 * version was still in development). Append-only, one row per publication, written only
 * by `arkon:record-render-compat` after the named build reproduced the stored HTML byte
 * for byte. Publications and their render inputs are never changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        CREATE TABLE publication_render_compat (
            publication_id uuid PRIMARY KEY,
            site_id uuid NOT NULL,
            page_id uuid NOT NULL,
            renderer text NOT NULL CHECK (renderer IN ('arkon-php-2-pre-basis')),
            reason text NOT NULL CHECK (length(reason) BETWEEN 1 AND 500),
            created_at timestamptz NOT NULL DEFAULT now(),
            CONSTRAINT publication_render_compat_publication_fk FOREIGN KEY (site_id, page_id, publication_id) REFERENCES publications (site_id, page_id, id)
        );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS publication_render_compat');
    }
};
