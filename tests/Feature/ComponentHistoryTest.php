<?php

namespace Tests\Feature;

use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Components\DocumentValidator;
use App\Arkon\Database\MigrationConfig;
use App\Arkon\Pages\PageService;
use App\Arkon\Renderer\PageRenderer;
use App\Arkon\Renderer\RenderException;
use App\Arkon\Support\Json;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\DatabaseTestCase;
use Tests\Support\HeroNext;

/**
 * Publications stay reproducible across component versions: a page published
 * with the current hero (v3) renders byte for byte the same after a hypothetical
 * the next hero version (v5) is registered, while editing migrates the page forward to it.
 */
class ComponentHistoryTest extends DatabaseTestCase
{
    private array $f;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
        $this->dir = storage_path('testing/components-'.uniqid());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** Swaps in a registry with the next hero version (v5), as a deployment adding the new version would. */
    private function registerHeroNext(): void
    {
        $this->app->instance(ComponentRegistry::class, HeroNext::registry($this->dir));
        $this->app->forgetInstance(DocumentValidator::class);
        $this->app->forgetInstance(PageRenderer::class);
    }

    private function pages(): PageService
    {
        return app(PageService::class);
    }

    private function publish(int $version): array
    {
        return $this->pages()->publish($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => $version, 'idempotencyKey' => self::key()]);
    }

    private function publication(string $id): object
    {
        return DB::table('publications')->where('id', $id)->first();
    }

    private function versionsIn(string $revisionId): array
    {
        $doc = Json::decode(DB::table('page_revisions')->where('id', $revisionId)->value('document'));

        return collect(Json::entries($doc['nodes']))->mapWithKeys(fn ($n) => [$n['type'] => $n['version']])->sortKeys()->all();
    }

    public function test_a_publication_is_reproduced_byte_for_byte_after_a_new_hero_version_is_registered(): void
    {
        $v1 = $this->publish(1);
        $stored = $this->publication($v1['publicationId']);
        $this->assertSame(['hero@4', 'page@3'], json_decode($stored->render_inputs, true)['components']);
        $this->assertStringContainsString('ak-hero3__heading', $stored->html);

        $this->registerHeroNext();
        // Reading the old revision with today's editing rules fails; migrating it changes the output.
        $revision = Json::decode(DB::table('page_revisions')->where('id', $v1['revisionId'])->value('document'));
        $this->assertThrows(fn () => app(PageRenderer::class)->render($revision, 'production', ['title' => 'Home', 'path' => '/'], ['name' => 'Test Site'], []), RenderException::class);
        $migrated = app(ComponentRegistry::class)->migrateDocument($revision);
        $this->assertNotSame($stored->html, app(PageRenderer::class)->render($migrated, 'production', ['title' => 'Home', 'path' => '/'], ['name' => 'Test Site'], [])['html']);

        // Reproduction uses the recorded versions and inputs: identical, even after the site was renamed.
        DB::table('sites')->where('id', $this->f['siteId'])->update(['name' => 'Renamed later']);
        $result = $this->pages()->reproducePublication($this->f['siteId'], $v1['publicationId']);
        $this->assertSame('reproduced', $result['status'], (string) $result['reason']);
        $this->assertTrue($result['matches']);
        $this->assertSame($stored->html, $result['html']);
        $this->artisan('arkon:reproduce-publication', ['publication' => $v1['publicationId']])->expectsOutputToContain('byte for byte')->assertSuccessful();
    }

    public function test_editing_migrates_forward_and_the_new_publication_stores_exactly_what_it_rendered(): void
    {
        $v1 = $this->publish(1);
        $this->registerHeroNext();

        // The editor receives the page migrated to the next version (5).
        $state = $this->pages()->editorState($this->f['ctx'], $this->f['pageId']);
        $hero = $state['draft']['document']['nodes'][$this->f['heroId']];
        $this->assertSame(5, $hero['version']);
        $this->assertSame('Original text', $hero['props']['body']);

        // Publishing the unsaved, in-memory migration stores the migrated document as its own revision.
        $v2 = $this->publish(1);
        $this->assertNotSame($v1['revisionId'], $v2['revisionId']);
        $this->assertSame(['hero' => 5, 'page' => 3], $this->versionsIn($v2['revisionId']));
        $this->assertSame('Published with components upgraded to current versions', DB::table('page_revisions')->where('id', $v2['revisionId'])->value('message'));
        $this->assertSame(['hero' => 4, 'page' => 3], $this->versionsIn($v1['revisionId']), 'history is never rewritten');
        $html = $this->publication($v2['publicationId'])->html;
        $this->assertStringContainsString('ak-hero4__body', $html);
        $this->assertSame('published', $this->pages()->listPages($this->f['ctx'])[0]['status']);

        // Saving an edit persists v5; both publications still reproduce exactly.
        $saved = $this->pages()->saveDraft($this->f['ctx'], ['pageId' => $this->f['pageId'], 'baseVersion' => 1, 'saveKey' => self::key(),
            'operations' => [['op' => 'updateProps', 'nodeId' => $this->f['heroId'], 'set' => ['body' => 'New body']]]]);
        $this->assertSame(['hero' => 5, 'page' => 3], $this->versionsIn($saved['revision']['id']));
        foreach ([$v1, $v2] as $publication) {
            $result = $this->pages()->reproducePublication($this->f['siteId'], $publication['publicationId']);
            $this->assertTrue($result['matches'], $publication['publicationId']);
        }
    }

    public function test_a_background_rerender_keeps_the_published_component_versions(): void
    {
        $v1 = $this->publish(1);
        $this->registerHeroNext();
        DB::table('sites')->where('id', $this->f['siteId'])->update(['name' => 'New name', 'publish_epoch' => DB::raw('publish_epoch + 1')]);

        $prepared = $this->pages()->prepareRerender($this->f['siteId'], $this->f['pageId']);
        $this->assertSame(true, $this->pages()->commitRerender($prepared)['applied']);
        $live = $this->pages()->livePage($this->f['siteId'], '/');
        $this->assertStringContainsString('New name', $live->html);
        $this->assertStringContainsString('ak-hero3__heading', $live->html, 'still the published hero markup');
        $this->assertStringNotContainsString('ak-hero4', $live->html);
        $this->assertSame($v1['revisionId'], $this->publication($live->publication_id)->revision_id);
        $this->assertTrue($this->pages()->reproducePublication($this->f['siteId'], $live->publication_id)['matches']);
    }

    public function test_legacy_and_unavailable_inputs_are_reported_not_guessed(): void
    {
        $publication = $this->publish(1);
        // A legacy publication: made before inputs were recorded (owner role; history is append-only for the app).
        $owner = MigrationConfig::connect('test');
        try {
            DB::connection($owner)->table('publications')->where('id', $publication['publicationId'])->update(['render_inputs' => null]);
            $this->assertSame('legacy', $this->pages()->reproducePublication($this->f['siteId'], $publication['publicationId'])['status']);

            $inputs = ['renderer' => 'arkon-php-0', 'components' => ['hero@1', 'page@1'], 'page' => ['title' => 'Home', 'path' => '/'], 'site' => ['name' => 'Test Site', 'lang' => 'en'], 'media' => new \stdClass];
            DB::connection($owner)->table('publications')->where('id', $publication['publicationId'])->update(['render_inputs' => Json::encode($inputs)]);
            $result = $this->pages()->reproducePublication($this->f['siteId'], $publication['publicationId']);
            $this->assertSame('unavailable', $result['status']);
            $this->assertStringContainsString('arkon-php-0', $result['reason']);

            $inputs['renderer'] = PageRenderer::VERSION;
            $inputs['components'] = ['hero@3', 'page@1'];
            DB::connection($owner)->table('publications')->where('id', $publication['publicationId'])->update(['render_inputs' => Json::encode($inputs)]);
            $this->assertSame('unavailable', $this->pages()->reproducePublication($this->f['siteId'], $publication['publicationId'])['status']);
        } finally {
            MigrationConfig::disconnect();
        }
    }
}
