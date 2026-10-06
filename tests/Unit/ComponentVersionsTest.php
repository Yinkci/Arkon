<?php

namespace Tests\Unit;

use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Components\DocumentValidator;
use App\Arkon\Components\Render\HeroV1;
use App\Arkon\Components\Render\PageV1;
use App\Arkon\Support\Json;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Historical component versions: versions are immutable, old ones stay
 * registered, and documents are migrated forward in memory (stored revisions
 * are never rewritten). Uses a test registry with a hypothetical hero v2.
 */
class ComponentVersionsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('testing/components-'.uniqid());
        File::copyDirectory(resource_path('arkon/components'), $this->dir);
        $v1 = json_decode(File::get("{$this->dir}/hero/v1.json"), true);
        // v2 renames `text` to `body`.
        $v2 = $v1;
        $v2['version'] = 2;
        $v2['props']['body'] = $v2['props']['text'];
        unset($v2['props']['text'], $v2['defaultProps']['text']);
        $v2['defaultProps']['body'] = '';
        File::put("{$this->dir}/hero/v2.json", json_encode($v2));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function registry(bool $withMigration = true): ComponentRegistry
    {
        return new ComponentRegistry($this->dir, ['page@1' => PageV1::class, 'hero@1' => HeroV1::class], $withMigration ? [
            'hero@1' => function (array $props) {
                $props['body'] = $props['text'] ?? '';
                unset($props['text']);

                return $props;
            },
        ] : []);
    }

    private function v1Document(): array
    {
        return Json::decode('{"schemaVersion":1,"root":"root0001","nodes":{"root0001":{"id":"root0001","type":"page","version":1,"props":{},"children":["hero0001"]},"hero0001":{"id":"hero0001","type":"hero","version":1,"props":{"heading":"Old","text":"Kept","image":{"assetId":"01890a5d-ac96-774b-bcce-b302099a8057","alt":"x"}}}},"seo":{}}');
    }

    public function test_old_versions_stay_registered_next_to_the_current_one(): void
    {
        $registry = $this->registry();
        $this->assertSame(1, $registry->get('hero', 1)->version);
        $this->assertSame(2, $registry->current('hero')->version);
    }

    public function test_an_old_document_is_migrated_forward_in_memory_and_then_validates(): void
    {
        $registry = $this->registry();
        $validator = new DocumentValidator($registry);
        $old = $this->v1Document();
        $this->assertSame([['nodeId' => 'hero0001', 'message' => 'Unsupported hero version 1']], array_slice($validator->validate($old), 0, 1));

        $migrated = $registry->migrateDocument($old);
        $this->assertSame([], $validator->validate($migrated));
        $this->assertSame(2, $migrated['nodes']['hero0001']['version']);
        $this->assertSame('Kept', $migrated['nodes']['hero0001']['props']['body']);
        // The input (a stored revision) is untouched.
        $this->assertSame(1, $old['nodes']['hero0001']['version']);
    }

    public function test_without_a_migration_path_the_node_is_left_alone_and_rejected_clearly(): void
    {
        $registry = $this->registry(withMigration: false);
        $doc = $registry->migrateDocument($this->v1Document());
        $this->assertSame(1, $doc['nodes']['hero0001']['version']);
        $this->assertContains('Unsupported hero version 1', array_column((new DocumentValidator($registry))->validate($doc), 'message'));
    }

    public function test_media_of_old_revisions_is_read_with_their_own_version(): void
    {
        $refs = (new DocumentValidator($this->registry()))->mediaRefsLenient($this->v1Document());
        $this->assertSame(['ids' => ['01890a5d-ac96-774b-bcce-b302099a8057'], 'skippedNodes' => 0], $refs);
    }
}
