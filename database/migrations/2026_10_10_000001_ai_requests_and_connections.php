<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AI through Claude Code (subscription) instead of a billed API key.
 *
 * - ai_connections: revocable Arkon credentials for the local helper (runs the Claude Code
 *   CLI for the editor's AI panel) and for the MCP server (Claude Code in VS Code). Each is
 *   bound to one user and one site; only a SHA-256 hash of the token is stored. The helper
 *   reports its readiness (Claude Code version, auth mode, never credentials) in `status`.
 * - ai_proposals becomes the durable request record: queued → running (leased to one helper)
 *   → proposed / empty / failed / cancelled, then applied or discarded. A request key is
 *   bound to its page, prompt and base version (request_fingerprint). Earlier rows came from
 *   the removed API path and are kept, marked source 'api'.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        CREATE TABLE ai_connections (
            id uuid PRIMARY KEY,
            site_id uuid NOT NULL REFERENCES sites (id),
            user_id uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
            kind text NOT NULL CHECK (kind IN ('helper', 'mcp')),
            name text NOT NULL,
            token_hash text NOT NULL UNIQUE,
            status jsonb NOT NULL DEFAULT '{}',
            created_at timestamptz NOT NULL DEFAULT now(),
            last_seen_at timestamptz,
            revoked_at timestamptz
        );
        CREATE INDEX ai_connections_site_kind_idx ON ai_connections (site_id, kind) WHERE revoked_at IS NULL;

        ALTER TABLE ai_proposals DROP CONSTRAINT ai_proposals_status_check;
        ALTER TABLE ai_proposals ADD CONSTRAINT ai_proposals_status_check
            CHECK (status IN ('pending', 'queued', 'running', 'proposed', 'empty', 'failed', 'cancelled', 'applied', 'discarded'));
        ALTER TABLE ai_proposals ADD COLUMN source text NOT NULL DEFAULT 'api' CHECK (source IN ('api', 'panel', 'mcp'));
        ALTER TABLE ai_proposals ALTER COLUMN source DROP DEFAULT;
        ALTER TABLE ai_proposals ADD COLUMN request_fingerprint text;
        ALTER TABLE ai_proposals ADD COLUMN error_message text;
        ALTER TABLE ai_proposals ADD COLUMN attempts integer NOT NULL DEFAULT 0;
        ALTER TABLE ai_proposals ADD COLUMN lease_token uuid;
        ALTER TABLE ai_proposals ADD COLUMN lease_expires_at timestamptz;
        ALTER TABLE ai_proposals ADD COLUMN started_at timestamptz;
        ALTER TABLE ai_proposals ADD COLUMN connection_id uuid REFERENCES ai_connections (id);
        CREATE INDEX ai_proposals_queue_idx ON ai_proposals (site_id, status, created_at) WHERE status IN ('queued', 'running');
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
        DROP INDEX IF EXISTS ai_proposals_queue_idx;
        ALTER TABLE ai_proposals DROP COLUMN IF EXISTS connection_id, DROP COLUMN IF EXISTS started_at,
            DROP COLUMN IF EXISTS lease_expires_at, DROP COLUMN IF EXISTS lease_token, DROP COLUMN IF EXISTS attempts,
            DROP COLUMN IF EXISTS error_message, DROP COLUMN IF EXISTS request_fingerprint, DROP COLUMN IF EXISTS source;
        ALTER TABLE ai_proposals DROP CONSTRAINT ai_proposals_status_check;
        ALTER TABLE ai_proposals ADD CONSTRAINT ai_proposals_status_check
            CHECK (status IN ('pending', 'proposed', 'empty', 'failed', 'applied', 'discarded'));
        DROP TABLE IF EXISTS ai_connections;
        SQL);
    }
};
