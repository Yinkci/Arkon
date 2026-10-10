<?php

namespace Tests\Feature;

use App\Arkon\Api\ApiTokens;
use App\Arkon\Content\ContentItems;
use App\Arkon\Content\TermService;
use App\Arkon\Sites\SiteContext;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/** /api/v1: authentication, scopes, content reads and writes, errors, caching and site isolation. */
class PublicApiTest extends DatabaseTestCase
{
    private array $f;

    private const BASE = 'http://api.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
        $this->addDomain($this->f['siteId'], 'api.test');
    }

    private function token(array $scopes, ?SiteContext $ctx = null): string
    {
        return app(ApiTokens::class)->create($ctx ?? $this->f['ctx'], 'Test', $scopes)['token'];
    }

    private function auth(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    private function makePost(string $title, array $extra = []): string
    {
        return app(ContentItems::class)->create($this->f['ctx'], 'post', ['title' => $title, ...$extra])['id'];
    }

    public function test_anonymous_requests_see_published_content_only(): void
    {
        $live = $this->makePost('Live post', ['status' => 'published', 'blocks' => [['type' => 'paragraph', 'text' => 'Hello readers']]]);
        $draft = $this->makePost('Draft post');
        $trashed = $this->makePost('Trashed post', ['status' => 'published']);
        app(ContentItems::class)->trash($this->f['ctx'], 'post', $trashed);

        $list = $this->getJson(self::BASE.'/posts')->assertOk();
        $this->assertSame([$live], array_column($list->json('data'), 'id'));
        $this->assertSame(['page' => 1, 'per_page' => 10, 'total' => 1, 'total_pages' => 1], $list->json('meta'));
        $this->assertArrayNotHasKey('content', $list->json('data.0'), 'collections leave content out unless included');

        $one = $this->getJson(self::BASE.'/posts/'.$live)->assertOk();
        $this->assertStringContainsString('Hello readers', $one->json('data.content.rendered'));
        $this->assertStringNotContainsString('data-ak-', $one->json('data.content.rendered'));
        $this->assertSame('published', $one->json('data.status'));
        $this->assertSame('http://api.test/blog/live-post', $one->json('data.link'));
        $this->assertArrayNotHasKey('version', $one->json('data'), 'editing details are for members');

        $this->getJson(self::BASE.'/posts/'.$draft)->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->getJson(self::BASE.'/posts/'.$trashed)->assertNotFound();
        $this->getJson(self::BASE.'/posts?status=draft')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
        // Pages and posts are separate collections of the same model.
        $this->assertSame([], $this->getJson(self::BASE.'/pages')->json('data'));
    }

    public function test_the_live_title_and_details_stay_until_the_draft_is_published(): void
    {
        $id = $this->makePost('First title', ['status' => 'published', 'excerpt' => 'Live excerpt']);
        app(ContentItems::class)->update($this->f['ctx'], 'post', $id, ['title' => 'Renamed in draft', 'excerpt' => 'Draft excerpt']);
        $this->getJson(self::BASE.'/posts/'.$id)->assertJsonPath('data.title', 'First title')->assertJsonPath('data.excerpt', 'Live excerpt');

        $token = $this->token(['read:posts']);
        $this->getJson(self::BASE.'/posts/'.$id.'?context=edit', $this->auth($token))
            ->assertJsonPath('data.title', 'Renamed in draft')->assertJsonPath('data.excerpt', 'Draft excerpt')->assertJsonPath('data.has_unpublished_changes', true);
    }

    public function test_tokens_read_drafts_and_trash_within_their_scopes(): void
    {
        $draft = $this->makePost('Draft post');
        $token = $this->token(['read:posts']);
        $this->assertSame([$draft], array_column($this->getJson(self::BASE.'/posts?status=draft', $this->auth($token))->assertOk()->json('data'), 'id'));
        $this->getJson(self::BASE.'/posts/'.$draft, $this->auth($token))->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.version', 1);
        $this->getJson(self::BASE.'/pages?status=draft', $this->auth($token))->assertForbidden()->assertJsonPath('error.code', 'insufficient_scope')->assertJsonPath('error.required_scope', 'read:pages');
        $this->getJson(self::BASE.'/posts', $this->auth($token))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_invalid_revoked_expired_and_foreign_tokens_are_refused(): void
    {
        $this->getJson(self::BASE.'/posts', ['Authorization' => 'Bearer arkon_pat_nope'])->assertUnauthorized()->assertJsonPath('error.code', 'invalid_token');
        $this->getJson(self::BASE.'/posts', ['Authorization' => 'Basic abc'])->assertUnauthorized();

        $other = $this->siteFixture();
        $this->addDomain($other['siteId'], 'other.test');
        $foreign = $this->token(['read:posts'], $other['ctx']);
        $this->getJson(self::BASE.'/posts?status=draft', $this->auth($foreign))->assertUnauthorized()->assertJsonPath('error.code', 'invalid_token');

        $created = app(ApiTokens::class)->create($this->f['ctx'], 'Soon revoked', ['read:posts']);
        $this->getJson(self::BASE.'/posts?status=draft', $this->auth($created['token']))->assertOk();
        app(ApiTokens::class)->revoke($this->f['ctx'], $created['item']['id']);
        $this->getJson(self::BASE.'/posts?status=draft', $this->auth($created['token']))->assertUnauthorized();

        $expiring = app(ApiTokens::class)->create($this->f['ctx'], 'Expiring', ['read:posts'], now()->addMinute()->toIso8601String());
        DB::table('api_tokens')->where('id', $expiring['item']['id'])->update(['expires_at' => DB::raw("now() - interval '1 second'")]);
        $this->getJson(self::BASE.'/posts?status=draft', $this->auth($expiring['token']))->assertUnauthorized();

        // A member who leaves the site loses every token at once.
        $viewer = $this->addMember($this->f['siteId'], 'viewer');
        $left = $this->token(['read:posts'], $viewer);
        DB::table('site_members')->where('user_id', $viewer->userId)->delete();
        $this->getJson(self::BASE.'/posts?status=draft', $this->auth($left))->assertUnauthorized();
    }

    public function test_a_scope_never_grants_more_than_the_role(): void
    {
        $editor = $this->addMember($this->f['siteId'], 'editor');
        $token = $this->token(['write:posts', 'read:posts'], $editor);
        $created = $this->postJson(self::BASE.'/posts', ['title' => 'By an editor'], $this->auth($token))->assertCreated()->assertJsonPath('data.status', 'draft');
        $this->patchJson(self::BASE.'/posts/'.$created->json('data.id'), ['status' => 'published'], $this->auth($token))->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        $this->postJson(self::BASE.'/posts', ['title' => 'And publish', 'status' => 'published'], $this->auth($token))->assertForbidden();
        $this->assertSame(1, DB::table('pages')->where('kind', 'post')->count(), 'the refused create-and-publish created nothing');

        $viewer = $this->addMember($this->f['siteId'], 'viewer');
        $this->postJson(self::BASE.'/posts', ['title' => 'By a viewer'], $this->auth($this->token(['write:posts'], $viewer)))->assertForbidden();
    }

    public function test_writes_create_update_publish_trash_restore_and_delete_through_the_domain(): void
    {
        $token = $this->token(['write:posts', 'read:posts']);
        $category = app(TermService::class)->create($this->f['ctx'], 'category', ['name' => 'Engineering']);
        $key = self::key();
        $body = ['title' => 'From the API', 'slug' => 'from-api', 'excerpt' => 'Short', 'categories' => [$category['id']], 'content' => ['blocks' => [
            ['type' => 'heading', 'level' => 2, 'text' => 'Why'], ['type' => 'paragraph', 'text' => 'Because.'],
        ]]];
        $created = $this->postJson(self::BASE.'/posts', $body, [...$this->auth($token), 'Idempotency-Key' => $key])->assertCreated();
        $id = $created->json('data.id');
        $created->assertHeader('Location', self::BASE.'/posts/'.$id)->assertJsonPath('data.path', '/blog/from-api')->assertJsonPath('data.categories.0.name', 'Engineering');
        $this->assertStringContainsString('Because.', $created->json('data.content.rendered'));
        // An exact retry returns the same post; the key with other content is a conflict.
        $this->postJson(self::BASE.'/posts', $body, [...$this->auth($token), 'Idempotency-Key' => $key])->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson(self::BASE.'/posts', [...$body, 'title' => 'Other'], [...$this->auth($token), 'Idempotency-Key' => $key])->assertStatus(409)->assertJsonPath('error.code', 'conflict');
        $this->assertSame('human', DB::table('page_revisions')->where('page_id', $id)->value('source'), 'API changes are the member\'s own, not AI');
        $this->assertSame('api', DB::table('audit_logs')->where('target_id', $id)->where('action', 'page.create')->value('actor_via'));

        $this->patchJson(self::BASE.'/posts/'.$id, ['version' => 9, 'title' => 'Stale'], $this->auth($token))->assertStatus(409)->assertJsonPath('error.code', 'version_conflict')->assertJsonPath('error.current_version', 1);
        $this->patchJson(self::BASE.'/posts/'.$id, ['version' => 1, 'title' => 'Published from the API', 'status' => 'published'], $this->auth($token))->assertOk()->assertJsonPath('data.status', 'published');
        $this->getJson(self::BASE.'/posts/'.$id)->assertOk()->assertJsonPath('data.title', 'Published from the API');

        $this->deleteJson(self::BASE.'/posts/'.$id, [], $this->auth($token))->assertNoContent();
        $this->getJson(self::BASE.'/posts/'.$id)->assertNotFound();
        $this->getJson(self::BASE.'/posts?status=trash', $this->auth($token))->assertJsonPath('data.0.id', $id);
        $this->postJson(self::BASE.'/posts/'.$id.'/restore', [], $this->auth($token))->assertOk()->assertJsonPath('data.status', 'draft');
        $this->deleteJson(self::BASE.'/posts/'.$id.'?force=true', [], $this->auth($token))->assertStatus(409)->assertJsonPath('error.code', 'conflict');
        $this->deleteJson(self::BASE.'/posts/'.$id, [], $this->auth($token))->assertNoContent();
        $this->deleteJson(self::BASE.'/posts/'.$id.'?force=true', [], $this->auth($token))->assertNoContent();
        $this->assertNotNull(DB::table('pages')->where('id', $id)->value('purged_at'));
    }

    public function test_validation_errors_name_their_fields(): void
    {
        $token = $this->token(['write:posts']);
        $this->postJson(self::BASE.'/posts', ['slug' => 'no-title'], $this->auth($token))->assertStatus(422)->assertJsonPath('error.code', 'validation_error')->assertJsonPath('error.fields.title.0', 'A title is required.');
        $this->postJson(self::BASE.'/posts', ['title' => 'X', 'builder_json' => []], $this->auth($token))->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['builder_json']]]);
        $this->postJson(self::BASE.'/posts', ['title' => 'X', 'content' => ['blocks' => [['type' => 'script', 'text' => 'alert(1)']]]], $this->auth($token))->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['content.blocks.0.type']]]);
        $this->postJson(self::BASE.'/posts', ['title' => 'X', 'content' => ['blocks' => [['type' => 'button', 'label' => 'Go', 'href' => 'javascript:alert(1)']]]], $this->auth($token))->assertStatus(422);
        $this->postJson(self::BASE.'/posts', ['title' => 'X', 'slug' => 'Not A Slug'], $this->auth($token))->assertStatus(422)->assertJsonPath('error.fields.slug.0', 'A slug uses lowercase letters, digits and single hyphens.');
        $this->postJson(self::BASE.'/posts', ['title' => 'X', 'categories' => ['not-an-id']], $this->auth($token))->assertStatus(422);
        $this->postJson(self::BASE.'/pages', ['title' => 'Page', 'excerpt' => 'Pages have none'], $this->auth($this->token(['write:pages'])))->assertStatus(422)->assertJsonPath('error.fields.excerpt.0', 'This field is not accepted.');
        $this->assertSame(0, DB::table('pages')->where('kind', 'post')->count());
    }

    public function test_pagination_filters_sorting_search_and_slug_lookup(): void
    {
        $terms = app(TermService::class);
        $tech = $terms->create($this->f['ctx'], 'category', ['name' => 'Technology']);
        $laravel = $terms->create($this->f['ctx'], 'tag', ['name' => 'Laravel']);
        $ids = [];
        foreach (range(1, 12) as $i) {
            $ids[$i] = $this->makePost("Post {$i}", ['status' => 'published', 'terms' => $i % 3 === 0 ? ['category' => [$tech['id']], 'tag' => [$laravel['id']]] : []]);
            DB::table('pages')->where('id', $ids[$i])->update(['first_published_at' => now()->subDays(20 - $i)]);
        }

        $page2 = $this->getJson(self::BASE.'/posts?per_page=5&page=2')->assertOk();
        $this->assertSame(['page' => 2, 'per_page' => 5, 'total' => 12, 'total_pages' => 3], $page2->json('meta'));
        $this->assertSame([$ids[7], $ids[6], $ids[5], $ids[4], $ids[3]], array_column($page2->json('data'), 'id'), 'newest first by default');
        $this->assertStringContainsString('page=3', $page2->json('links.next'));
        $this->assertStringContainsString('page=1', $page2->json('links.prev'));

        $this->assertCount(4, $this->getJson(self::BASE.'/posts?category=technology&per_page=100')->json('data'));
        $this->assertCount(4, $this->getJson(self::BASE.'/posts?tag='.$laravel['id'])->json('data'));
        $this->assertSame([], $this->getJson(self::BASE.'/posts?category=unknown-slug')->json('data'));
        $this->assertSame([$ids[11]], array_column($this->getJson(self::BASE.'/posts?search=Post 11')->json('data'), 'id'));
        $this->assertSame([$ids[2]], array_column($this->getJson(self::BASE.'/posts?slug=post-2')->json('data'), 'id'));
        $this->assertSame($ids[1], $this->getJson(self::BASE.'/posts?sort=published_at&per_page=1')->json('data.0.id'));
        $this->assertSame($ids[1], $this->getJson(self::BASE.'/posts?sort=title&per_page=1')->json('data.0.id'));
        $this->assertSame(12, $this->getJson(self::BASE.'/posts?author='.$this->f['ctx']->userId)->json('meta.total'));
        $this->assertSame(2, $this->getJson(self::BASE.'/posts?after='.urlencode(now()->subDays(10)->toIso8601String()))->json('meta.total'));
        $withContent = $this->getJson(self::BASE.'/posts?per_page=2&include=content,seo')->json('data.0');
        $this->assertArrayHasKey('rendered', $withContent['content']);
        $this->assertArrayHasKey('robots', $withContent['seo']);

        $this->getJson(self::BASE.'/posts?per_page=500')->assertStatus(400)->assertJsonPath('error.code', 'invalid_parameter');
        $this->getJson(self::BASE.'/posts?sort=password')->assertStatus(400)->assertJsonPath('error.fields.sort.0', 'Unsupported sort field.');
        $this->getJson(self::BASE.'/posts?include=everything')->assertStatus(400);
    }

    public function test_anonymous_reads_revalidate_with_etags(): void
    {
        $this->makePost('Cached', ['status' => 'published']);
        $first = $this->getJson(self::BASE.'/posts')->assertOk()->assertHeader('Cache-Control', 'max-age=0, must-revalidate, public');
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->getJson(self::BASE.'/posts', ['If-None-Match' => $etag])->assertStatus(304);
        $this->makePost('New', ['status' => 'published']);
        $this->getJson(self::BASE.'/posts', ['If-None-Match' => $etag])->assertOk();
    }

    public function test_sites_are_isolated_by_host(): void
    {
        $other = $this->siteFixture();
        $this->addDomain($other['siteId'], 'other.test');
        $theirs = app(ContentItems::class)->create($other['ctx'], 'post', ['title' => 'Elsewhere', 'status' => 'published'])['id'];
        $this->getJson(self::BASE.'/posts/'.$theirs)->assertNotFound();
        $this->assertSame([$theirs], array_column($this->getJson('http://other.test/api/v1/posts')->json('data'), 'id'));
        $token = $this->token(['write:posts']);
        $this->patchJson('http://other.test/api/v1/posts/'.$theirs, ['title' => 'Hijacked'], $this->auth($token))->assertUnauthorized();
        $this->patchJson(self::BASE.'/posts/'.$theirs, ['title' => 'Hijacked'], $this->auth($token))->assertNotFound();
        $this->getJson('http://unknown.test/api/v1/posts')->assertNotFound()->assertJsonPath('error.code', 'site_not_found');
    }

    public function test_unknown_routes_methods_and_rate_limits_use_the_error_shape(): void
    {
        $this->getJson(self::BASE.'/nothing-here')->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->putJson(self::BASE.'/posts', [])->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
        config(['arkon.api.limits.anonymous' => 2]);
        $this->getJson(self::BASE.'/site')->assertOk();
        $this->getJson(self::BASE.'/site')->assertOk();
        $this->getJson(self::BASE.'/site')->assertStatus(429)->assertJsonPath('error.code', 'rate_limited')->assertHeader('Retry-After');
    }

    public function test_api_responses_never_set_cookies_or_start_sessions(): void
    {
        $response = $this->getJson(self::BASE.'/site')->assertOk();
        $this->assertSame([], $response->headers->getCookies());
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('*', $this->call('OPTIONS', self::BASE.'/posts', [], [], [], ['HTTP_ORIGIN' => 'https://frontend.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET'])->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_site_details_expose_nothing_secret(): void
    {
        $data = $this->getJson(self::BASE.'/site')->assertOk()->json('data');
        $this->assertSame(['name', 'description', 'url', 'language', 'timezone', 'logo', 'favicon', 'organization'], array_keys($data));
        $this->assertSame('http://api.test', $data['url']);
        $index = $this->getJson(self::BASE)->assertOk()->json('data');
        $this->assertSame('v1', $index['version']);
        $this->assertContains('http://api.test/api/v1/posts', $index['resources']);
    }
}
