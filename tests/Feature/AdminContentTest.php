<?php

namespace Tests\Feature;

use App\Arkon\Content\ContentItems;
use App\Arkon\Content\TermService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\DatabaseTestCase;

/** The admin screens for posts, categories and tags, post details in the editor, and API tokens. */
class AdminContentTest extends DatabaseTestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->f = $this->siteFixture();
        $this->addDomain($this->f['siteId'], 'admin.test');
        $this->actingAs(User::findOrFail($this->f['ctx']->userId));
    }

    public function test_posts_and_pages_share_one_screen_and_list_their_own_kind(): void
    {
        $post = app(ContentItems::class)->create($this->f['ctx'], 'post', ['title' => 'Hello'])['id'];
        $this->get('/admin/posts')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Pages')->where('type.kind', 'post')->where('type.pathPrefix', '/blog')->has('pages', 1)->where('pages.0.id', $post));
        $this->get('/admin/pages')->assertOk()->assertInertia(fn (Assert $p) => $p->where('type.kind', 'page')->has('pages', 1)->where('pages.0.id', $this->f['pageId']));
        // The admin create endpoint makes a post when asked, and ignores anything but title, URL and type.
        $created = $this->postJson('/admin/api/pages', ['title' => 'From admin', 'path' => '/blog/from-admin', 'kind' => 'post', 'requestKey' => self::key(), 'document' => ['bogus' => true]])->assertOk();
        $this->assertSame('post', DB::table('pages')->where('id', $created->json('data.pageId'))->value('kind'));
    }

    public function test_the_editor_gets_the_kind_details_and_terms_and_saves_details(): void
    {
        $tag = app(TermService::class)->create($this->f['ctx'], 'tag', ['name' => 'Laravel']);
        $post = app(ContentItems::class)->create($this->f['ctx'], 'post', ['title' => 'Hello', 'excerpt' => 'Short'])['id'];
        $this->get('/admin/editor/'.$post)->assertOk()->assertInertia(fn (Assert $p) => $p->where('init.content.kind', 'post')->where('init.content.details', true)
            ->where('init.content.values.excerpt', 'Short')->where('init.content.taxonomies.1.terms.0.name', 'Laravel'));
        $this->get('/admin/editor/'.$this->f['pageId'])->assertInertia(fn (Assert $p) => $p->where('init.content.kind', 'page')->where('init.content.details', false)->where('init.content.taxonomies', []));

        $this->postJson("/admin/api/pages/{$post}/details", ['excerpt' => 'Longer', 'terms' => ['tag' => [$tag['id']]]])->assertOk()->assertJsonPath('data.excerpt', 'Longer')->assertJsonPath('data.terms.tag.0', $tag['id']);
        $this->postJson("/admin/api/pages/{$this->f['pageId']}/details", ['excerpt' => 'Pages have none'])->assertStatus(422);
        $viewer = $this->addMember($this->f['siteId'], 'viewer');
        $this->actingAs(User::findOrFail($viewer->userId))->postJson("/admin/api/pages/{$post}/details", ['excerpt' => 'x'])->assertForbidden();
    }

    public function test_terms_screen_and_endpoints_follow_the_role(): void
    {
        $this->get('/admin/posts/categories')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Terms')->where('taxonomy', 'category'));
        $this->get('/admin/posts/tags')->assertOk()->assertInertia(fn (Assert $p) => $p->where('taxonomy', 'tag'));
        $id = $this->postJson('/admin/api/terms/category', ['name' => 'News'])->assertOk()->json('data.id');
        $this->postJson("/admin/api/terms/category/{$id}", ['name' => 'Updates'])->assertOk()->assertJsonPath('data.name', 'Updates');
        $this->postJson('/admin/api/terms/genre', ['name' => 'x'])->assertNotFound('unknown taxonomies have no route');

        $editor = $this->addMember($this->f['siteId'], 'editor');
        $this->actingAs(User::findOrFail($editor->userId));
        $this->postJson('/admin/api/terms/tag', ['name' => 'php'])->assertOk();
        $this->postJson("/admin/api/terms/category/{$id}/delete")->assertForbidden();
        $this->actingAs(User::findOrFail($this->f['ctx']->userId))->postJson("/admin/api/terms/category/{$id}/delete")->assertOk();
        $this->assertNull(DB::table('terms')->where('id', $id)->first());
    }

    public function test_members_create_tokens_shown_once_and_revoke_them(): void
    {
        $this->get('/admin/settings/developer')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Developer')->where('baseUrl', 'http://admin.test/api/v1')->has('scopes.read:posts'));
        $created = $this->postJson('/admin/api/tokens', ['name' => 'Frontend', 'scopes' => ['read:posts']])->assertOk();
        $token = $created->json('data.token');
        $this->assertStringStartsWith('arkon_pat_', $token);
        $this->get('/admin/settings/developer')->assertInertia(fn (Assert $p) => $p->has('tokens', 1)->where('tokens.0.hint', '…'.substr($token, -4))->missing('tokens.0.token'));
        $this->assertStringNotContainsString($token, (string) DB::table('api_tokens')->value('token_hash'));
        $this->getJson('http://admin.test/api/v1/posts?status=draft', ['Authorization' => 'Bearer '.$token])->assertOk();

        $this->postJson('/admin/api/tokens', ['name' => 'Too much', 'scopes' => ['root']])->assertStatus(422);
        $this->postJson('/admin/api/tokens/'.$created->json('data.item.id').'/revoke')->assertOk();
        $this->getJson('http://admin.test/api/v1/posts?status=draft', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
        // A viewer manages their own tokens too (they cannot do more than read).
        $viewer = $this->addMember($this->f['siteId'], 'viewer');
        $this->actingAs(User::findOrFail($viewer->userId))->postJson('/admin/api/tokens', ['name' => 'Reader', 'scopes' => ['read:posts']])->assertOk();
    }
}
