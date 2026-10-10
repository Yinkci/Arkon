<?php

namespace Tests\Feature;

use App\Arkon\Api\ApiTokens;
use Illuminate\Http\UploadedFile;
use Tests\DatabaseTestCase;

/**
 * Reference client: a headless blog built only from the public API. The publisher side writes
 * through the API with a token; the reader side is anonymous and never touches the database,
 * exactly what a separate frontend (Next.js, Astro, a mobile app) would do.
 */
class HeadlessBlogReferenceTest extends DatabaseTestCase
{
    private const BASE = 'http://blog.test/api/v1';

    public function test_a_complete_blog_can_be_built_from_the_api_alone(): void
    {
        $f = $this->siteFixture();
        $this->addDomain($f['siteId'], 'blog.test');
        $publisher = ['Authorization' => 'Bearer '.app(ApiTokens::class)->create($f['ctx'], 'Publisher', ['write:posts', 'write:media', 'write:categories', 'write:tags', 'read:posts'])['token']];

        // ── Publisher: taxonomy, an image and 15 posts, all through the API.
        $engineering = $this->postJson(self::BASE.'/categories', ['name' => 'Engineering', 'description' => 'How we build'], $publisher)->assertCreated()->json('data');
        $news = $this->postJson(self::BASE.'/categories', ['name' => 'News'], $publisher)->assertCreated()->json('data');
        $laravel = $this->postJson(self::BASE.'/tags', ['name' => 'Laravel'], $publisher)->assertCreated()->json('data');
        $image = imagecreatetruecolor(1600, 900);
        ob_start();
        imagejpeg($image);
        $cover = $this->post(self::BASE.'/media', ['file' => UploadedFile::fake()->createWithContent('cover.jpg', (string) ob_get_clean()), 'alt' => 'Server racks'], [...$publisher, 'Accept' => 'application/json'])->assertCreated()->json('data');
        foreach (range(1, 15) as $i) {
            $this->postJson(self::BASE.'/posts', [
                'title' => "Laravel tip {$i}", 'excerpt' => "Tip number {$i}.", 'status' => 'published',
                'categories' => [$i % 2 ? $engineering['id'] : $news['id']], 'tags' => $i <= 3 ? [$laravel['id']] : [],
                'featured_media' => $i === 15 ? $cover['id'] : null,
                'content' => ['blocks' => [['type' => 'paragraph', 'text' => "Body of tip {$i}."], ['type' => 'heading', 'level' => 2, 'text' => 'Details'], ['type' => 'paragraph', 'text' => 'More.']]],
            ], $publisher)->assertCreated();
        }
        $this->postJson(self::BASE.'/posts', ['title' => 'Unfinished draft'], $publisher)->assertCreated();

        // ── Reader (anonymous): the archive, newest first, 10 per page.
        $archive = $this->getJson(self::BASE.'/posts?per_page=10')->assertOk();
        $this->assertSame(15, $archive->json('meta.total'), 'the draft is not public');
        $this->assertSame(2, $archive->json('meta.total_pages'));
        $first = $archive->json('data.0');
        $this->assertSame('Laravel tip 15', $first['title']);
        foreach (['id', 'title', 'slug', 'link', 'excerpt', 'author', 'featured_media', 'categories', 'tags', 'published_at'] as $field) {
            $this->assertArrayHasKey($field, $first);
        }
        $this->assertSame('Server racks', $first['featured_media']['alt']);
        $this->assertNotEmpty($first['featured_media']['variants'], 'responsive images for srcset');
        $this->assertCount(5, $this->getJson($archive->json('links.next'))->assertOk()->json('data'));

        // A single post by slug, with rendered content and SEO for the page head.
        $single = $this->getJson(self::BASE.'/posts?slug=laravel-tip-3&include=content,seo')->assertOk()->json('data.0');
        $this->assertStringContainsString('Body of tip 3.', $single['content']['rendered']);
        $this->assertStringContainsString('<h2', $single['content']['rendered']);
        $this->assertNotEmpty($single['seo']['title']);
        $this->assertSame('http://blog.test/blog/laravel-tip-3', $single['seo']['canonical']);
        $this->assertSame(['Laravel'], array_column($single['tags'], 'name'));

        // Author, categories with counts, category and tag archives, search.
        $this->getJson(self::BASE.'/users/'.$single['author']['id'])->assertOk()->assertJsonPath('data.name', 'owner');
        $categories = $this->getJson(self::BASE.'/categories?hide_empty=true')->assertOk()->json('data');
        $this->assertSame(['Engineering' => 8, 'News' => 7], array_column($categories, 'count', 'name'));
        $this->assertSame(8, $this->getJson(self::BASE.'/posts?category=engineering')->json('meta.total'));
        $this->assertSame(3, $this->getJson(self::BASE.'/posts?tag=laravel')->json('meta.total'));
        $this->assertSame(['Laravel tip 12'], array_column($this->getJson(self::BASE.'/posts?search=tip 12')->json('data'), 'title'));
        $this->assertSame('Server racks', $this->getJson(self::BASE.'/media/'.$cover['id'])->assertOk()->json('data.alt'), 'the featured image is public once its post is');
        $this->getJson(self::BASE.'/site')->assertOk()->assertJsonPath('data.url', 'http://blog.test');
    }
}
