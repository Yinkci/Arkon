<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TABLE website_contexts (id uuid PRIMARY KEY, site_id uuid NOT NULL REFERENCES sites(id), user_id uuid NOT NULL REFERENCES users(id), snapshot jsonb NOT NULL, created_at timestamptz NOT NULL DEFAULT now()); CREATE INDEX website_contexts_user_created ON website_contexts(site_id,user_id,created_at);');
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE website_contexts;');
    }
};
