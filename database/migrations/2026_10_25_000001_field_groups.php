<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Field groups: a reusable set of fields that several things can carry.
 *
 * A group holds only schema. Values stay with whatever owns them — an entry keeps its values in
 * page_drafts.fields exactly as before, whether a field reached it directly from its content
 * type or through a group — so assigning a group to a second type copies no data and a group
 * never owns a value.
 *
 * - field_groups.fields: the same field definitions a content type stores, validated by the one
 *   field engine (App\Arkon\Content\FieldSchema). schema_version rises when the fields change.
 * - field_group_assignments: where a group applies. target_kind is bounded by format, not by a
 *   list, exactly as pages.kind is: a new kind of target (site data, a template) needs no schema
 *   change. Location rules will grow from this table rather than replace it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
  CREATE TABLE field_groups (
      id uuid PRIMARY KEY,
      site_id uuid NOT NULL REFERENCES sites (id),
      key text NOT NULL CHECK (key ~ '^[a-z][a-z0-9_]{0,31}$'),
      name text NOT NULL CHECK (char_length(name) BETWEEN 1 AND 60),
      description text NOT NULL DEFAULT '' CHECK (char_length(description) <= 500),
      fields jsonb NOT NULL DEFAULT '[]'::jsonb,
      schema_version integer NOT NULL DEFAULT 1 CHECK (schema_version > 0),
      created_by uuid REFERENCES users (id) ON DELETE SET NULL,
      created_at timestamptz NOT NULL DEFAULT now(),
      updated_at timestamptz NOT NULL DEFAULT now(),
      CONSTRAINT field_groups_site_key_key UNIQUE (site_id, key),
      CONSTRAINT field_groups_site_id_id_key UNIQUE (site_id, id)
  );

  CREATE TABLE field_group_assignments (
      site_id uuid NOT NULL,
      group_id uuid NOT NULL,
      target_kind text NOT NULL CHECK (target_kind ~ '^[a-z][a-z0-9_]{0,31}$'),
      target_key text NOT NULL CHECK (target_key ~ '^[a-z][a-z0-9_]{0,63}$'),
      -- Where the group sits among the others on the same target.
      position integer NOT NULL DEFAULT 0 CHECK (position >= 0),
      created_at timestamptz NOT NULL DEFAULT now(),
      PRIMARY KEY (group_id, target_kind, target_key),
      CONSTRAINT field_group_assignments_group_fk FOREIGN KEY (site_id, group_id)
          REFERENCES field_groups (site_id, id) ON DELETE CASCADE
  );
  CREATE INDEX field_group_assignments_target_idx ON field_group_assignments (site_id, target_kind, target_key);
  SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
  DROP TABLE IF EXISTS field_group_assignments;
  DROP TABLE IF EXISTS field_groups;
  SQL);
    }
};
