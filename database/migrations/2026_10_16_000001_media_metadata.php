<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE media_assets ADD COLUMN title text NOT NULL DEFAULT '', ADD COLUMN alt_text text NOT NULL DEFAULT '', ADD COLUMN caption text NOT NULL DEFAULT '', ADD COLUMN description text NOT NULL DEFAULT '', ADD COLUMN metadata_version integer NOT NULL DEFAULT 1 CHECK(metadata_version>0), ADD COLUMN archived_at timestamptz;
UPDATE media_assets SET title=original_name;
CREATE INDEX media_library_order ON media_assets(site_id,created_at DESC,id) WHERE archived_at IS NULL;
CREATE TABLE media_metadata_saves (site_id uuid NOT NULL, asset_id uuid NOT NULL, request_key text NOT NULL, fingerprint text NOT NULL, result jsonb NOT NULL, created_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY(site_id,asset_id,request_key), FOREIGN KEY(site_id,asset_id) REFERENCES media_assets(site_id,id));
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Media metadata history must be preserved.');
    }
};
