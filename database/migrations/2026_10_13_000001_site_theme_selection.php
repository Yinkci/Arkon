<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        CREATE TABLE site_theme_sets (
            site_id uuid PRIMARY KEY REFERENCES sites(id),
            version integer NOT NULL DEFAULT 1 CHECK(version > 0),
            draft jsonb,
            published_version integer,
            updated_by uuid REFERENCES users(id) ON DELETE SET NULL,
            updated_at timestamptz NOT NULL DEFAULT now()
        );
        CREATE TABLE site_theme_versions (
            site_id uuid NOT NULL REFERENCES sites(id),
            version integer NOT NULL CHECK(version > 0),
            selection jsonb,
            published_by uuid REFERENCES users(id) ON DELETE SET NULL,
            epoch bigint NOT NULL,
            created_at timestamptz NOT NULL DEFAULT now(),
            PRIMARY KEY(site_id, version)
        );
        ALTER TABLE site_theme_sets ADD CONSTRAINT site_theme_live_fk
            FOREIGN KEY(site_id, published_version) REFERENCES site_theme_versions(site_id, version);
        CREATE TABLE site_theme_requests (
            site_id uuid NOT NULL REFERENCES sites(id),
            kind text NOT NULL CHECK(kind IN ('activate', 'publish')),
            request_key text NOT NULL,
            fingerprint text NOT NULL,
            result jsonb NOT NULL,
            created_at timestamptz NOT NULL DEFAULT now(),
            PRIMARY KEY(site_id, kind, request_key)
        );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS site_theme_requests; DROP TABLE IF EXISTS site_theme_sets; DROP TABLE IF EXISTS site_theme_versions;');
    }
};
