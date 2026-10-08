<?php

namespace Tests\Feature;

use App\Arkon\Pages\PageService;
use App\Arkon\Renderer\PageRenderer;
use App\Arkon\Renderer\RenderException;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Publications made while arkon-php-2 was still in development (hero v2 parts with a fixed
 * 0% flex basis, no --ak-basis from direction settings) and the ones made after the change
 * both reproduce byte for byte: the later ones from their recorded renderer version, the
 * earlier ones from the development build an append-only compatibility record names. The
 * immutable publication, revision and render inputs are never changed.
 */
class RenderCompatTest extends DatabaseTestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
    }

    private function pages(): PageService
    {
        return app(PageService::class);
    }

    /**
     * A hero v2 publication with a direction per screen (image right on desktop, stacked on
     * mobile), as arkon-php-2 publishes it today. New pages get hero v3, so the v2 revision and
     * its publication are written here from the renderer's pinned output, like history.
     */
    private function publishStackingHero(): object
    {
        $style = ['root' => ['base' => ['direction' => 'row'], 'mobile' => ['direction' => 'column']], 'media' => ['base' => ['height' => '500px']]];
        $this->pages()->saveDraft($this->f['ctx'], ['pageId' => $this->f['pageId'], 'baseVersion' => 1, 'saveKey' => self::key(),
            'operations' => Json::decode(Json::encode([['op' => 'updateProps', 'nodeId' => $this->f['heroId'], 'set' => ['style' => $style]]]))]);
        $this->pages()->publish($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => 2, 'idempotencyKey' => self::key()]);
        $current = DB::table('publications')->where('page_id', $this->f['pageId'])->orderByDesc('created_at')->first();
        $revision = DB::table('page_revisions')->where('id', $current->revision_id)->first();

        $doc = Json::decode($revision->document);
        $doc['nodes'][$this->f['heroId']]['version'] = 2;
        $out = app(PageRenderer::class)->render(Json::decode(Json::encode($doc)), 'production', ['title' => 'Home', 'path' => '/'], ['name' => 'Test Site', 'lang' => 'en'], [],
            pinned: true, resources: ['tokens' => ['version' => null, 'values' => []]]);
        $revisionId = Uuid::v7();
        DB::table('page_revisions')->insert([...(array) $revision, 'id' => $revisionId, 'number' => $revision->number + 1, 'document' => Json::encode($doc)]);
        $id = Uuid::v7();
        DB::table('publications')->insert([...(array) $current, 'id' => $id, 'revision_id' => $revisionId, 'idempotency_key' => self::key(),
            'html' => $out['html'], 'render_inputs' => Json::encode($out['inputs'])]);

        return DB::table('publications')->where('id', $id)->first();
    }

    /**
     * The same revision as the in-development build published it, written as a separate
     * publication. Derived here independently of the renderer: the hero v2 rules as the
     * development database shows them, no --ak-basis declarations, and therefore the style
     * class named after the hash of the declarations without them.
     */
    private function insertPreBasisPublication(object $current): object
    {
        $class = fn (string $declarations) => 'ak-s'.substr(hash('sha256', $declarations), 0, 10);
        $html = str_replace(
            ['.ak-hero2{--ak-basis:0%;', 'flex:1 1 var(--ak-basis)', ';--ak-basis:0%}', ';--ak-basis:auto}',
                $class('flex-direction:row;--ak-basis:0%||flex-direction:column;--ak-basis:auto')],
            ['.ak-hero2{', 'flex:1 1 0%', '}', '}',
                $class('flex-direction:row||flex-direction:column')],
            $current->html,
        );
        $this->assertNotSame($current->html, $html);
        $id = Uuid::v7();
        DB::table('publications')->insert([...(array) $current, 'id' => $id, 'html' => $html, 'idempotency_key' => self::key()]);

        return DB::table('publications')->where('id', $id)->first();
    }

    public function test_current_publications_reproduce_from_their_recorded_version_with_the_flex_basis(): void
    {
        $publication = $this->publishStackingHero();
        $this->assertSame('arkon-php-2', Json::decode($publication->render_inputs)['renderer']);
        $this->assertStringContainsString('.ak-hero2{--ak-basis:0%;', $publication->html);
        $this->assertMatchesRegularExpression('#@media \(max-width:599px\)\{\.ak-s[0-9a-f]{10}\{flex-direction:column;--ak-basis:auto\}#', $publication->html);

        $result = $this->pages()->reproducePublication($this->f['siteId'], $publication->id);
        $this->assertSame(['reproduced', true, null], [$result['status'], $result['matches'], $result['build']]);
        $this->assertFalse($this->pages()->recordRenderCompat($publication->id, 'arkon-php-2-pre-basis', 'test')['recorded'], 'nothing to record');
        $this->assertSame(0, DB::table('publication_render_compat')->count());
    }

    public function test_in_development_publications_reproduce_from_the_build_their_compat_record_names(): void
    {
        $current = $this->publishStackingHero();
        $old = $this->insertPreBasisPublication($current);
        $before = (array) $old;

        $this->assertFalse($this->pages()->reproducePublication($this->f['siteId'], $old->id)['matches'], 'the recorded version alone does not reproduce it');
        $this->assertFalse($this->pages()->recordRenderCompat($old->id, 'arkon-php-9', 'test')['recorded'], 'unknown build');

        $recorded = $this->pages()->recordRenderCompat($old->id, 'arkon-php-2-pre-basis', 'Published during development');
        $this->assertTrue($recorded['recorded'], $recorded['reason']);
        $result = $this->pages()->reproducePublication($this->f['siteId'], $old->id);
        $this->assertSame(['reproduced', true, 'arkon-php-2-pre-basis'], [$result['status'], $result['matches'], $result['build']]);
        // The earlier behaviour exactly: fixed 0% basis on the hero parts, no --ak-basis from direction settings.
        $this->assertStringContainsString('.ak-hero2__media{display:block;flex:1 1 0%;', $result['html']);
        $this->assertStringNotContainsString('--ak-basis', $result['html']);
        // The later publication is unaffected, and nothing immutable changed.
        $this->assertTrue($this->pages()->reproducePublication($this->f['siteId'], $current->id)['matches']);
        $this->assertEquals($before, (array) DB::table('publications')->where('id', $old->id)->first());
        $this->assertFalse($this->pages()->recordRenderCompat($old->id, 'arkon-php-2-pre-basis', 'again')['recorded'], 'one record per publication');
    }

    public function test_a_build_is_recorded_only_when_it_reproduces_the_stored_html_exactly(): void
    {
        $current = $this->publishStackingHero();
        $old = $this->insertPreBasisPublication($current);
        // Output neither build produced (e.g. edited by hand): no record is made.
        DB::table('publications')->insert([...(array) $old, 'id' => $other = Uuid::v7(), 'idempotency_key' => self::key(), 'html' => str_replace('flex:1 1 0%', 'flex:2 1 0%', $old->html)]);
        $this->assertFalse($this->pages()->recordRenderCompat($other, 'arkon-php-2-pre-basis', 'test')['recorded']);
        $this->assertSame(0, DB::table('publication_render_compat')->count());
    }

    public function test_compat_records_are_append_only_and_builds_never_make_new_output(): void
    {
        $old = $this->insertPreBasisPublication($this->publishStackingHero());
        $this->pages()->recordRenderCompat($old->id, 'arkon-php-2-pre-basis', 'test');
        $this->assertSame('42501', $this->sqlState(fn () => DB::table('publication_render_compat')->update(['reason' => 'changed'])));
        $this->assertSame('42501', $this->sqlState(fn () => DB::table('publication_render_compat')->delete()));

        $this->expectException(RenderException::class);
        app(PageRenderer::class)->render($this->f['document'], 'production', ['title' => 'Home', 'path' => '/'], ['name' => 'Test Site'], [], rendererVersion: 'arkon-php-2-pre-basis');
    }

    public function test_the_record_command_reports_what_it_did(): void
    {
        $old = $this->insertPreBasisPublication($this->publishStackingHero());
        $this->artisan('arkon:record-render-compat', ['publication' => $old->id, 'build' => 'arkon-php-2-pre-basis'])->assertSuccessful();
        $this->artisan('arkon:record-render-compat', ['publication' => $old->id, 'build' => 'arkon-php-2-pre-basis'])->assertFailed();
        $this->artisan('arkon:reproduce-publication', ['publication' => $old->id])->expectsOutputToContain('byte for byte')->assertSuccessful();
    }
}
