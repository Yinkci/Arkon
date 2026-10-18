<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Field values become part of the publishing pipeline, and the query index learns which kind
 * each row belongs to.
 *
 * - content_field_index.kind: a value marked unique is unique among entries of one content
 *   type, not across the whole site (a Service and a Project may both use code "X1"), and a
 *   schema change can drop the rows of one type without touching another's. Backfilled from
 *   pages; rows of relationships nested in groups, repeaters and sections are added by the
 *   data upgrade that re-projects every entry.
 * - page_revisions.fields_recorded: whether the revision captured the entry's field values.
 *   Revisions written before this never did (their `fields` is the column default), so a reader
 *   of live values can tell "this entry had no values" from "this revision predates values"
 *   and fall back to the draft only for the latter. The column is added, never back-filled:
 *   history stays exactly as it was recorded.
 * - publication_dependencies / page_refreshes: a live page can depend on site data (it binds
 *   a site value) or on content (it lists entries, or reads through a relationship), so a
 *   change to either re-renders it like a token or component change already does.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
  ALTER TABLE content_field_index ADD COLUMN kind text;
  UPDATE content_field_index i SET kind = p.kind FROM pages p WHERE p.id = i.page_id;
  DELETE FROM content_field_index WHERE kind IS NULL;
  ALTER TABLE content_field_index ALTER COLUMN kind SET NOT NULL;
  ALTER TABLE content_field_index ADD CONSTRAINT content_field_index_kind_check CHECK (kind ~ '^[a-z][a-z0-9_]{0,31}$');
  DROP INDEX IF EXISTS content_field_index_unique_idx;
  CREATE UNIQUE INDEX content_field_index_unique_idx ON content_field_index (site_id, kind, field_key, text_value)
      WHERE unique_value AND text_value IS NOT NULL;
  CREATE INDEX content_field_index_kind_idx ON content_field_index (site_id, kind, field_key);

  ALTER TABLE page_revisions ADD COLUMN fields_recorded boolean NOT NULL DEFAULT false;

  ALTER TABLE publication_dependencies DROP CONSTRAINT publication_dependencies_kind_check;
  ALTER TABLE publication_dependencies ADD CONSTRAINT publication_dependencies_kind_check CHECK (kind IN ('tokens','component','form','menu','seo','site_data','content'));
  ALTER TABLE page_refreshes DROP CONSTRAINT page_refreshes_cause_kind_check;
  ALTER TABLE page_refreshes ADD CONSTRAINT page_refreshes_cause_kind_check CHECK (cause_kind IN ('tokens','component','form','menu','seo','site_data','content'));
  SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
  DELETE FROM page_refreshes WHERE cause_kind IN ('site_data','content');
  ALTER TABLE page_refreshes DROP CONSTRAINT page_refreshes_cause_kind_check;
  ALTER TABLE page_refreshes ADD CONSTRAINT page_refreshes_cause_kind_check CHECK (cause_kind IN ('tokens','component','form','menu','seo'));
  DELETE FROM publication_dependencies WHERE kind IN ('site_data','content');
  ALTER TABLE publication_dependencies DROP CONSTRAINT publication_dependencies_kind_check;
  ALTER TABLE publication_dependencies ADD CONSTRAINT publication_dependencies_kind_check CHECK (kind IN ('tokens','component','form','menu','seo'));

  ALTER TABLE page_revisions DROP COLUMN IF EXISTS fields_recorded;

  DROP INDEX IF EXISTS content_field_index_kind_idx;
  DROP INDEX IF EXISTS content_field_index_unique_idx;
  CREATE UNIQUE INDEX content_field_index_unique_idx ON content_field_index (site_id, field_key, text_value)
      WHERE unique_value AND text_value IS NOT NULL;
  ALTER TABLE content_field_index DROP COLUMN IF EXISTS kind;
  SQL);
    }
};
