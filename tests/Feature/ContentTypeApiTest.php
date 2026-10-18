<?php

namespace Tests\Feature;

use App\Arkon\Api\ApiTokens;
use App\Arkon\Content\ContentItems;
use App\Arkon\Content\ContentTypeService;
use App\Arkon\Content\EntryFields;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Pages\PageService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * /api/v1/content/{type}: entries of the content types a site defined, named by the type's key,
 * with their field values, under per-type scopes (read:content.{type}, write:content.{type}).
 */
final class ContentTypeApiTest extends DatabaseTestCase
{
    private const BASE = 'http://api.test/api/v1';

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
        $this->addDomain($this->f['siteId'], 'api.test');
        app(ContentTypeService::class)->create($this->f['ctx'], [
            'key' => 'service', 'name' => 'Services', 'singular' => 'Service', 'description' => '', 'icon' => 'grid',
            'pathPrefix' => '/services', 'inMenu' => true, 'menuOrder' => 10, 'details' => false, 'taxonomies' => [],
            'fields' => [
                ['key' => 'headline', 'label' => 'Headline', 'type' => 'text', 'settings' => ['required' => true]],
                ['key' => 'price', 'label' => 'Price', 'type' => 'decimal', 'settings' => []],
                ['key' => 'featured', 'label' => 'Featured', 'type' => 'boolean', 'settings' => [], 'default' => true],
                ['key' => 'cost', 'label' => 'Internal cost', 'type' => 'decimal', 'settings' => ['private' => true]],
            ],
        ]);
    }

    private function token(array $scopes): array
    {
        return ['Authorization' => 'Bearer '.app(ApiTokens::class)->create($this->f['ctx'], 'Test', $scopes)['token']];
    }

    private function publish(string $id): void
    {
        $version = (int) DB::table('page_drafts')->where('page_id', $id)->value('version');
        app(PageService::class)->publish($this->f['ctx'], ['pageId' => $id, 'expectedVersion' => $version, 'idempotencyKey' => self::key()]);
    }

    public function test_visitors_read_published_entries_with_their_public_published_values(): void
    {
        $id = app(ContentItems::class)->create($this->f['ctx'], 'service', ['title' => 'Web development', 'fields' => ['headline' => 'Sites that work', 'price' => 1500.5, 'cost' => 900]])['id'];
        $draft = app(ContentItems::class)->create($this->f['ctx'], 'service', ['title' => 'Unreleased'])['id'];
        $this->publish($id);
        app(EntryFields::class)->update($this->f['ctx'], $id, ['headline' => 'Not published yet']);

        $list = $this->getJson(self::BASE.'/content/service?include=fields')->assertOk();
        $this->assertSame([$id], array_column($list->json('data'), 'id'), 'drafts are not public');
        $this->assertNotContains($draft, array_column($list->json('data'), 'id'));
        $one = $this->getJson(self::BASE.'/content/service/'.$id)->assertOk()->json('data');
        $this->assertSame('service', $one['type']);
        $this->assertSame(['headline' => 'Sites that work', 'price' => 1500.5, 'featured' => true], $one['fields'], 'typed, published, defaults applied, private left out');
    }

    public function test_writes_need_the_types_own_scope_and_go_through_the_field_rules(): void
    {
        $this->postJson(self::BASE.'/content/service', ['title' => 'SEO'])->assertUnauthorized();
        $this->postJson(self::BASE.'/content/service', ['title' => 'SEO'], $this->token(['write:posts']))->assertForbidden()->assertJsonPath('error.required_scope', 'write:content.service');

        $auth = $this->token(['write:content.service', 'read:content.service']);
        $this->postJson(self::BASE.'/content/service', ['title' => 'SEO', 'fields' => ['price' => 'cheap']], $auth)->assertStatus(422);
        $created = $this->postJson(self::BASE.'/content/service', ['title' => 'SEO', 'fields' => ['headline' => 'Be found', 'price' => '300']], $auth)
            ->assertCreated()->json('data');
        $this->assertSame(300.0, $created['fields']['price'] + 0.0);
        $this->assertSame('/services/seo', $created['path']);

        // Publishing applies the required rule, through the API like everywhere else.
        $this->patchJson(self::BASE.'/content/service/'.$created['id'], ['fields' => ['headline' => null]], $auth)->assertOk();
        $this->patchJson(self::BASE.'/content/service/'.$created['id'], ['status' => 'published'], $auth)->assertStatus(422);
        $this->patchJson(self::BASE.'/content/service/'.$created['id'], ['status' => 'published', 'fields' => ['headline' => 'Be found']], $auth)->assertOk()->assertJsonPath('data.status', 'published');
    }

    public function test_unknown_inactive_and_built_in_kinds_are_not_served_here(): void
    {
        $this->getJson(self::BASE.'/content/nothing')->assertNotFound();
        $this->getJson(self::BASE.'/content/post')->assertNotFound();
        app(ContentTypeService::class)->setActive($this->f['ctx'], 'service', false);
        $this->getJson(self::BASE.'/content/service')->assertNotFound();
    }

    public function test_the_index_and_tokens_know_the_sites_own_types(): void
    {
        $index = $this->getJson(self::BASE.'/')->assertOk()->json('data');
        $this->assertContains(self::BASE.'/content/service', $index['resources']);
        $this->assertArrayHasKey('read:content.service', $index['scopes']);

        $this->expectException(ValidationException::class);
        app(ApiTokens::class)->create($this->f['ctx'], 'Wrong', ['read:content.nothing']);
    }
}
