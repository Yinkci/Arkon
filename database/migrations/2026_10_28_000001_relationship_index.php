<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Relationship lookups, both ways.
 *
 * content_field_index already had an index on ref_id alone, which answers "what refers to this
 * entry?" — the question a delete confirmation asks. Filtering the other way round ("properties
 * whose agent is Jane") names a field as well, so it gets the composite index: without it that
 * query reads every reference on the site and discards the ones from other fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
  CREATE INDEX content_field_index_ref_field_idx ON content_field_index (site_id, field_key, ref_id) WHERE ref_id IS NOT NULL;
  SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP INDEX IF EXISTS content_field_index_ref_field_idx;');
    }
};
