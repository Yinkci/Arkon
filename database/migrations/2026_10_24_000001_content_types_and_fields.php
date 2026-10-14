<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Content types a site defines for itself, and the typed values of their entries.
 *
 * An entry is a page (pages.kind), as a post already is: the same drafts, revisions,
 * publishing, Trash, redirects, SEO and renderer. This adds what a defined type needs.
 *
 * - content_types: one site's definition of a kind (labels, routing, fields). The key is the
 *   stable identifier used by the API, templates and AI; the label may change freely.
 *   schema_version rises whenever the fields change, so consumers can tell definitions apart.
 * - page_drafts.fields / page_revisions.fields: the entry's own field values, beside the
 *   builder document, so they version, publish and restore through the existing pipeline.
 *   They are the source of truth; publications.content_meta still records what went live.
 * - content_field_index: those values projected into typed columns on every draft save, so
 *   admin lists and queries filter and sort in the database instead of reading JSON. Derived,
 *   never authoritative: deleting a page drops its rows, and a reprojection rebuilds them.
 *   unique_value marks the rows of fields the definition marks unique, which the partial
 *   unique index then enforces for the whole site.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
  CREATE TABLE content_types (
      id uuid PRIMARY KEY,
      site_id uuid NOT NULL REFERENCES sites (id),
      key text NOT NULL CHECK (key ~ '^[a-z][a-z0-9_]{0,31}$'),
      definition jsonb NOT NULL,
      schema_version integer NOT NULL DEFAULT 1 CHECK (schema_version > 0),
      active boolean NOT NULL DEFAULT true,
      created_by uuid REFERENCES users (id) ON DELETE SET NULL,
      created_at timestamptz NOT NULL DEFAULT now(),
      updated_at timestamptz NOT NULL DEFAULT now(),
      CONSTRAINT content_types_site_key_key UNIQUE (site_id, key),
      CONSTRAINT content_types_site_id_id_key UNIQUE (site_id, id)
  );

  ALTER TABLE page_drafts ADD COLUMN fields jsonb NOT NULL DEFAULT '{}'::jsonb;
  ALTER TABLE page_revisions ADD COLUMN fields jsonb NOT NULL DEFAULT '{}'::jsonb;

  CREATE TABLE content_field_index (
      site_id uuid NOT NULL,
      page_id uuid NOT NULL,
      field_key text NOT NULL CHECK (field_key ~ '^[a-z][a-z0-9_]{0,39}$'),
      -- A field with several values (multi-select) keeps one row per value.
      position integer NOT NULL DEFAULT 0 CHECK (position >= 0),
      text_value text,
      num_value numeric,
      bool_value boolean,
      date_value timestamptz,
      ref_id uuid,
      unique_value boolean NOT NULL DEFAULT false,
      PRIMARY KEY (page_id, field_key, position),
      CONSTRAINT content_field_index_page_fk FOREIGN KEY (site_id, page_id)
          REFERENCES pages (site_id, id) ON DELETE CASCADE
  );
  CREATE INDEX content_field_index_text_idx ON content_field_index (site_id, field_key, text_value);
  CREATE INDEX content_field_index_num_idx ON content_field_index (site_id, field_key, num_value);
  CREATE INDEX content_field_index_date_idx ON content_field_index (site_id, field_key, date_value);
  CREATE INDEX content_field_index_ref_idx ON content_field_index (ref_id) WHERE ref_id IS NOT NULL;
  -- Fields the definition marks unique: one entry per value for the whole site.
  CREATE UNIQUE INDEX content_field_index_unique_idx ON content_field_index (site_id, field_key, text_value)
      WHERE unique_value AND text_value IS NOT NULL;
  SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
  DROP TABLE IF EXISTS content_field_index;
  ALTER TABLE page_revisions DROP COLUMN IF EXISTS fields;
  ALTER TABLE page_drafts DROP COLUMN IF EXISTS fields;
  DROP TABLE IF EXISTS content_types;
  SQL);
    }
};
