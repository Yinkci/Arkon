<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Trash for pages and forms reuses the existing soft-delete columns (pages.deleted_at,
 * site_forms.archived_at). "Delete permanently" sets purged_at: the item leaves the Trash and can
 * no longer be restored, while the append-only history it points to (revisions, publications,
 * form versions) stays intact for audit and reproduction.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
  ALTER TABLE pages ADD COLUMN purged_at timestamptz, ADD CONSTRAINT pages_purged_in_trash CHECK (purged_at IS NULL OR deleted_at IS NOT NULL);
  ALTER TABLE site_forms ADD COLUMN purged_at timestamptz, ADD CONSTRAINT site_forms_purged_in_trash CHECK (purged_at IS NULL OR archived_at IS NOT NULL);
  CREATE INDEX pages_site_trash_idx ON pages (site_id, deleted_at DESC) WHERE deleted_at IS NOT NULL AND purged_at IS NULL;
  SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Trash state is retained; use a forward migration.');
    }
};
