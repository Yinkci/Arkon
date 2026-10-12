<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Website revisions and generated images.
 *
 * - ai_proposals.parent_id / revision: a website proposal made by "Request changes" records the
 *   proposal it revises and its target ({scope, pageId, nodeId, baseline}). The parent row is never
 *   changed by a revision.
 * - ai_image_jobs: one generated image for one placement of one proposal. asset_id is reserved when
 *   the proposal is made and is the media asset id the image is imported under, so a placement is
 *   generated at most once (UNIQUE) and a confirmation retry finds the finished asset instead of
 *   generating a replacement. Jobs are leased to one helper like proposal runs; an interrupted job
 *   fails and only an explicit retry starts it again.
 * - media_assets.source / provenance: where an image came from (an upload or an AI-generated image,
 *   with provider, proposal and the structured request).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE ai_proposals ADD COLUMN parent_id uuid REFERENCES ai_proposals(id), ADD COLUMN revision jsonb;
CREATE INDEX ai_proposals_parent_idx ON ai_proposals (parent_id) WHERE parent_id IS NOT NULL;
ALTER TABLE media_assets ADD COLUMN source text NOT NULL DEFAULT 'upload' CHECK (source IN ('upload', 'ai-generated')), ADD COLUMN provenance jsonb;
CREATE TABLE ai_image_jobs (
    id uuid PRIMARY KEY,
    site_id uuid NOT NULL REFERENCES sites(id),
    proposal_id uuid NOT NULL REFERENCES ai_proposals(id),
    asset_id uuid NOT NULL UNIQUE,
    created_by uuid NOT NULL,
    provider text NOT NULL CHECK (provider IN ('claude-code', 'codex')),
    spec jsonb NOT NULL,
    status text NOT NULL CHECK (status IN ('queued', 'running', 'completed', 'failed', 'cancelled')),
    activity text,
    attempts integer NOT NULL DEFAULT 0,
    lease_token uuid,
    lease_expires_at timestamptz,
    heartbeat_at timestamptz,
    connection_id uuid REFERENCES ai_connections(id),
    error_code text,
    error_message text,
    retry_key text,
    created_at timestamptz NOT NULL DEFAULT now(),
    started_at timestamptz,
    completed_at timestamptz
);
CREATE INDEX ai_image_jobs_queue_idx ON ai_image_jobs (site_id, status, created_at) WHERE status IN ('queued', 'running');
CREATE INDEX ai_image_jobs_proposal_idx ON ai_image_jobs (proposal_id);
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE ai_image_jobs; ALTER TABLE media_assets DROP COLUMN provenance, DROP COLUMN source; DROP INDEX ai_proposals_parent_idx; ALTER TABLE ai_proposals DROP COLUMN revision, DROP COLUMN parent_id;');
    }
};
