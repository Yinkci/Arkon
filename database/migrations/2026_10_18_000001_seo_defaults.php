<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
  CREATE TABLE site_seo_sets(site_id uuid PRIMARY KEY REFERENCES sites(id), draft jsonb NOT NULL DEFAULT '{}', version integer NOT NULL DEFAULT 1, published_version integer, last_save_key text, last_save_fingerprint text, updated_at timestamptz NOT NULL DEFAULT now());
  CREATE TABLE site_seo_versions(site_id uuid NOT NULL REFERENCES sites(id),version integer NOT NULL,settings jsonb NOT NULL,idempotency_key text NOT NULL,request_fingerprint text NOT NULL,published_by uuid REFERENCES users(id),created_at timestamptz NOT NULL DEFAULT now(),PRIMARY KEY(site_id,version),UNIQUE(site_id,idempotency_key));
  ALTER TABLE site_seo_sets ADD FOREIGN KEY(site_id,published_version) REFERENCES site_seo_versions(site_id,version);
  ALTER TABLE publication_dependencies DROP CONSTRAINT publication_dependencies_kind_check;
  ALTER TABLE publication_dependencies ADD CHECK(kind IN ('tokens','component','form','menu','seo'));
  ALTER TABLE page_refreshes DROP CONSTRAINT page_refreshes_cause_kind_check;
  ALTER TABLE page_refreshes ADD CHECK(cause_kind IN ('tokens','component','form','menu','seo'));
  SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('SEO history is retained; use a forward migration.');
    }
};
