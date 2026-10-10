<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Content types, taxonomies and API access tokens.
 *
 * - pages.kind: a post is a page of kind "post" (same drafts, revisions, publishing, Trash,
 *   redirects, SEO and renderer). Kinds and taxonomies are named in code
 *   (App\Arkon\Content\ContentTypes, Taxonomies); the checks only bound their format, so a new
 *   type or taxonomy needs no schema change. A page's kind never changes.
 * - excerpt and featured_media_id are the draft's post details, like pages.title is the draft
 *   title. Publishing records what went live in publications.content_meta (with the terms), so
 *   the live site and the API only change when something is published.
 * - first_published_at: the stable "published" date of a post, set by its first publication.
 * - terms / page_terms: categories, tags and future taxonomies, and the draft's assignments.
 * - api_tokens: personal access tokens for the public API (/api/v1). One user on one site with
 *   explicit scopes; only a SHA-256 hash is stored, the token is shown once.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
  ALTER TABLE pages ADD COLUMN kind text NOT NULL DEFAULT 'page' CHECK (kind ~ '^[a-z][a-z0-9_]{0,31}$');
  ALTER TABLE pages ADD COLUMN excerpt text NOT NULL DEFAULT '' CHECK (char_length(excerpt) <= 1000);
  ALTER TABLE pages ADD COLUMN featured_media_id uuid;
  ALTER TABLE pages ADD CONSTRAINT pages_featured_media_fkey FOREIGN KEY (site_id, featured_media_id) REFERENCES media_assets (site_id, id);
  ALTER TABLE pages ADD COLUMN first_published_at timestamptz;
  CREATE INDEX pages_site_kind_idx ON pages (site_id, kind) WHERE deleted_at IS NULL;

  ALTER TABLE publications ADD COLUMN content_meta jsonb;

  CREATE TABLE terms (
      id uuid PRIMARY KEY,
      site_id uuid NOT NULL REFERENCES sites (id),
      taxonomy text NOT NULL CHECK (taxonomy ~ '^[a-z][a-z0-9_]{0,31}$'),
      name text NOT NULL CHECK (char_length(name) BETWEEN 1 AND 100),
      slug text NOT NULL CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$' AND char_length(slug) <= 100),
      description text NOT NULL DEFAULT '' CHECK (char_length(description) <= 1000),
      parent_id uuid,
      created_at timestamptz NOT NULL DEFAULT now(),
      updated_at timestamptz NOT NULL DEFAULT now(),
      CONSTRAINT terms_site_id_id_key UNIQUE (site_id, id),
      CONSTRAINT terms_site_taxonomy_slug_key UNIQUE (site_id, taxonomy, slug),
      CONSTRAINT terms_parent_fkey FOREIGN KEY (site_id, parent_id) REFERENCES terms (site_id, id),
      CONSTRAINT terms_not_own_parent CHECK (parent_id IS NULL OR parent_id <> id)
  );

  CREATE TABLE page_terms (
      site_id uuid NOT NULL,
      page_id uuid NOT NULL,
      term_id uuid NOT NULL,
      PRIMARY KEY (page_id, term_id),
      FOREIGN KEY (site_id, page_id) REFERENCES pages (site_id, id),
      FOREIGN KEY (site_id, term_id) REFERENCES terms (site_id, id) ON DELETE CASCADE
  );
  CREATE INDEX page_terms_term_idx ON page_terms (term_id);

  CREATE TABLE api_tokens (
      id uuid PRIMARY KEY,
      site_id uuid NOT NULL REFERENCES sites (id),
      user_id uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
      name text NOT NULL CHECK (char_length(name) BETWEEN 1 AND 100),
      token_hash text NOT NULL UNIQUE,
      token_hint text NOT NULL,
      scopes text[] NOT NULL,
      created_at timestamptz NOT NULL DEFAULT now(),
      last_used_at timestamptz,
      expires_at timestamptz,
      revoked_at timestamptz
  );
  CREATE INDEX api_tokens_site_user_idx ON api_tokens (site_id, user_id);
  SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
  DROP TABLE IF EXISTS api_tokens;
  DROP TABLE IF EXISTS page_terms;
  DROP TABLE IF EXISTS terms;
  ALTER TABLE publications DROP COLUMN IF EXISTS content_meta;
  DROP INDEX IF EXISTS pages_site_kind_idx;
  ALTER TABLE pages DROP CONSTRAINT IF EXISTS pages_featured_media_fkey;
  ALTER TABLE pages DROP COLUMN IF EXISTS first_published_at, DROP COLUMN IF EXISTS featured_media_id, DROP COLUMN IF EXISTS excerpt, DROP COLUMN IF EXISTS kind;
  SQL);
    }
};
