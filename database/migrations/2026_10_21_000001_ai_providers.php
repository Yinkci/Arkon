<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE ai_connections ADD COLUMN provider text NOT NULL DEFAULT 'claude-code' CHECK (provider IN ('claude-code','codex'));
CREATE TABLE ai_preferences (site_id uuid NOT NULL, user_id uuid NOT NULL, provider text NOT NULL CHECK (provider IN ('claude-code','codex')), PRIMARY KEY(site_id,user_id), FOREIGN KEY(site_id,user_id) REFERENCES site_members(site_id,user_id) ON DELETE CASCADE);
CREATE INDEX ai_connections_provider_idx ON ai_connections(site_id,provider,kind) WHERE revoked_at IS NULL;
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE ai_preferences; DROP INDEX ai_connections_provider_idx; ALTER TABLE ai_connections DROP COLUMN provider;');
    }
};
