<?php

namespace Tests\Feature;

use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\ForbiddenException;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Errors\StaleVersionException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Pages\PageService;
use App\Arkon\Renderer\PageRenderer;
use App\Arkon\Seo\SeoDefaults;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Json;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/** Port of packages/core/test/pages.test.ts. */
class PagesTest extends DatabaseTestCase
{
    private array $f;

    private PageService $pages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
        $this->pages = app(PageService::class);
    }

    private function setHeading(string $heading): array
    {
        return [['op' => 'updateProps', 'nodeId' => $this->f['heroId'], 'set' => ['heading' => $heading]]];
    }

    private function save(int $baseVersion, array $ops, ?SiteContext $ctx = null): array
    {
        return $this->pages->saveDraft($ctx ?? $this->f['ctx'], ['pageId' => $this->f['pageId'], 'baseVersion' => $baseVersion, 'operations' => $ops, 'saveKey' => self::key()]);
    }

    private function publish(int $version, ?string $key = null, ?SiteContext $ctx = null, ?string $pageId = null): array
    {
        return $this->pages->publish($ctx ?? $this->f['ctx'], ['pageId' => $pageId ?? $this->f['pageId'], 'expectedVersion' => $version, 'idempotencyKey' => $key ?? self::key()]);
    }

    private function live(string $path = '/'): ?object
    {
        return $this->pages->livePage($this->f['siteId'], $path);
    }

    private function publicationCount(): int
    {
        return DB::table('publications')->where('page_id', $this->f['pageId'])->count();
    }

    // ── drafts and the live page ──

    public function test_saving_a_draft_bumps_the_version_and_records_a_revision(): void
    {
        $saved = $this->save(1, $this->setHeading('Draft 2'));
        $this->assertSame(2, $saved['version']);
        $this->assertSame(1, $saved['revision']['number']);
        $state = $this->pages->editorState($this->f['ctx'], $this->f['pageId']);
        $this->assertSame(2, $state['draft']['version']);
        $this->assertSame('draft', $state['status']);
        $this->assertSame(['Saved draft'], array_column($this->pages->listRevisions($this->f['ctx'], $this->f['pageId']), 'message'));
    }

    public function test_unpublished_edits_never_change_the_live_page(): void
    {
        $this->assertNull($this->live());
        $this->publish(1);
        $before = $this->live();
        $this->assertStringContainsString('Original heading', $before->html);

        $saved = $this->save(1, $this->setHeading('Unpublished change'));
        $revisions = $this->pages->listRevisions($this->f['ctx'], $this->f['pageId']);
        $this->pages->restoreRevision($this->f['ctx'], ['pageId' => $this->f['pageId'], 'revisionId' => end($revisions)['id'], 'expectedVersion' => $saved['version']]);
        $this->save($saved['version'] + 1, $this->setHeading('Another change'));

        $this->assertEquals($before, $this->live());
        $this->assertStringContainsString('Another change', $this->pages->renderPreview($this->f['ctx'], $this->f['pageId']));
        $this->assertSame('changed', $this->pages->listPages($this->f['ctx'])[0]['status']);
    }

    public function test_published_html_contains_no_editor_assets(): void
    {
        $this->publish(1);
        $html = $this->live()->html;
        $this->assertStringNotContainsString('data-ak-', $html);
        $this->assertDoesNotMatchRegularExpression('/<script(?! type="application\/ld\+json")/i', $html);
        $this->assertStringNotContainsString('contenteditable', $html);
        $this->assertStringNotContainsString('/build/', $html);
        $this->assertStringNotContainsString('inertia', strtolower($html));
    }

    public function test_publishing_makes_the_latest_draft_live_and_reuses_the_checkpoint_revision(): void
    {
        $this->save(1, $this->setHeading('Ship it'));
        $result = $this->publish(2);
        $this->assertFalse($result['replayed']);
        $this->assertStringContainsString('Ship it', $this->live()->html);
        $this->assertSame('published', $this->pages->listPages($this->f['ctx'])[0]['status']);
        $this->assertCount(1, $this->pages->listRevisions($this->f['ctx'], $this->f['pageId']));
    }

    public function test_refuses_to_publish_a_draft_with_publish_blocking_problems(): void
    {
        $this->save(1, $this->setHeading(''));
        try {
            $this->publish(2);
            $this->fail('Expected a validation error');
        } catch (ValidationException $error) {
            $this->assertSame('Hero heading is empty', $error->issues[0]['message']);
        }
        $this->assertSame(0, $this->publicationCount());
    }

    public function test_rejects_invalid_documents_without_changing_the_draft(): void
    {
        foreach ([$this->setHeading(str_repeat('x', 500)), [['op' => 'removeNode', 'nodeId' => 'missing1']], [['op' => 'explode']]] as $ops) {
            try {
                $this->save(1, $ops);
                $this->fail('Expected a validation error');
            } catch (ValidationException) {
            }
        }
        $this->assertSame(1, $this->pages->editorState($this->f['ctx'], $this->f['pageId'])['draft']['version']);
    }

    public function test_empty_objects_stay_objects_through_save_and_storage(): void
    {
        // A request body as the editor sends it: seo {} and the page root's props {}.
        $raw = Json::decode('[{"op":"updateSeo","set":{},"unset":["title"]}]');
        $this->save(1, $raw);
        $stored = DB::table('page_drafts')->where('page_id', $this->f['pageId'])->value('document');
        $decoded = json_decode($stored); // objects as stdClass, lists as arrays
        $this->assertInstanceOf(\stdClass::class, $decoded->seo);
        $this->assertInstanceOf(\stdClass::class, $decoded->nodes->{$decoded->root}->props);
    }

    // ── stale writes are rejected ──

    public function test_a_save_based_on_an_old_version_cannot_overwrite_a_newer_draft(): void
    {
        $this->save(1, $this->setHeading('First writer'));
        $this->expectExceptionObject(new StaleVersionException(1, 2));
        try {
            $this->save(1, $this->setHeading('Second writer'));
        } finally {
            $state = $this->pages->editorState($this->f['ctx'], $this->f['pageId']);
            $this->assertSame(2, $state['draft']['version']);
            $this->assertStringContainsString('First writer', Json::encode($state['draft']['document']));
        }
    }

    public function test_publishing_and_restoring_require_the_version_the_user_was_looking_at(): void
    {
        $this->save(1, $this->setHeading('Newer'));
        $this->assertThrows(fn () => $this->publish(1), StaleVersionException::class);
        $revision = $this->pages->listRevisions($this->f['ctx'], $this->f['pageId'])[0];
        $this->assertThrows(
            fn () => $this->pages->restoreRevision($this->f['ctx'], ['pageId' => $this->f['pageId'], 'revisionId' => $revision['id'], 'expectedVersion' => 1]),
            StaleVersionException::class,
        );
    }

    // ── publishing is idempotent and ordered ──

    public function test_retrying_with_the_same_key_returns_the_first_result(): void
    {
        $key = self::key();
        $first = $this->publish(1, $key);
        $retry = $this->publish(1, $key);
        $this->assertSame($first['publicationId'], $retry['publicationId']);
        $this->assertTrue($retry['replayed']);
        $this->assertSame(1, $this->publicationCount());
    }

    public function test_a_key_reused_for_another_page_is_rejected(): void
    {
        $key = self::key();
        $this->publish(1, $key);
        $other = $this->addPage($this->f['siteId'], '/other', 'Other');
        $this->assertThrows(fn () => $this->publish(1, $key, pageId: $other), ConflictException::class, 'different page or version');
    }

    public function test_publications_store_their_render_inputs_and_are_reproducible(): void
    {
        $this->publish(1);
        $publication = DB::table('publications')->where('page_id', $this->f['pageId'])->first();
        $inputs = json_decode($publication->render_inputs, true);
        $this->assertSame(PageRenderer::VERSION, $inputs['renderer']);
        $this->assertSame(['hero@5', 'page@6'], $inputs['components']);
        $this->assertEquals(['name' => 'Test Site', 'lang' => 'en', 'seoDefaults' => ['version' => 0, 'values' => SeoDefaults::defaults()]], $inputs['site']);

        // Same revision + same recorded inputs → byte-identical HTML, even after the site was renamed.
        DB::table('sites')->where('id', $this->f['siteId'])->update(['name' => 'Renamed later']);
        $revision = DB::table('page_revisions')->where('id', $publication->revision_id)->first();
        $again = app(PageRenderer::class)->render(
            Json::decode($revision->document), 'production', $inputs['page'], $inputs['site'], $inputs['media'], strict: true,
        );
        $this->assertSame($publication->html, $again['html']);
    }

    public function test_an_older_background_rerender_can_never_replace_a_newer_publication(): void
    {
        $this->publish(1);
        $stale = $this->pages->prepareRerender($this->f['siteId'], $this->f['pageId']);
        $this->assertNotNull($stale);

        $this->save(1, $this->setHeading('Newest'));
        $newest = $this->publish(2);

        $this->assertSame(false, $this->pages->commitRerender($stale)['applied']);
        $live = $this->live();
        $this->assertSame($newest['publicationId'], $live->publication_id);
        $this->assertStringContainsString('Newest', $live->html);
    }

    public function test_a_rerender_after_a_newer_epoch_applies_and_retrying_it_is_a_no_op(): void
    {
        $this->publish(1);
        // A dependency publish elsewhere in the site advanced the epoch.
        DB::table('sites')->where('id', $this->f['siteId'])->increment('publish_epoch');
        $prepared = $this->pages->prepareRerender($this->f['siteId'], $this->f['pageId']);
        $this->assertSame(true, $this->pages->commitRerender($prepared)['applied']);
        $this->assertSame(false, $this->pages->commitRerender($prepared)['applied']);
        $this->assertSame(2, $this->publicationCount());
    }

    // ── permissions and site isolation ──

    public function test_editors_can_save_but_not_publish_and_viewers_cannot_edit(): void
    {
        $editor = $this->addMember($this->f['siteId'], 'editor');
        $this->save(1, $this->setHeading('By editor'), $editor);
        $this->assertThrows(fn () => $this->publish(2, ctx: $editor), ForbiddenException::class);
        $viewer = $this->addMember($this->f['siteId'], 'viewer');
        $this->assertThrows(fn () => $this->save(2, $this->setHeading('x'), $viewer), ForbiddenException::class);
    }

    public function test_members_of_another_site_see_nothing_and_change_nothing(): void
    {
        $other = $this->siteFixture();
        $intruder = $other['ctx'];
        $asIfOwnSite = new SiteContext($this->f['siteId'], $intruder->userId);
        $pageId = $this->f['pageId'];

        foreach ([
            fn () => $this->pages->editorState($asIfOwnSite, $pageId),
            fn () => $this->save(1, $this->setHeading('pwned'), $asIfOwnSite),
            fn () => $this->publish(1, ctx: $asIfOwnSite),
            // Own site context, foreign page id.
            fn () => $this->pages->editorState($intruder, $pageId),
            fn () => $this->save(1, $this->setHeading('pwned'), $intruder),
            fn () => $this->pages->listRevisions($intruder, $pageId),
            fn () => $this->pages->renderPreview($intruder, $pageId),
            fn () => $this->pages->renderCanvas($intruder, $pageId, $this->f['document'], new MediaSigner),
        ] as $attempt) {
            $this->assertThrows($attempt, NotFoundException::class);
        }
        $this->assertSame(1, $this->pages->editorState($this->f['ctx'], $pageId)['draft']['version']);
        $this->assertNull($this->pages->livePage($other['siteId'], '/'));
    }

    public function test_malformed_ids_are_not_found_rather_than_errors(): void
    {
        $this->assertThrows(fn () => $this->pages->editorState($this->f['ctx'], 'not-a-uuid'), NotFoundException::class);
        $this->assertThrows(fn () => $this->pages->editorState(new SiteContext('nope', $this->f['ctx']->userId), $this->f['pageId']), NotFoundException::class);
    }

    public function test_the_database_refuses_cross_site_links_even_if_application_code_were_wrong(): void
    {
        $other = $this->siteFixture();
        $revisionId = $this->publish(1)['revisionId'];
        // A publication claiming to be site B's but pointing at site A's revision violates the composite FK.
        $state = $this->sqlState(fn () => DB::table('publications')->insert([
            'id' => '01890a5d-ac96-774b-bcce-b302099a8057', 'site_id' => $other['siteId'], 'page_id' => $this->f['pageId'],
            'revision_id' => $revisionId, 'path' => '/', 'html' => 'x', 'epoch' => 99, 'idempotency_key' => self::key(), 'request_fingerprint' => 'test',
        ]));
        $this->assertSame('23503', $state); // foreign_key_violation
    }

    public function test_the_runtime_role_cannot_rewrite_history(): void
    {
        $this->publish(1);
        $denied = '42501'; // insufficient_privilege
        $this->assertSame($denied, $this->sqlState(fn () => DB::table('publications')->where('page_id', $this->f['pageId'])->update(['html' => 'tampered'])));
        $this->assertSame($denied, $this->sqlState(fn () => DB::table('page_revisions')->where('page_id', $this->f['pageId'])->delete()));
        $this->assertSame($denied, $this->sqlState(fn () => DB::table('audit_logs')->delete()));
        $this->assertSame($denied, $this->sqlState(fn () => DB::table('publication_media')->delete()));
        $this->assertSame($denied, $this->sqlState(fn () => DB::statement('TRUNCATE publications CASCADE')));
    }
}
