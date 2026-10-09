<?php

namespace Tests\Feature;

use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Media\MediaService;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Pages\PageManagement;
use App\Arkon\Pages\PageService;
use App\Arkon\Pages\PageStore;
use App\Arkon\Renderer\PageRenderer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\DatabaseTestCase;

final class PublicDeliveryTest extends DatabaseTestCase
{
    public function test_crawlers_can_fetch_public_runtimes_and_sitemap_excludes_noindex_drafts(): void
    {
        $f = $this->siteFixture();
        $this->addDomain($f['siteId'], 'delivery.test');
        $robots = $this->get('http://delivery.test/robots.txt')->assertOk()->getContent();
        $this->assertStringNotContainsString("Disallow: /_arkon\n", $robots);
        $this->assertStringContainsString("Disallow: /_arkon/forms/\n", $robots);
        $this->assertStringContainsString('Sitemap: http://delivery.test/sitemap.xml', $robots);
        $this->get('http://unknown.test/robots.txt')->assertNotFound();
        $pages = app(PageService::class);
        $pages->publish($f['ctx'], ['pageId' => $f['pageId'], 'expectedVersion' => 1, 'idempotencyKey' => self::key()]);
        $pages->saveDraft($f['ctx'], ['pageId' => $f['pageId'], 'baseVersion' => 1, 'saveKey' => self::key(), 'operations' => [['op' => 'updateSeo', 'set' => ['noindex' => true]]]]);
        $this->get('http://delivery.test/sitemap.xml')->assertOk()->assertSee('<loc>http://delivery.test/</loc>', false);
        $pages->publish($f['ctx'], ['pageId' => $f['pageId'], 'expectedVersion' => 2, 'idempotencyKey' => self::key()]);
        $this->get('http://delivery.test/sitemap.xml')->assertOk()->assertDontSee('<loc>', false);
    }

    public function test_public_and_seo_routes_never_construct_the_editing_pipeline(): void
    {
        $f = $this->siteFixture();
        $this->addDomain($f['siteId'], 'delivery.test');
        $pages = app(PageService::class);
        $pages->publish($f['ctx'], ['pageId' => $f['pageId'], 'expectedVersion' => 1, 'idempotencyKey' => self::key()]);
        app(PageManagement::class)->updateSettings($f['ctx'], ['pageId' => $f['pageId'], 'expectedVersion' => 1, 'title' => 'Home', 'path' => '/home', 'saveKey' => self::key()]);
        $pages->publish($f['ctx'], ['pageId' => $f['pageId'], 'expectedVersion' => 2, 'idempotencyKey' => self::key()]);
        foreach ([ComponentRegistry::class, PageRenderer::class, PageStore::class, PageService::class] as $service) {
            $this->app->forgetInstance($service);
            $this->app->bind($service, fn () => throw new \LogicException('Public route resolved editing service '.$service));
        }
        $this->get('http://delivery.test/home')->assertOk();
        $this->get('http://delivery.test/')->assertStatus(301)->assertHeader('Location', '/home');
        $this->get('http://delivery.test/sitemap.xml')->assertOk()->assertSee('<loc>http://delivery.test/home</loc>', false);
        $this->get('http://delivery.test/robots.txt')->assertOk();
        $this->get('http://delivery.test/missing')->assertNotFound();
    }

    public function test_page_weak_and_multiple_validators_revalidate_live_state(): void
    {
        $f = $this->siteFixture();
        $this->addDomain($f['siteId'], 'delivery.test');
        app(PageService::class)->publish($f['ctx'], ['pageId' => $f['pageId'], 'expectedVersion' => 1, 'idempotencyKey' => self::key()]);
        $etag = $this->get('http://delivery.test/')->assertOk()->headers->get('ETag');
        $this->get('http://delivery.test/', ['If-None-Match' => '"older", W/'.$etag])->assertStatus(304);
        $this->get('http://delivery.test/', ['If-None-Match' => '*'])->assertStatus(304);
        $this->get('http://delivery.test/', ['If-None-Match' => '"unrelated"'])->assertOk();
        $live = app(PageService::class)->livePage($f['siteId'], '/');
        app(PageManagement::class)->unpublish($f['ctx'], ['pageId' => $f['pageId'], 'expectedPublicationId' => $live->publication_id]);
        $this->get('http://delivery.test/', ['If-None-Match' => $etag])->assertNotFound();
    }

    public function test_media_streams_and_validators_cannot_bypass_private_or_withdrawn_access(): void
    {
        $f = $this->siteFixture();
        $this->addDomain($f['siteId'], 'delivery.test');
        $asset = app(MediaService::class)->upload($f['ctx'], self::png(), 'stream.png');
        $signed = app(MediaSigner::class)->signUrl($asset['url']);
        $private = $this->get('http://delivery.test'.$signed, ['If-None-Match' => '*'])->assertOk();
        $private->assertHeader('Cache-Control', 'no-store, private');
        $this->assertInstanceOf(BinaryFileResponse::class, $private->baseResponse);
        $this->assertFalse($private->headers->has('ETag'));
        $pages = app(PageService::class);
        $pages->saveDraft($f['ctx'], ['pageId' => $f['pageId'], 'baseVersion' => 1, 'saveKey' => self::key(), 'operations' => [['op' => 'updateProps', 'nodeId' => $f['heroId'], 'set' => ['image' => ['assetId' => $asset['id'], 'alt' => 'Dot']]]]]);
        $pages->publish($f['ctx'], ['pageId' => $f['pageId'], 'expectedVersion' => 2, 'idempotencyKey' => self::key()]);
        $public = $this->get('http://delivery.test'.$asset['url'])->assertOk();
        $this->assertSame(self::png(), file_get_contents($public->baseResponse->getFile()->getPathname()));
        $etag = $public->headers->get('ETag');
        $this->get('http://delivery.test'.$asset['url'], ['If-None-Match' => 'W/'.$etag])->assertStatus(304);
        $this->get('http://other.test'.$asset['url'], ['If-None-Match' => $etag])->assertNotFound();
        $this->call('HEAD', 'http://delivery.test'.$asset['url'])->assertOk()->assertHeader('Content-Length', (string) strlen(self::png()));
        $live = $pages->livePage($f['siteId'], '/');
        app(PageManagement::class)->unpublish($f['ctx'], ['pageId' => $f['pageId'], 'expectedPublicationId' => $live->publication_id]);
        $this->get('http://delivery.test'.$asset['url'], ['If-None-Match' => $etag])->assertNotFound();
    }
}
