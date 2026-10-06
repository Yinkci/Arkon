<?php

namespace Tests;

use App\Arkon\Components\Factories;
use App\Arkon\Database\MigrationConfig;
use App\Arkon\Database\TestDatabase;
use App\Arkon\Database\Transactions;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\FailingCommitTransactions;

/**
 * Integration tests against arkonlaravel_test. The code under test connects as
 * the restricted runtime role; only the reset (TRUNCATE) uses the schema owner.
 * No transactions are wrapped around tests: concurrency and commit behaviour
 * must be real.
 */
abstract class DatabaseTestCase extends TestCase
{
    private static bool $migrated = false;

    /** 1×1 transparent PNG. */
    public const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.connections.pgsql.database') !== MigrationConfig::databaseFor('test')) {
            throw new RuntimeException('Tests must run against the test database');
        }
        if (! self::$migrated) {
            TestDatabase::migrate('test');
            self::$migrated = true;
        }
        TestDatabase::truncate('test');
    }

    public static function png(): string
    {
        return base64_decode(self::PNG_1X1);
    }

    public static function key(): string
    {
        return 'test-'.str_replace('-', '', Uuid::v7());
    }

    protected function createUser(string $name = 'User'): string
    {
        $id = Uuid::v7();
        DB::table('users')->insert(['id' => $id, 'name' => $name, 'email' => strtolower($name)."-{$id}@test.local", 'password' => 'x']);

        return $id;
    }

    /**
     * A site with one member of the given role and one unpublished page at "/".
     *
     * @return array{siteId: string, pageId: string, ctx: SiteContext, document: array, heroId: string}
     */
    protected function siteFixture(string $role = 'owner', string $siteName = 'Test Site'): array
    {
        $siteId = Uuid::v7();
        $pageId = Uuid::v7();
        $userId = $this->createUser($role);
        $hero = Factories::heroNode(['heading' => 'Original heading', 'text' => 'Original text']);
        $document = Factories::pageDocument([$hero]);
        DB::table('sites')->insert(['id' => $siteId, 'name' => $siteName]);
        DB::table('site_members')->insert(['site_id' => $siteId, 'user_id' => $userId, 'role' => $role]);
        DB::table('pages')->insert(['id' => $pageId, 'site_id' => $siteId, 'path' => '/', 'title' => 'Home']);
        DB::table('page_drafts')->insert(['page_id' => $pageId, 'site_id' => $siteId, 'document' => Json::encode($document), 'version' => 1]);

        return ['siteId' => $siteId, 'pageId' => $pageId, 'ctx' => new SiteContext($siteId, $userId), 'document' => $document, 'heroId' => $hero['id']];
    }

    /** An extra fixture-style page (no revision yet), like the reference e2e/integration helpers. */
    protected function addPage(string $siteId, string $path, string $title, ?array $document = null): string
    {
        $pageId = Uuid::v7();
        $document ??= Factories::pageDocument([Factories::heroNode(['heading' => $title])]);
        DB::table('pages')->insert(['id' => $pageId, 'site_id' => $siteId, 'path' => $path, 'title' => $title]);
        DB::table('page_drafts')->insert(['page_id' => $pageId, 'site_id' => $siteId, 'document' => Json::encode($document), 'version' => 1]);

        return $pageId;
    }

    protected function addMember(string $siteId, string $role): SiteContext
    {
        $userId = $this->createUser($role);
        DB::table('site_members')->insert(['site_id' => $siteId, 'user_id' => $userId, 'role' => $role]);

        return new SiteContext($siteId, $userId);
    }

    protected function addDomain(string $siteId, string $hostname): void
    {
        DB::table('site_domains')->insert(['hostname' => $hostname, 'site_id' => $siteId]);
    }

    /** Runs `$service` with transactions that complete and then fail to commit (connection lost at COMMIT). */
    protected function withFailingCommit(callable $run): void
    {
        $this->app->instance(Transactions::class, new FailingCommitTransactions);
        try {
            $run();
            $this->fail('Expected the commit to fail');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('before commit', $error->getMessage());
        } finally {
            $this->app->instance(Transactions::class, new Transactions);
        }
    }

    /** The SQLSTATE of a rejected statement. */
    protected function sqlState(callable $run): ?string
    {
        try {
            $run();
        } catch (QueryException $error) {
            return $error->errorInfo[0] ?? null;
        }
        $this->fail('Expected the statement to fail');
    }
}
