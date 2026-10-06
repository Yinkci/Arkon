<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retry-safe saves and publishes, and the first dependency table.
 *
 * - page_drafts remembers the key and fingerprint of the last applied save, so a
 *   retried save is recognised instead of re-applied.
 * - publications store the fingerprint of the request their idempotency key was
 *   used for. Rows created before this have none and can never be replayed.
 * - publication_media records the media each publication uses. Public media
 *   delivery depends on it; existing publications are linked by the data upgrade
 *   "2026-10-06-backfill-publication-media" that arkon:migrate runs afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        ALTER TABLE page_drafts ADD COLUMN last_save_key text;
        ALTER TABLE page_drafts ADD COLUMN last_save_fingerprint text;

        ALTER TABLE publications ADD COLUMN request_fingerprint text NOT NULL DEFAULT 'legacy';
        ALTER TABLE publications ALTER COLUMN request_fingerprint DROP DEFAULT;
        ALTER TABLE publications ADD CONSTRAINT publications_site_id_key UNIQUE (site_id, id);

        CREATE TABLE publication_media (
            site_id uuid NOT NULL,
            publication_id uuid NOT NULL,
            asset_id uuid NOT NULL,
            PRIMARY KEY (publication_id, asset_id),
            CONSTRAINT publication_media_publication_fk FOREIGN KEY (site_id, publication_id)
                REFERENCES publications (site_id, id),
            CONSTRAINT publication_media_asset_fk FOREIGN KEY (site_id, asset_id)
                REFERENCES media_assets (site_id, id)
        );
        CREATE INDEX publication_media_site_asset_idx ON publication_media (site_id, asset_id);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
        DROP TABLE IF EXISTS publication_media;
        ALTER TABLE publications DROP CONSTRAINT IF EXISTS publications_site_id_key;
        ALTER TABLE publications DROP COLUMN IF EXISTS request_fingerprint;
        ALTER TABLE page_drafts DROP COLUMN IF EXISTS last_save_fingerprint;
        ALTER TABLE page_drafts DROP COLUMN IF EXISTS last_save_key;
        SQL);
    }
};
