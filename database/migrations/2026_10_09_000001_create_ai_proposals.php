<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AI proposals: one row per prompt. A proposal records the page and draft version it
 * was based on and the exact operations it would apply, so applying it is checked
 * against what was proposed (and refused when stale). Rows also carry token usage,
 * which the daily cost limits count. A pending row exists while the provider is being
 * asked, so concurrent requests count towards the limits too.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        CREATE TABLE ai_proposals (
            id uuid PRIMARY KEY,
            site_id uuid NOT NULL REFERENCES sites (id),
            page_id uuid NOT NULL,
            created_by uuid REFERENCES users (id) ON DELETE SET NULL,
            request_key text NOT NULL,
            prompt text NOT NULL,
            base_version integer NOT NULL,
            status text NOT NULL CHECK (status IN ('pending', 'proposed', 'empty', 'failed', 'applied', 'discarded')),
            summary text,
            details jsonb NOT NULL DEFAULT '{}',
            operations jsonb,
            operations_fingerprint text,
            error_code text,
            provider text NOT NULL,
            model text NOT NULL,
            input_tokens integer NOT NULL DEFAULT 0,
            output_tokens integer NOT NULL DEFAULT 0,
            applied_revision_id uuid,
            created_at timestamptz NOT NULL DEFAULT now(),
            resolved_at timestamptz,
            CONSTRAINT ai_proposals_page_fk FOREIGN KEY (site_id, page_id) REFERENCES pages (site_id, id),
            CONSTRAINT ai_proposals_request_key UNIQUE (site_id, created_by, request_key)
        );
        CREATE INDEX ai_proposals_site_created_idx ON ai_proposals (site_id, created_at);
        CREATE INDEX ai_proposals_user_created_idx ON ai_proposals (created_by, created_at);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS ai_proposals');
    }
};
