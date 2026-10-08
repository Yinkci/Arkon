<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Shared design resources and responsive images.
 *
 * - Design tokens: one editable draft per site (site_token_sets) and immutable published
 *   versions (site_token_versions). Pages render with the published version only.
 * - Reusable components: an editable draft document per component and immutable
 *   published versions. Page instances render the published version only.
 * - publication_dependencies: which token version and component versions each
 *   publication was rendered with (also in its render_inputs, for reproduction), so
 *   publishing a resource finds the live pages that use it.
 * - page_refreshes: one row per live page to re-render after a resource was published,
 *   with status (pending / done / skipped / failed), attempts and the last error.
 * - media_variants: resized WebP copies of an image, private exactly when their
 *   original is (delivery checks the original asset).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        CREATE TABLE site_token_sets (
            site_id uuid PRIMARY KEY REFERENCES sites (id),
            draft jsonb NOT NULL DEFAULT '{}',
            version integer NOT NULL DEFAULT 1,
            published_version integer,
            last_save_key text,
            last_save_fingerprint text,
            updated_by uuid REFERENCES users (id) ON DELETE SET NULL,
            updated_at timestamptz NOT NULL DEFAULT now()
        );

        CREATE TABLE site_token_versions (
            site_id uuid NOT NULL REFERENCES sites (id),
            version integer NOT NULL CHECK (version > 0),
            tokens jsonb NOT NULL,
            idempotency_key text NOT NULL,
            request_fingerprint text NOT NULL,
            published_by uuid REFERENCES users (id) ON DELETE SET NULL,
            created_at timestamptz NOT NULL DEFAULT now(),
            PRIMARY KEY (site_id, version),
            CONSTRAINT site_token_versions_key UNIQUE (site_id, idempotency_key)
        );

        CREATE TABLE reusable_components (
            id uuid PRIMARY KEY,
            site_id uuid NOT NULL REFERENCES sites (id),
            name text NOT NULL CHECK (length(name) BETWEEN 1 AND 80),
            draft jsonb NOT NULL,
            version integer NOT NULL DEFAULT 1,
            published_version integer,
            last_save_key text,
            last_save_fingerprint text,
            created_by uuid REFERENCES users (id) ON DELETE SET NULL,
            updated_by uuid REFERENCES users (id) ON DELETE SET NULL,
            created_at timestamptz NOT NULL DEFAULT now(),
            updated_at timestamptz NOT NULL DEFAULT now(),
            CONSTRAINT reusable_components_site_id_key UNIQUE (site_id, id)
        );

        CREATE TABLE reusable_component_versions (
            site_id uuid NOT NULL,
            component_id uuid NOT NULL,
            version integer NOT NULL CHECK (version > 0),
            name text NOT NULL,
            document jsonb NOT NULL,
            idempotency_key text NOT NULL,
            request_fingerprint text NOT NULL,
            published_by uuid REFERENCES users (id) ON DELETE SET NULL,
            created_at timestamptz NOT NULL DEFAULT now(),
            PRIMARY KEY (component_id, version),
            CONSTRAINT reusable_component_versions_component_fk FOREIGN KEY (site_id, component_id) REFERENCES reusable_components (site_id, id),
            CONSTRAINT reusable_component_versions_key UNIQUE (site_id, idempotency_key)
        );

        CREATE TABLE publication_dependencies (
            publication_id uuid NOT NULL,
            site_id uuid NOT NULL,
            page_id uuid NOT NULL,
            kind text NOT NULL CHECK (kind IN ('tokens', 'component')),
            -- The component id; for tokens the site id, so (kind, resource_id) is always set.
            resource_id uuid NOT NULL,
            -- The version used; 0 = the token defaults (nothing published yet).
            version integer NOT NULL CHECK (version >= 0),
            PRIMARY KEY (publication_id, kind, resource_id),
            CONSTRAINT publication_dependencies_publication_fk FOREIGN KEY (site_id, page_id, publication_id) REFERENCES publications (site_id, page_id, id)
        );
        CREATE INDEX publication_dependencies_resource_idx ON publication_dependencies (site_id, kind, resource_id);

        CREATE TABLE page_refreshes (
            id uuid PRIMARY KEY,
            site_id uuid NOT NULL REFERENCES sites (id),
            page_id uuid NOT NULL,
            cause_kind text NOT NULL CHECK (cause_kind IN ('tokens', 'component')),
            cause_id uuid NOT NULL,
            cause_version integer NOT NULL,
            status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'done', 'skipped', 'failed')),
            attempts integer NOT NULL DEFAULT 0,
            last_error text,
            publication_id uuid,
            created_at timestamptz NOT NULL DEFAULT now(),
            updated_at timestamptz NOT NULL DEFAULT now(),
            CONSTRAINT page_refreshes_page_fk FOREIGN KEY (site_id, page_id) REFERENCES pages (site_id, id),
            CONSTRAINT page_refreshes_cause_key UNIQUE (site_id, page_id, cause_kind, cause_id, cause_version)
        );
        CREATE INDEX page_refreshes_open_idx ON page_refreshes (site_id, status, created_at) WHERE status IN ('pending', 'failed');

        CREATE TABLE media_variants (
            asset_id uuid NOT NULL REFERENCES media_assets (id),
            site_id uuid NOT NULL REFERENCES sites (id),
            format text NOT NULL CHECK (format IN ('webp')),
            width integer NOT NULL CHECK (width > 0),
            height integer NOT NULL CHECK (height > 0),
            bytes integer NOT NULL,
            storage_key text NOT NULL UNIQUE,
            created_at timestamptz NOT NULL DEFAULT now(),
            PRIMARY KEY (asset_id, format, width)
        );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
        DROP TABLE IF EXISTS media_variants;
        DROP TABLE IF EXISTS page_refreshes;
        DROP TABLE IF EXISTS publication_dependencies;
        DROP TABLE IF EXISTS reusable_component_versions;
        DROP TABLE IF EXISTS reusable_components;
        DROP TABLE IF EXISTS site_token_versions;
        DROP TABLE IF EXISTS site_token_sets;
        SQL);
    }
};
