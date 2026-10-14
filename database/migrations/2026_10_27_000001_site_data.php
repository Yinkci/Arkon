<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Site data: the structured values that belong to the website itself rather than to any one
 * entry — the company's phone number, its address, its opening hours.
 *
 * It is not a content type holding a single pretend entry. A site has exactly one set of these
 * values, so it is one row per site, and the fields it carries are decided the same way an
 * entry's are: by the field groups whose location rules say they belong here.
 *
 * - site_data.values: the values themselves, in the same shape an entry stores, so the one field
 *   engine validates and reads them.
 * - version: every save names the version it was based on, so two people editing at once cannot
 *   quietly overwrite each other.
 * - site_data_revisions: what the values were before each save. Site data shows on every page of
 *   a website, so a change nobody meant to make should be visible and recoverable.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
  CREATE TABLE site_data (
      site_id uuid PRIMARY KEY REFERENCES sites (id),
      values jsonb NOT NULL DEFAULT '{}'::jsonb,
      version integer NOT NULL DEFAULT 1 CHECK (version > 0),
      updated_by uuid REFERENCES users (id) ON DELETE SET NULL,
      updated_at timestamptz NOT NULL DEFAULT now()
  );

  CREATE TABLE site_data_revisions (
      id uuid PRIMARY KEY,
      site_id uuid NOT NULL REFERENCES sites (id),
      version integer NOT NULL CHECK (version > 0),
      values jsonb NOT NULL,
      author_id uuid REFERENCES users (id) ON DELETE SET NULL,
      created_at timestamptz NOT NULL DEFAULT now(),
      CONSTRAINT site_data_revisions_site_version_key UNIQUE (site_id, version)
  );
  CREATE INDEX site_data_revisions_site_idx ON site_data_revisions (site_id, created_at DESC);
  SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
  DROP TABLE IF EXISTS site_data_revisions;
  DROP TABLE IF EXISTS site_data;
  SQL);
    }
};
