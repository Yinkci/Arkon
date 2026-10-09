<?php

namespace Tests\Feature;

use App\Arkon\Components\Factories;
use App\Arkon\Database\MigrationConfig;
use App\Arkon\Database\SchemaMigrator;
use App\Arkon\Media\MediaService;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use App\Arkon\Upgrades\DataUpgrades;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Upgrade regression (port of packages/core/test/upgrade.test.ts): a database
 * created and populated before publication_media existed (foundation schema
 * only) must keep serving its live images after upgrading, while images that
 * were never published stay private. Ends with a fully migrated database.
 */
class UpgradeTest extends DatabaseTestCase
{
    private const FIRST_MIGRATIONS = ['0001_01_01_000000_create_users_table.php', '2026_10_06_000001_create_arkon_foundation_tables.php'];

    public function test_upgrading_a_pre_publication_media_database(): void
    {
        $ids = array_combine(
            ['user', 'siteA', 'siteB', 'pageA', 'pageB', 'liveAsset', 'draftAsset', 'revA1', 'revA2', 'revB1', 'pubA', 'pubB'],
            array_map(fn () => Uuid::v7(), range(1, 12)),
        );

        // 1. Rebuild the test database at the foundation schema only.
        $owner = MigrationConfig::connect('test');
        DB::connection($owner)->unprepared('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
        MigrationConfig::disconnect();
        app(SchemaMigrator::class)->migrate('test', array_map(fn ($f) => database_path("migrations/{$f}"), self::FIRST_MIGRATIONS), runUpgrades: false);

        // 2. Data as the pre-upgrade application wrote it: no fingerprints, no media links, no titles on revisions.
        $owner = MigrationConfig::connect('test');
        $db = DB::connection($owner);
        $this->assertSame(2, $db->table('migrations')->count());
        $liveDoc = Factories::pageDocument([Factories::heroNode(['heading' => 'Live', 'image' => ['assetId' => $ids['liveAsset'], 'alt' => 'Live image']])]);
        $draftDoc = Factories::pageDocument([Factories::heroNode(['heading' => 'Draft', 'image' => ['assetId' => $ids['draftAsset'], 'alt' => 'Draft image']])]);
        // Site B's published revision (corruptly) references site A's image: it must not be linked.
        $foreignDoc = Factories::pageDocument([Factories::heroNode(['heading' => 'B', 'image' => ['assetId' => $ids['liveAsset'], 'alt' => 'x']])]);

        $db->table('users')->insert(['id' => $ids['user'], 'name' => 'Legacy', 'email' => 'legacy@test.local', 'password' => 'x']);
        foreach ([[$ids['siteA'], 'legacy-a.test'], [$ids['siteB'], 'legacy-b.test']] as [$site, $host]) {
            $db->table('sites')->insert(['id' => $site, 'name' => 'Legacy site', 'publish_epoch' => 1]);
            $db->table('site_domains')->insert(['hostname' => $host, 'site_id' => $site]);
            $db->table('site_members')->insert(['site_id' => $site, 'user_id' => $ids['user'], 'role' => 'owner']);
        }
        foreach ([$ids['liveAsset'], $ids['draftAsset']] as $asset) {
            $db->table('media_assets')->insert([
                'id' => $asset, 'site_id' => $ids['siteA'], 'storage_key' => "{$asset}.png", 'mime' => 'image/png',
                'bytes' => 68, 'width' => 1, 'height' => 1, 'original_name' => 'x.png',
            ]);
        }
        $page = function (string $pageId, string $site, array $revisions, array $draft, int $version) use ($db) {
            $db->table('pages')->insert(['id' => $pageId, 'site_id' => $site, 'path' => '/', 'title' => 'Home']);
            foreach ($revisions as [$rev, $number, $doc]) {
                $db->table('page_revisions')->insert([
                    'id' => $rev, 'site_id' => $site, 'page_id' => $pageId, 'number' => $number, 'document' => Json::encode($doc),
                    'schema_version' => 1, 'source' => 'human', 'message' => 'legacy',
                ]);
            }
            $db->table('page_drafts')->insert(['page_id' => $pageId, 'site_id' => $site, 'document' => Json::encode($draft), 'version' => $version]);
        };
        // Site A: revision 1 (live image) is published; revision 2 (draft image) is saved but not published.
        $page($ids['pageA'], $ids['siteA'], [[$ids['revA1'], 1, $liveDoc], [$ids['revA2'], 2, $draftDoc]], $draftDoc, 2);
        $page($ids['pageB'], $ids['siteB'], [[$ids['revB1'], 1, $foreignDoc]], $foreignDoc, 1);
        foreach ([[$ids['pubA'], $ids['siteA'], $ids['pageA'], $ids['revA1']], [$ids['pubB'], $ids['siteB'], $ids['pageB'], $ids['revB1']]] as [$pub, $site, $pageId, $rev]) {
            $db->table('publications')->insert([
                'id' => $pub, 'site_id' => $site, 'page_id' => $pageId, 'revision_id' => $rev, 'path' => '/',
                'html' => '<html></html>', 'epoch' => 1, 'idempotency_key' => "legacy-{$pub}",
            ]);
            $db->table('live_pages')->insert(['page_id' => $pageId, 'site_id' => $site, 'path' => '/', 'publication_id' => $pub, 'epoch' => 1]);
        }
        MigrationConfig::disconnect();

        // 3. Upgrade: newer migrations on top of the existing history, then data upgrades.
        app(SchemaMigrator::class)->migrate('test');

        $owner = MigrationConfig::connect('test');
        $db = DB::connection($owner);
        $this->assertSame(count(glob(database_path('migrations/*.php'))), $db->table('migrations')->count());
        $legacy = $db->table('publications')->where('id', $ids['pubA'])->first();
        $this->assertSame('legacy', $legacy->request_fingerprint);
        $this->assertNull($legacy->render_inputs);
        // Revisions made before titles and URLs were editable get the page values they were made with.
        $this->assertSame([['Home', '/']], $db->table('page_revisions')->get(['title', 'path'])->map(fn ($r) => [$r->title, $r->path])->unique()->values()->all());

        $access = fn (string $asset, string $host) => app(MediaService::class)->resolveAccess("{$asset}.png", $host, null, null, new MediaSigner)['access'] ?? null;
        // A live image published before the upgrade is still public.
        $this->assertSame('public', $access($ids['liveAsset'], 'legacy-a.test'));
        // An image only used by an unpublished draft stays private.
        $this->assertNull($access($ids['draftAsset'], 'legacy-a.test'));
        // Links stay site-scoped: another site's publication cannot make an asset public there.
        $this->assertNull($access($ids['liveAsset'], 'legacy-b.test'));
        $links = $db->table('publication_media')->get()->map(fn ($r) => (array) $r)->all();
        $this->assertSame([['site_id' => $ids['siteA'], 'publication_id' => $ids['pubA'], 'asset_id' => $ids['liveAsset']]], $links);

        // Recorded once, and safe to run again.
        $recorded = $db->table('data_upgrades')->orderBy('name')->get();
        $this->assertSame(['2026-10-06-backfill-publication-media', '2026-10-08-backfill-page-request-fingerprints', '2026-10-10-index-form-entries'], $recorded->pluck('name')->all());
        // Legacy pages were created without request keys: nothing to backfill.
        $this->assertSame(['pages' => 0, 'backfilled' => 0, 'withoutFirstRevision' => 0], json_decode($recorded[1]->details, true));
        $details = json_decode($recorded[0]->details, true);
        $this->assertSame([2, 1, 1], [$details['publications'], $details['inserted'], $details['unknownAssets']]);
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection($owner);
        try {
            $again = app(DataUpgrades::class)->backfillPublicationMedia();
            app(DataUpgrades::class)->runPending(fn () => null);
        } finally {
            DB::setDefaultConnection($previous);
        }
        $this->assertSame([0, 2], [$again['inserted'], $again['publications']]);
        $this->assertSame(1, $db->table('publication_media')->count());
        MigrationConfig::disconnect();

        // The runtime role cannot read or change upgrade or migration bookkeeping.
        $this->assertSame('42501', $this->sqlState(fn () => DB::table('data_upgrades')->count()));
        $this->assertSame('42501', $this->sqlState(fn () => DB::table('migrations')->count()));
    }
}
