<?php

namespace Tests\Feature;

use App\Arkon\Api\ApiTokens;
use App\Arkon\Content\ContentItems;
use App\Arkon\Content\TermService;
use App\Arkon\Forms\FormService;
use App\Arkon\Media\MediaService;
use App\Arkon\Navigation\MenuService;
use App\Arkon\Pages\PageService;
use App\Arkon\Support\Json;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/** /api/v1 media, forms, entries, terms, navigation, users and SEO analysis. */
class ApiResourcesTest extends DatabaseTestCase
{
    private array $f;

    private const BASE = 'http://api.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
        $this->addDomain($this->f['siteId'], 'api.test');
    }

    private function auth(array $scopes, $ctx = null): array
    {
        return ['Authorization' => 'Bearer '.app(ApiTokens::class)->create($ctx ?? $this->f['ctx'], 'Test', $scopes)['token']];
    }

    private function jpeg(int $width = 1400, int $height = 900): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 200));
        ob_start();
        imagejpeg($image, null, 85);

        return (string) ob_get_clean();
    }

    public function test_media_is_public_only_once_a_live_page_uses_it_and_exposes_variants_not_paths(): void
    {
        $used = app(MediaService::class)->upload($this->f['ctx'], $this->jpeg(), 'cover.jpg');
        $private = app(MediaService::class)->upload($this->f['ctx'], self::png(), 'secret.png');
        $post = app(ContentItems::class)->create($this->f['ctx'], 'post', ['title' => 'With cover', 'featuredMediaId' => $used['id'], 'status' => 'published']);

        $list = $this->getJson(self::BASE.'/media')->assertOk();
        $this->assertSame([$used['id']], array_column($list->json('data'), 'id'), 'unpublished images stay private');
        $this->getJson(self::BASE.'/media/'.$private['id'])->assertNotFound();
        $item = $this->getJson(self::BASE.'/media/'.$used['id'])->assertOk()->json('data');
        $widths = array_map('intval', array_keys($item['variants']));
        foreach ([640, 960, 1280] as $width) {
            $this->assertContains($width, $widths, 'responsive sizes keyed by width');
        }
        $this->assertStringStartsWith('http://api.test/media/', $item['url']);
        $this->assertSame('image/webp', $item['variants']['640']['mime_type']);
        $this->assertTrue($item['formats']['webp']);
        $this->assertArrayNotHasKey('storage_key', $item);
        $this->assertStringNotContainsString('storage', json_encode($item));
        $this->assertSame($used['id'], $this->getJson(self::BASE.'/posts/'.$post['id'])->json('data.featured_media.id'));

        $member = $this->getJson(self::BASE.'/media', $this->auth(['read:media']))->assertOk();
        $this->assertCount(2, $member->json('data'));
        $this->assertFalse(collect($member->json('data'))->firstWhere('id', $private['id'])['public']);
    }

    public function test_uploads_use_the_media_pipeline_and_details_can_be_edited_and_trashed(): void
    {
        $auth = $this->auth(['write:media', 'read:media']);
        $file = UploadedFile::fake()->createWithContent('photo.jpg', $this->jpeg(1200, 800));
        $created = $this->post(self::BASE.'/media', ['file' => $file, 'alt' => 'A blue square'], [...$auth, 'Accept' => 'application/json'])->assertCreated();
        $id = $created->json('data.id');
        $created->assertJsonPath('data.alt', 'A blue square')->assertJsonPath('data.width', 1200)->assertJsonPath('data.formats.webp', true);
        $this->assertTrue(DB::table('media_variants')->where('asset_id', $id)->exists(), 'responsive WebP sizes were generated');

        $fake = UploadedFile::fake()->createWithContent('evil.jpg', '<?php echo 1;');
        $this->post(self::BASE.'/media', ['file' => $fake], [...$auth, 'Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('error.code', 'validation_error');

        $this->patchJson(self::BASE.'/media/'.$id, ['caption' => 'Caption'], $auth)->assertOk()->assertJsonPath('data.caption', 'Caption')->assertJsonPath('data.alt', 'A blue square');
        $this->patchJson(self::BASE.'/media/'.$id, ['caption' => 'Stale', 'version' => 1], $auth)->assertStatus(409);
        $this->deleteJson(self::BASE.'/media/'.$id, [], $this->auth(['write:media']))->assertNoContent();
        $this->assertSame('trash', $this->getJson(self::BASE.'/media/'.$id, $auth)->json('data.status'));
        $this->postJson(self::BASE.'/media/'.$id.'/restore', [], $auth)->assertOk()->assertJsonPath('data.status', 'library');
        $this->post(self::BASE.'/media', ['file' => $file], ['Accept' => 'application/json'])->assertUnauthorized();
    }

    private function liveForm(): string
    {
        $def = ['name' => 'Contact', 'submitLabel' => 'Send', 'successMessage' => 'Thanks', 'fields' => [['id' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true], ['id' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true]]];
        $form = app(FormService::class)->save($this->f['ctx'], ['baseVersion' => 0, 'requestKey' => self::key(), 'definition' => $def]);
        app(FormService::class)->publish($this->f['ctx'], $form['id'], ['expectedVersion' => 1, 'requestKey' => self::key()]);
        $node = ['id' => 'form_123', 'type' => 'form', 'version' => 4, 'props' => ['form' => ['id' => $form['id']], 'style' => new \stdClass]];
        app(PageService::class)->saveDraft($this->f['ctx'], ['pageId' => $this->f['pageId'], 'baseVersion' => 1, 'saveKey' => self::key(), 'operations' => Json::decode(Json::encode([['op' => 'insertNode', 'parentId' => $this->f['document']['root'], 'index' => 1, 'nodes' => [$node]]]))]);

        return $form['id'];
    }

    public function test_forms_are_public_once_live_and_submissions_use_the_forms_service(): void
    {
        $id = $this->liveForm();
        $this->getJson(self::BASE.'/forms/'.$id)->assertNotFound('not on a live page yet');
        $this->postJson(self::BASE.'/forms/'.$id.'/submissions', ['fields' => ['email' => 'a@example.com', 'message' => 'Hi']])->assertNotFound();
        app(PageService::class)->publish($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => 2, 'idempotencyKey' => self::key()]);

        $form = $this->getJson(self::BASE.'/forms/'.$id)->assertOk()->json('data');
        $this->assertSame(['email', 'message'], array_column($form['fields'], 'id'));
        $this->assertArrayNotHasKey('notifications', $form);

        $this->postJson(self::BASE.'/forms/'.$id.'/submissions', ['fields' => ['email' => 'nope']])->assertStatus(422)->assertJsonPath('error.code', 'validation_error');
        $this->postJson(self::BASE.'/forms/'.$id.'/submissions', ['fields' => 'not an object'])->assertStatus(422);
        $key = self::key();
        $this->postJson(self::BASE.'/forms/'.$id.'/submissions', ['fields' => ['email' => 'a@example.com', 'message' => 'Hi']], ['Idempotency-Key' => $key])
            ->assertCreated()->assertJsonPath('data.received', true)->assertJsonPath('data.confirmation.message', 'Thanks');
        $this->postJson(self::BASE.'/forms/'.$id.'/submissions', ['fields' => ['email' => 'a@example.com', 'message' => 'Hi']], ['Idempotency-Key' => $key])->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame(1, DB::table('form_submissions')->where('form_id', $id)->count());
        $this->assertStringNotContainsString('a@example.com', (string) DB::table('form_submissions')->where('form_id', $id)->value('payload'), 'stored encrypted');

        // Entries are personal data: a token with the scope and a role that may read them.
        $this->getJson(self::BASE.'/forms/'.$id.'/entries')->assertUnauthorized();
        $this->getJson(self::BASE.'/forms/'.$id.'/entries', $this->auth(['read:forms']))->assertForbidden();
        $editor = $this->addMember($this->f['siteId'], 'editor');
        $this->getJson(self::BASE.'/forms/'.$id.'/entries', $this->auth(['read:form_entries'], $editor))->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        $entries = $this->getJson(self::BASE.'/forms/'.$id.'/entries', $this->auth(['read:form_entries']))->assertOk();
        $this->assertSame('a@example.com', $entries->json('data.0.fields.0.value'));
        $this->getJson(self::BASE.'/forms/'.$id.'/entries/'.$entries->json('data.0.id'), $this->auth(['read:form_entries']))->assertOk()->assertJsonPath('data.fields.1.value', 'Hi');
        $this->assertSame('active', $this->getJson(self::BASE.'/forms', $this->auth(['read:forms']))->json('data.0.status'));
    }

    public function test_terms_list_with_published_counts_and_need_a_scope_to_change(): void
    {
        $terms = app(TermService::class);
        $tech = $terms->create($this->f['ctx'], 'category', ['name' => 'Technology', 'description' => 'Tech news']);
        $terms->create($this->f['ctx'], 'category', ['name' => 'AI', 'parentId' => $tech['id']]);
        app(ContentItems::class)->create($this->f['ctx'], 'post', ['title' => 'One', 'status' => 'published', 'terms' => ['category' => [$tech['id']]]]);
        app(ContentItems::class)->create($this->f['ctx'], 'post', ['title' => 'Draft', 'terms' => ['category' => [$tech['id']]]]);

        $list = $this->getJson(self::BASE.'/categories')->assertOk();
        $this->assertSame(['AI', 'Technology'], array_column($list->json('data'), 'name'));
        $this->assertSame(1, collect($list->json('data'))->firstWhere('name', 'Technology')['count'], 'drafts are not counted');
        $this->assertSame($tech['id'], collect($list->json('data'))->firstWhere('name', 'AI')['parent']);
        $this->assertSame(['Technology'], array_column($this->getJson(self::BASE.'/categories?hide_empty=true')->json('data'), 'name'));
        $this->assertSame(['Technology'], array_column($this->getJson(self::BASE.'/categories?parent=none')->json('data'), 'name'));
        $this->getJson(self::BASE.'/tags?parent=none')->assertStatus(400);
        $this->getJson(self::BASE.'/categories/'.$tech['id'])->assertOk()->assertJsonPath('data.slug', 'technology');

        $this->postJson(self::BASE.'/tags', ['name' => 'Laravel'])->assertUnauthorized();
        $created = $this->postJson(self::BASE.'/tags', ['name' => 'Laravel'], $this->auth(['write:tags']))->assertCreated()->assertJsonPath('data.slug', 'laravel');
        $this->postJson(self::BASE.'/tags', ['name' => 'Again', 'slug' => 'laravel'], $this->auth(['write:tags']))->assertStatus(409);
        $this->patchJson(self::BASE.'/tags/'.$created->json('data.id'), ['name' => 'Laravel 13'], $this->auth(['write:tags']))->assertOk()->assertJsonPath('data.name', 'Laravel 13');
        $this->deleteJson(self::BASE.'/tags/'.$created->json('data.id'), [], $this->auth(['write:tags']))->assertNoContent();
    }

    public function test_navigation_is_a_tree_of_published_menus_with_live_urls(): void
    {
        $about = app(ContentItems::class)->create($this->f['ctx'], 'page', ['title' => 'About', 'path' => '/about', 'status' => 'published'])['id'];
        $draft = app(ContentItems::class)->create($this->f['ctx'], 'page', ['title' => 'Hidden', 'path' => '/hidden'])['id'];
        $menus = app(MenuService::class);
        $menu = $menus->save($this->f['ctx'], ['baseVersion' => 0, 'requestKey' => self::key(), 'definition' => ['name' => 'Main', 'items' => [
            ['id' => 'about', 'label' => 'About', 'type' => 'page', 'pageId' => $about, 'href' => '', 'anchor' => '', 'parentId' => null],
            ['id' => 'hidden', 'label' => 'Hidden', 'type' => 'page', 'pageId' => $draft, 'href' => '', 'anchor' => '', 'parentId' => 'about'],
            ['id' => 'docs', 'label' => 'Docs', 'type' => 'url', 'pageId' => null, 'href' => 'https://example.com', 'anchor' => '', 'parentId' => null],
        ]]]);
        $this->assertSame([], $this->getJson(self::BASE.'/navigation')->json('data'), 'menu drafts are private');
        $menus->publish($this->f['ctx'], $menu['id'], ['expectedVersion' => 1, 'requestKey' => self::key()]);

        $tree = $this->getJson(self::BASE.'/navigation')->assertOk()->json('data.0');
        $this->assertSame(['About', 'Docs'], array_column($tree['items'], 'label'));
        $this->assertSame('/about', $tree['items'][0]['url']);
        $this->assertSame(['type' => 'page', 'id' => $about], $tree['items'][0]['target']);
        $this->assertNull($tree['items'][0]['children'][0]['url'], 'unpublished destinations have no URL');
        $this->getJson(self::BASE.'/navigation/'.$menu['id'])->assertOk()->assertJsonPath('data.name', 'Main');
    }

    public function test_users_are_authors_publicly_and_members_with_a_scope(): void
    {
        $writer = $this->addMember($this->f['siteId'], 'editor');
        $quiet = $this->addMember($this->f['siteId'], 'viewer');
        $post = app(ContentItems::class)->create($writer, 'post', ['title' => 'By the writer'])['id'];
        app(ContentItems::class)->update($this->f['ctx'], 'post', $post, ['status' => 'published']);
        $this->getJson(self::BASE.'/users/'.$writer->userId)->assertOk()->assertExactJson(['data' => ['id' => $writer->userId, 'name' => 'editor']]);
        $this->getJson(self::BASE.'/users/'.$quiet->userId)->assertNotFound();
        $this->getJson(self::BASE.'/users')->assertUnauthorized();
        $this->assertSame(3, $this->getJson(self::BASE.'/users', $this->auth(['read:users']))->assertOk()->json('meta.total'));
        $this->assertStringNotContainsString('@', $this->getJson(self::BASE.'/users', $this->auth(['read:users']))->getContent(), 'no email addresses');
    }

    public function test_seo_analysis_is_the_deterministic_report_of_the_live_page_or_draft(): void
    {
        $post = app(ContentItems::class)->create($this->f['ctx'], 'post', ['title' => 'Laravel performance', 'blocks' => [['type' => 'paragraph', 'text' => str_repeat('Laravel performance tips. ', 20)]]])['id'];
        $auth = $this->auth(['read:posts']);
        $this->getJson(self::BASE.'/posts/'.$post.'/seo-analysis', $auth)->assertNotFound();
        $draft = $this->getJson(self::BASE.'/posts/'.$post.'/seo-analysis?context=edit', $auth)->assertOk()->json('data');
        $this->assertIsInt($draft['score']);
        $this->assertNotEmpty($draft['checks']);
        app(ContentItems::class)->update($this->f['ctx'], 'post', $post, ['status' => 'published']);
        $live = $this->getJson(self::BASE.'/posts/'.$post.'/seo-analysis', $auth)->assertOk()->json('data');
        $this->assertSame($draft['score'], $live['score'], 'the same document gets the same score');
        $this->getJson(self::BASE.'/posts/'.$post.'/seo-analysis')->assertUnauthorized();
        $seo = $this->getJson(self::BASE.'/posts/'.$post)->json('data.seo');
        $this->assertSame(['index' => true, 'follow' => true], $seo['robots']);
        $this->assertSame('http://api.test/blog/laravel-performance', $seo['canonical']);
    }
}
