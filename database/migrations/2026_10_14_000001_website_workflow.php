<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        ALTER TABLE publication_dependencies DROP CONSTRAINT publication_dependencies_kind_check;
        ALTER TABLE publication_dependencies ADD CHECK(kind IN ('tokens','component','form'));
        ALTER TABLE page_refreshes DROP CONSTRAINT page_refreshes_cause_kind_check;
        ALTER TABLE page_refreshes ADD CHECK(cause_kind IN ('tokens','component','form'));
        CREATE TABLE site_forms (
            id uuid PRIMARY KEY, site_id uuid NOT NULL REFERENCES sites(id), name text NOT NULL, draft jsonb NOT NULL,
            version integer NOT NULL CHECK(version>0), published_version integer, notification_email text,
            created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(site_id,id)
        );
        CREATE TABLE site_form_versions (
            site_id uuid NOT NULL, form_id uuid NOT NULL, version integer NOT NULL CHECK(version>0), definition jsonb NOT NULL,
            epoch bigint NOT NULL, created_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY(site_id,form_id,version),
            FOREIGN KEY(site_id,form_id) REFERENCES site_forms(site_id,id)
        );
        ALTER TABLE site_forms ADD FOREIGN KEY(site_id,id,published_version) REFERENCES site_form_versions(site_id,form_id,version);
        CREATE TABLE form_submissions (
            id uuid PRIMARY KEY, site_id uuid NOT NULL, form_id uuid NOT NULL, form_version integer NOT NULL,
            payload text NOT NULL, notification_status text NOT NULL DEFAULT 'disabled', created_at timestamptz NOT NULL DEFAULT now(),
            FOREIGN KEY(site_id,form_id,form_version) REFERENCES site_form_versions(site_id,form_id,version)
        );
        CREATE TABLE site_resource_requests (
            site_id uuid NOT NULL REFERENCES sites(id), request_key text NOT NULL, fingerprint text NOT NULL, result jsonb NOT NULL,
            created_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY(site_id,request_key)
        );
        CREATE TABLE site_website_settings (
            site_id uuid PRIMARY KEY REFERENCES sites(id), version integer NOT NULL DEFAULT 0,
            header_id uuid, footer_id uuid, form_id uuid,
            FOREIGN KEY(site_id,header_id) REFERENCES reusable_components(site_id,id),
            FOREIGN KEY(site_id,footer_id) REFERENCES reusable_components(site_id,id),
            FOREIGN KEY(site_id,form_id) REFERENCES site_forms(site_id,id)
        );
        ALTER TABLE ai_proposals ALTER COLUMN page_id DROP NOT NULL;
        ALTER TABLE ai_proposals ADD COLUMN scope text NOT NULL DEFAULT 'page' CHECK(scope IN ('page','website'));
        ALTER TABLE ai_proposals ADD COLUMN website_snapshot jsonb;
        ALTER TABLE ai_proposals ADD COLUMN website_result jsonb;
        CREATE TABLE website_applications (
            proposal_id uuid PRIMARY KEY REFERENCES ai_proposals(id), site_id uuid NOT NULL REFERENCES sites(id),
            result jsonb NOT NULL, created_at timestamptz NOT NULL DEFAULT now()
        );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE website_applications; DROP TABLE site_website_settings; ALTER TABLE ai_proposals DROP COLUMN website_result, DROP COLUMN website_snapshot, DROP COLUMN scope; DROP TABLE site_resource_requests; DROP TABLE form_submissions; ALTER TABLE site_forms DROP CONSTRAINT site_forms_site_id_id_published_version_fkey; DROP TABLE site_form_versions; DROP TABLE site_forms;');
    }
};
