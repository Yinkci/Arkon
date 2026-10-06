<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PDO;
use Tests\DatabaseTestCase;
use Tests\Support\Parallel;

/**
 * Concurrent requests in separate PHP processes (separate connections, real lock
 * waits), started at the same instant. Ports the concurrency cases of
 * pages.test.ts / page-management.test.ts and consistency.test.ts.
 */
class ConcurrencyTest extends DatabaseTestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
    }

    private function codes(array $results): array
    {
        $codes = array_map(fn ($r) => $r['ok'] ? 'OK' : $r['code'], $results);
        sort($codes);

        return $codes;
    }

    public function test_concurrent_saves_of_the_same_version_exactly_one_wins(): void
    {
        $parallel = new Parallel;
        foreach (['A', 'B', 'C', 'D'] as $heading) {
            $parallel->add('saveDraft', $this->f['ctx'], [
                'pageId' => $this->f['pageId'], 'baseVersion' => 1, 'saveKey' => self::key(),
                'operations' => [['op' => 'updateProps', 'nodeId' => $this->f['heroId'], 'set' => ['heading' => $heading]]],
            ]);
        }
        $this->assertSame(['OK', 'STALE_VERSION', 'STALE_VERSION', 'STALE_VERSION'], $this->codes($parallel->wait()));
        $this->assertSame(2, (int) DB::table('page_drafts')->where('page_id', $this->f['pageId'])->value('version'));
        $this->assertSame(1, DB::table('page_revisions')->where('page_id', $this->f['pageId'])->count());
    }

    public function test_concurrent_duplicate_publishes_publish_once(): void
    {
        $key = self::key();
        $parallel = new Parallel;
        foreach (range(1, 4) as $_) {
            $parallel->add('publish', $this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => 1, 'idempotencyKey' => $key]);
        }
        $results = $parallel->wait();
        $this->assertSame(['OK', 'OK', 'OK', 'OK'], $this->codes($results));
        $this->assertCount(1, array_unique(array_map(fn ($r) => $r['result']['publicationId'], $results)));
        $this->assertSame(3, count(array_filter($results, fn ($r) => $r['result']['replayed'])));
        $this->assertSame(1, DB::table('publications')->where('page_id', $this->f['pageId'])->count());
    }

    public function test_concurrent_creates_of_the_same_path_exactly_one_wins(): void
    {
        $parallel = new Parallel;
        foreach (range(1, 4) as $i) {
            $parallel->add('createPage', $this->f['ctx'], ['title' => "Race {$i}", 'path' => '/race', 'requestKey' => self::key()]);
        }
        $this->assertSame(['CONFLICT', 'CONFLICT', 'CONFLICT', 'OK'], $this->codes($parallel->wait()));
        $this->assertSame(1, DB::table('pages')->where('path', '/race')->count());
    }

    public function test_concurrent_renames_to_the_same_url_exactly_one_wins(): void
    {
        $a = $this->addPage($this->f['siteId'], '/a', 'A');
        $b = $this->addPage($this->f['siteId'], '/b', 'B');
        $parallel = new Parallel;
        foreach ([$a, $b] as $pageId) {
            $parallel->add('updateSettings', $this->f['ctx'], ['pageId' => $pageId, 'expectedVersion' => 1, 'title' => 'Same', 'path' => '/same', 'saveKey' => self::key()]);
        }
        $this->assertSame(['CONFLICT', 'OK'], $this->codes($parallel->wait()));
    }

    public function test_concurrent_saves_and_publishes_across_pages_never_deadlock_and_get_distinct_epochs(): void
    {
        $pages = [$this->f['pageId'], ...array_map(fn ($i) => $this->addPage($this->f['siteId'], "/p{$i}", "P{$i}"), range(1, 4))];
        $parallel = new Parallel;
        foreach ($pages as $pageId) {
            $parallel->add('publish', $this->f['ctx'], ['pageId' => $pageId, 'expectedVersion' => 1, 'idempotencyKey' => self::key()]);
        }
        $parallel->add('saveDraft', $this->f['ctx'], [
            'pageId' => $this->f['pageId'], 'baseVersion' => 1, 'saveKey' => self::key(),
            'operations' => [['op' => 'updateProps', 'nodeId' => $this->f['heroId'], 'set' => ['heading' => 'Racing save']]],
        ]);
        $results = $parallel->wait();
        // The save and the publish of the same page serialise on the draft row: whichever is
        // second sees a newer version (stale) or succeeds; nothing deadlocks or fails otherwise.
        foreach ($results as $result) {
            $this->assertTrue($result['ok'] || $result['code'] === 'STALE_VERSION', json_encode($result));
        }
        $epochs = DB::table('publications')->pluck('epoch')->map(fn ($e) => (int) $e)->all();
        $this->assertSame(count($epochs), count(array_unique($epochs)));
    }

    /**
     * Snapshot consistency (ARCHITECTURE §9.3). Another change to published state takes
     * the epoch lock, changes something every page renders (the site name in <title>),
     * holds the lock, then commits. For every interleaving, a publication shows that
     * change if and only if its epoch is later.
     */
    public function test_a_publication_shows_a_concurrent_dependency_change_iff_its_epoch_is_later(): void
    {
        foreach (range(0, 3) as $round) {
            if ($round > 0) {
                parent::setUp();
                $this->f = $this->siteFixture();
            }
            $pages = array_map(fn ($i) => $this->addPage($this->f['siteId'], "/p{$i}", "P{$i}"), range(0, 4));
            $name = "Renamed {$round}";
            $parallel = new Parallel;
            foreach ($pages as $i => $pageId) {
                $parallel->add('publish', $this->f['ctx'], ['pageId' => $pageId, 'expectedVersion' => 1, 'idempotencyKey' => self::key()], $i * (5 + $round * 15));
            }
            $dependencyEpoch = $this->publishDependency($parallel, $name, startMs: $round * 20, holdMs: 150 + $round * 50);
            $results = $parallel->wait();

            $epochs = [$dependencyEpoch];
            foreach ($results as $result) {
                $this->assertTrue($result['ok'], json_encode($result));
                $row = DB::table('publications')->where('id', $result['result']['publicationId'])->first();
                $epochs[] = (int) $row->epoch;
                $this->assertSame((int) $row->epoch > $dependencyEpoch, str_contains($row->html, $name), "round {$round}: epoch {$row->epoch} vs dependency {$dependencyEpoch}");
            }
            // Every change to published state got its own epoch.
            $this->assertCount(count($results) + 1, array_unique($epochs));
        }
    }

    private function publishDependency(Parallel $parallel, string $name, int $startMs, int $holdMs): int
    {
        $config = config('database.connections.pgsql');
        $pdo = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $parallel->sleepUntil($startMs);
        $pdo->beginTransaction();
        $statement = $pdo->prepare('UPDATE sites SET publish_epoch = publish_epoch + 1, name = ? WHERE id = ? RETURNING publish_epoch');
        $statement->execute([$name, $this->f['siteId']]);
        $epoch = (int) $statement->fetchColumn();
        usleep($holdMs * 1000);
        $pdo->commit();

        return $epoch;
    }

    public function test_the_dependency_change_is_visible_to_a_publish_that_waited_for_it(): void
    {
        $pages = array_map(fn ($i) => $this->addPage($this->f['siteId'], "/w{$i}", "W{$i}"), range(0, 3));
        $parallel = new Parallel;
        foreach ($pages as $pageId) {
            // Start while the dependency transaction already holds the epoch lock.
            $parallel->add('publish', $this->f['ctx'], ['pageId' => $pageId, 'expectedVersion' => 1, 'idempotencyKey' => self::key()], 100);
        }
        $dependencyEpoch = $this->publishDependency($parallel, 'Renamed Site', startMs: 0, holdMs: 600);
        foreach ($parallel->wait() as $result) {
            $row = DB::table('publications')->where('id', $result['result']['publicationId'])->first();
            $this->assertGreaterThan($dependencyEpoch, (int) $row->epoch);
            $this->assertStringContainsString('Renamed Site', $row->html);
        }
    }
}
