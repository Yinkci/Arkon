<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        ALTER TABLE site_forms ADD COLUMN updated_at timestamptz NOT NULL DEFAULT now(), ADD COLUMN archived_at timestamptz;
        UPDATE site_forms SET updated_at=created_at;
        ALTER TABLE form_submissions ADD COLUMN read_at timestamptz, ADD COLUMN starred boolean NOT NULL DEFAULT false,
          ADD COLUMN status text NOT NULL DEFAULT 'inbox' CHECK(status IN ('inbox','spam','trash')),
          ADD COLUMN search_tokens text[] NOT NULL DEFAULT '{}', ADD COLUMN notification_results jsonb NOT NULL DEFAULT '[]', ADD COLUMN request_key text, ADD COLUMN request_fingerprint text;
        CREATE INDEX forms_entry_page ON form_submissions(site_id,form_id,status,created_at DESC,id);
        CREATE INDEX forms_entry_search ON form_submissions USING gin(search_tokens);
        CREATE UNIQUE INDEX forms_submission_request ON form_submissions(site_id,form_id,request_key) WHERE request_key IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forms data is retained; use a forward migration.');
    }
};
