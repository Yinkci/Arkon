<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
 CREATE TABLE site_menus (id uuid PRIMARY KEY, site_id uuid NOT NULL REFERENCES sites(id), name text NOT NULL, draft jsonb NOT NULL, version integer NOT NULL CHECK(version>0), published_version integer, created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(site_id,id));
 CREATE TABLE site_menu_versions (site_id uuid NOT NULL, menu_id uuid NOT NULL, version integer NOT NULL CHECK(version>0), definition jsonb NOT NULL, epoch bigint NOT NULL, created_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY(site_id,menu_id,version), FOREIGN KEY(site_id,menu_id) REFERENCES site_menus(site_id,id));
 ALTER TABLE site_menus ADD FOREIGN KEY(site_id,id,published_version) REFERENCES site_menu_versions(site_id,menu_id,version);
 ALTER TABLE site_website_settings ADD COLUMN main_menu_id uuid, ADD FOREIGN KEY(site_id,main_menu_id) REFERENCES site_menus(site_id,id);
 ALTER TABLE publication_dependencies DROP CONSTRAINT publication_dependencies_kind_check;
 ALTER TABLE publication_dependencies ADD CHECK(kind IN ('tokens','component','form','menu'));
 ALTER TABLE page_refreshes DROP CONSTRAINT page_refreshes_cause_kind_check;
 ALTER TABLE page_refreshes ADD CHECK(cause_kind IN ('tokens','component','form','menu'));
 SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Navigation history must be preserved; restore a verified backup to roll back.');
    }
};
