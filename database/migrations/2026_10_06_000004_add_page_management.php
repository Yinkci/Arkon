<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Page management: create requests with retry keys, soft delete by whom,
 * revisions that snapshot their title and URL, and page-targeted redirects.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        ALTER TABLE pages ADD COLUMN request_key text;
        ALTER TABLE pages ADD COLUMN deleted_by uuid REFERENCES users (id) ON DELETE SET NULL;
        ALTER TABLE pages ADD CONSTRAINT pages_site_request_key UNIQUE (site_id, request_key);

        ALTER TABLE page_revisions ADD COLUMN title text;
        ALTER TABLE page_revisions ADD COLUMN path text;
        -- Before this migration a page title and path could never change, so the page row
        -- holds exactly the values every existing revision was created (and published) with.
        UPDATE page_revisions r SET title = p.title, path = p.path
            FROM pages p WHERE p.site_id = r.site_id AND p.id = r.page_id;
        ALTER TABLE page_revisions ALTER COLUMN title SET NOT NULL;
        ALTER TABLE page_revisions ALTER COLUMN path SET NOT NULL;

        -- Old URLs of published pages. A redirect points at a page, not at a path: it resolves
        -- to the page's current live path, so chains collapse and loops cannot exist.
        CREATE TABLE redirects (
            site_id uuid NOT NULL,
            from_path text NOT NULL,
            page_id uuid NOT NULL,
            created_at timestamptz NOT NULL DEFAULT now(),
            PRIMARY KEY (site_id, from_path),
            CONSTRAINT redirects_page_fk FOREIGN KEY (site_id, page_id) REFERENCES pages (site_id, id)
        );
        CREATE INDEX redirects_site_page_idx ON redirects (site_id, page_id);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
        DROP TABLE IF EXISTS redirects;
        ALTER TABLE page_revisions DROP COLUMN IF EXISTS path;
        ALTER TABLE page_revisions DROP COLUMN IF EXISTS title;
        ALTER TABLE pages DROP CONSTRAINT IF EXISTS pages_site_request_key;
        ALTER TABLE pages DROP COLUMN IF EXISTS deleted_by;
        ALTER TABLE pages DROP COLUMN IF EXISTS request_key;
        SQL);
    }
};
