<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The page model as of the reference project's first migration: sites, members,
 * the versioned page resource (page → draft → revisions → publications → live),
 * media and the audit log.
 *
 * Every link between tenant tables is a composite foreign key that includes
 * site_id, so a row of one site can never reference a row of another.
 * Later migrations evolve this exactly like the reference history did, so the
 * upgrade path (and its data backfill) is exercised by tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        CREATE TABLE sites (
            id uuid PRIMARY KEY,
            name text NOT NULL,
            settings jsonb NOT NULL DEFAULT '{}'::jsonb,
            -- Incremented under a row lock by every change to published state. Orders publications.
            publish_epoch bigint NOT NULL DEFAULT 0,
            created_at timestamptz NOT NULL DEFAULT now()
        );

        CREATE TABLE site_domains (
            hostname text PRIMARY KEY,
            site_id uuid NOT NULL REFERENCES sites (id) ON DELETE CASCADE
        );

        CREATE TABLE site_members (
            site_id uuid NOT NULL REFERENCES sites (id) ON DELETE CASCADE,
            user_id uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
            role text NOT NULL,
            created_at timestamptz NOT NULL DEFAULT now(),
            PRIMARY KEY (site_id, user_id),
            CONSTRAINT site_members_role_check CHECK (role IN ('owner', 'admin', 'editor', 'viewer'))
        );

        CREATE TABLE pages (
            id uuid PRIMARY KEY,
            site_id uuid NOT NULL REFERENCES sites (id),
            -- Draft URL path and title. The live values belong to the published revision.
            path text NOT NULL,
            title text NOT NULL,
            created_by uuid REFERENCES users (id) ON DELETE SET NULL,
            created_at timestamptz NOT NULL DEFAULT now(),
            updated_at timestamptz NOT NULL DEFAULT now(),
            deleted_at timestamptz,
            CONSTRAINT pages_site_id_id_key UNIQUE (site_id, id)
        );
        CREATE UNIQUE INDEX pages_site_path_active_idx ON pages (site_id, path) WHERE deleted_at IS NULL;

        CREATE TABLE page_revisions (
            id uuid PRIMARY KEY,
            site_id uuid NOT NULL,
            page_id uuid NOT NULL,
            number integer NOT NULL,
            document jsonb NOT NULL,
            schema_version integer NOT NULL,
            source text NOT NULL,
            author_id uuid REFERENCES users (id) ON DELETE SET NULL,
            message text NOT NULL DEFAULT '',
            created_at timestamptz NOT NULL DEFAULT now(),
            CONSTRAINT page_revisions_page_fk FOREIGN KEY (site_id, page_id) REFERENCES pages (site_id, id),
            CONSTRAINT page_revisions_page_number_key UNIQUE (page_id, number),
            CONSTRAINT page_revisions_site_page_id_key UNIQUE (site_id, page_id, id),
            CONSTRAINT page_revisions_source_check CHECK (source IN ('human', 'ai', 'system'))
        );

        CREATE TABLE page_drafts (
            page_id uuid PRIMARY KEY,
            site_id uuid NOT NULL,
            document jsonb NOT NULL,
            -- Optimistic-concurrency token. Every write names the version it was based on.
            version integer NOT NULL DEFAULT 1,
            -- The revision equal to this draft as of checkpoint_version (reused when publishing).
            checkpoint_revision_id uuid,
            checkpoint_version integer,
            updated_by uuid REFERENCES users (id) ON DELETE SET NULL,
            updated_at timestamptz NOT NULL DEFAULT now(),
            CONSTRAINT page_drafts_page_fk FOREIGN KEY (site_id, page_id) REFERENCES pages (site_id, id),
            CONSTRAINT page_drafts_revision_fk FOREIGN KEY (site_id, page_id, checkpoint_revision_id)
                REFERENCES page_revisions (site_id, page_id, id)
        );

        CREATE TABLE publications (
            id uuid PRIMARY KEY,
            site_id uuid NOT NULL,
            page_id uuid NOT NULL,
            revision_id uuid NOT NULL,
            path text NOT NULL,
            html text NOT NULL,
            epoch bigint NOT NULL,
            idempotency_key text NOT NULL,
            published_by uuid REFERENCES users (id) ON DELETE SET NULL,
            created_at timestamptz NOT NULL DEFAULT now(),
            CONSTRAINT publications_revision_fk FOREIGN KEY (site_id, page_id, revision_id)
                REFERENCES page_revisions (site_id, page_id, id),
            CONSTRAINT publications_site_idempotency_key UNIQUE (site_id, idempotency_key),
            CONSTRAINT publications_site_page_id_key UNIQUE (site_id, page_id, id)
        );

        CREATE TABLE live_pages (
            page_id uuid PRIMARY KEY,
            site_id uuid NOT NULL,
            path text NOT NULL,
            publication_id uuid NOT NULL,
            epoch bigint NOT NULL,
            updated_at timestamptz NOT NULL DEFAULT now(),
            CONSTRAINT live_pages_page_fk FOREIGN KEY (site_id, page_id) REFERENCES pages (site_id, id),
            CONSTRAINT live_pages_publication_fk FOREIGN KEY (site_id, page_id, publication_id)
                REFERENCES publications (site_id, page_id, id),
            CONSTRAINT live_pages_site_path_key UNIQUE (site_id, path)
        );

        -- Immutable: replacing a file creates a new asset. Alt text lives on the usage (page document).
        CREATE TABLE media_assets (
            id uuid PRIMARY KEY,
            site_id uuid NOT NULL REFERENCES sites (id),
            storage_key text NOT NULL UNIQUE,
            mime text NOT NULL,
            bytes integer NOT NULL,
            width integer NOT NULL,
            height integer NOT NULL,
            original_name text NOT NULL,
            created_by uuid REFERENCES users (id) ON DELETE SET NULL,
            created_at timestamptz NOT NULL DEFAULT now(),
            CONSTRAINT media_assets_site_id_id_key UNIQUE (site_id, id)
        );

        CREATE TABLE audit_logs (
            id uuid PRIMARY KEY,
            site_id uuid REFERENCES sites (id),
            actor_user_id uuid REFERENCES users (id) ON DELETE SET NULL,
            actor_via text NOT NULL,
            action text NOT NULL,
            target_kind text NOT NULL,
            target_id text NOT NULL,
            data jsonb NOT NULL DEFAULT '{}'::jsonb,
            created_at timestamptz NOT NULL DEFAULT now()
        );
        CREATE INDEX audit_logs_site_created_idx ON audit_logs (site_id, created_at);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
        DROP TABLE IF EXISTS audit_logs, media_assets, live_pages, publications, page_drafts,
            page_revisions, pages, site_members, site_domains, sites;
        SQL);
    }
};
