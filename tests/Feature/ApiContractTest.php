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
use App\Console\Commands\OpenApiCommand;
use App\Http\Api\OpenApi;
use Illuminate\Support\Facades\Route;
use Tests\DatabaseTestCase;
use Tests\Support\OpenApiSchema;

/**
 * The /api/v1 contract: the published OpenAPI document is current, documents every route (and
 * nothing else), and real responses match it, including members' and error responses. Internal
 * fields never appear. Changing a response shape fails here before it breaks a client.
 */
class ApiContractTest extends DatabaseTestCase
{
    private const BASE = 'http://contract.test/api/v1';

    private array $spec;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spec = Json::toArray(json_decode((string) file_get_contents(resource_path('api/openapi.json')), true));
    }

    public function test_the_published_document_is_generated_from_the_code(): void
    {
        $this->assertSame(OpenApiCommand::encode(OpenApi::build()), file_get_contents(resource_path('api/openapi.json')), 'Run php artisan arkon:openapi and review the diff.');
        $this->getJson(self::BASE.'/openapi.json')->assertNotFound('only served at a site address');
    }

    public function test_every_route_is_documented_and_every_documented_operation_exists(): void
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1')) {
                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $routes[] = strtolower($method).' '.(substr($route->uri(), strlen('api/v1')) ?: '/');
                }
            }
        }
        $documented = [];
        foreach ($this->spec['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $documented[] = $method.' '.$path;
            }
        }
        sort($routes);
        sort($documented);
        $this->assertSame($documented, $routes);
    }

    public function test_responses_match_the_document_and_hide_internals(): void
    {
        $f = $this->siteFixture();
        $this->addDomain($f['siteId'], 'contract.test');
        $member = ['Authorization' => 'Bearer '.app(ApiTokens::class)->create($f['ctx'], 'Contract', ['read:posts', 'read:pages', 'read:media', 'read:forms', 'read:form_entries', 'read:users'])['token']];
        $category = app(TermService::class)->create($f['ctx'], 'category', ['name' => 'News', 'description' => 'Updates']);
        app(TermService::class)->create($f['ctx'], 'category', ['name' => 'Local', 'parentId' => $category['id']]);
        $tag = app(TermService::class)->create($f['ctx'], 'tag', ['name' => 'Laravel']);
        $image = imagecreatetruecolor(1000, 600);
        ob_start();
        imagejpeg($image);
        $cover = app(MediaService::class)->upload($f['ctx'], (string) ob_get_clean(), 'cover.jpg');
        $post = app(ContentItems::class)->create($f['ctx'], 'post', ['title' => 'Contract', 'excerpt' => 'Short', 'featuredMediaId' => $cover['id'], 'terms' => ['category' => [$category['id']], 'tag' => [$tag['id']]], 'blocks' => [['type' => 'paragraph', 'text' => 'Body']], 'status' => 'published'])['id'];
        $draft = app(ContentItems::class)->create($f['ctx'], 'post', ['title' => 'Draft'])['id'];
        $form = app(FormService::class)->save($f['ctx'], ['baseVersion' => 0, 'requestKey' => self::key(), 'definition' => ['name' => 'Contact', 'submitLabel' => 'Send', 'successMessage' => 'Thanks', 'fields' => [['id' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true]]]]);
        app(FormService::class)->publish($f['ctx'], $form['id'], ['expectedVersion' => 1, 'requestKey' => self::key()]);
        $node = ['id' => 'form_123', 'type' => 'form', 'version' => 4, 'props' => ['form' => ['id' => $form['id']], 'style' => new \stdClass]];
        app(PageService::class)->saveDraft($f['ctx'], ['pageId' => $f['pageId'], 'baseVersion' => 1, 'saveKey' => self::key(), 'operations' => Json::decode(Json::encode([['op' => 'insertNode', 'parentId' => $f['document']['root'], 'index' => 1, 'nodes' => [$node]]]))]);
        app(PageService::class)->publish($f['ctx'], ['pageId' => $f['pageId'], 'expectedVersion' => 2, 'idempotencyKey' => self::key()]);
        $menu = app(MenuService::class)->save($f['ctx'], ['baseVersion' => 0, 'requestKey' => self::key(), 'definition' => ['name' => 'Main', 'items' => [['id' => 'home', 'label' => 'Home', 'type' => 'page', 'pageId' => $f['pageId'], 'href' => '', 'anchor' => '', 'parentId' => null], ['id' => 'news', 'label' => 'News', 'type' => 'page', 'pageId' => $post, 'href' => '', 'anchor' => '', 'parentId' => 'home']]]]);
        app(MenuService::class)->publish($f['ctx'], $menu['id'], ['expectedVersion' => 1, 'requestKey' => self::key()]);
        $this->postJson(self::BASE.'/forms/'.$form['id'].'/submissions', ['fields' => ['email' => 'reader@example.com']])->assertCreated();
        $entry = $this->getJson(self::BASE.'/forms/'.$form['id'].'/entries', $member)->json('data.0.id');

        $checks = [
            ['/', [], 'get /', 200], ['/site', [], 'get /site', 200],
            ['/posts', [], 'get /posts', 200], ['/posts?include=content,seo', [], 'get /posts', 200], ['/posts?status=any', $member, 'get /posts', 200],
            ["/posts/{$post}", [], 'get /posts/{id}', 200], ["/posts/{$draft}", $member, 'get /posts/{id}', 200], ["/posts/{$post}/seo-analysis", $member, 'get /posts/{id}/seo-analysis', 200],
            ['/pages', [], 'get /pages', 200], ["/pages/{$f['pageId']}", [], 'get /pages/{id}', 200],
            ['/categories', [], 'get /categories', 200], ["/categories/{$category['id']}", [], 'get /categories/{id}', 200], ['/tags', [], 'get /tags', 200],
            ['/media', [], 'get /media', 200], ['/media', $member, 'get /media', 200], ["/media/{$cover['id']}", [], 'get /media/{id}', 200],
            ['/forms', $member, 'get /forms', 200], ["/forms/{$form['id']}", [], 'get /forms/{id}', 200],
            ["/forms/{$form['id']}/entries", $member, 'get /forms/{id}/entries', 200], ["/forms/{$form['id']}/entries/{$entry}", $member, 'get /forms/{id}/entries/{entry}', 200],
            ['/navigation', [], 'get /navigation', 200], ['/navigation/'.$menu['id'], [], 'get /navigation/{menu}', 200],
            ['/users', $member, 'get /users', 200], ["/users/{$f['ctx']->userId}", [], 'get /users/{id}', 200],
            ["/posts/{$draft}", [], 'get /posts/{id}', 404], ['/posts?status=draft', [], 'get /posts', 401], ['/posts?per_page=1000', [], 'get /posts', 400],
        ];
        foreach ($checks as [$url, $headers, $operation, $status]) {
            $response = $this->getJson(self::BASE.$url, $headers)->assertStatus($status);
            [$method, $path] = explode(' ', $operation, 2);
            $documented = $this->spec['paths'][$path][$method]['responses'][(string) $status] ?? $this->spec['paths'][$path][$method]['responses'][$status] ?? null;
            $this->assertNotNull($documented, "{$operation} does not document {$status}");
            $schema = isset($documented['$ref']) ? $this->spec['components']['responses']['Error']['content']['application/json']['schema'] : $documented['content']['application/json']['schema'];
            OpenApiSchema::assert($this->spec, $schema, $response->json(), $url);
            $body = $response->getContent();
            foreach (['"document"', 'builder', 'storage_key', 'token_hash', 'request_key', 'password', 'payload', 'render_inputs', 'notification', 'data-ak-'] as $internal) {
                $this->assertStringNotContainsString($internal, $body, "{$url} leaks {$internal}");
            }
        }
        $this->assertStringNotContainsString('reader@example.com', $this->getJson(self::BASE.'/forms/'.$form['id'])->getContent());
    }
}
