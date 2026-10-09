<?php

namespace Tests\Feature;

use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\ForbiddenException;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Media\MediaLibrary;
use App\Arkon\Media\MediaService;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Media\MediaStorage;
use App\Arkon\Pages\PageService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

class MediaLibraryTest extends DatabaseTestCase
{
    public function test_optimized_links_list_real_sizes_and_remain_site_scoped(): void
    {
        $f = $this->siteFixture();
        $other = $this->siteFixture();
        $image = imagecreatetruecolor(640, 400);
        ob_start();
        imagepng($image);
        $data = str_pad((string) ob_get_clean(), 1000000, "\0");
        imagedestroy($image);
        $asset = app(MediaService::class)->upload($f['ctx'], $data, 'source.png');
        $detail = app(MediaLibrary::class)->detail($f['ctx'], $asset['id']);
        $this->assertSame([320, 640], array_column($detail['webpVariants'], 'width'));
        foreach ($detail['webpVariants'] as $variant) {
            $this->assertStringEndsWith('.webp', $variant['url']);
            $this->assertStringNotContainsString('?', $variant['url']);
            $this->assertSame('image/webp', mime_content_type(app(MediaStorage::class)->path(substr($variant['url'], 7))));
            $this->assertNull(app(MediaService::class)->resolveAccess(substr($variant['url'], 7), null, null, null, app(MediaSigner::class)));
        }
        $this->assertThrows(fn () => app(MediaLibrary::class)->detail($other['ctx'], $asset['id']), NotFoundException::class);
    }

    private function input(int $version = 1): array
    {
        return ['title' => 'Team meeting', 'alt' => 'People discussing plans', 'caption' => 'Our team', 'description' => 'Editorial note', 'version' => $version, 'requestKey' => self::key()];
    }

    public function test_metadata_retries_are_stable_and_search_is_site_scoped(): void
    {
        $f = $this->siteFixture();
        $other = $this->siteFixture();
        $m = app(MediaService::class);
        $l = app(MediaLibrary::class);
        $a = $m->upload($f['ctx'], self::png(), 'IMG_4827.png');
        $foreign = $m->upload($other['ctx'], self::png(), 'private.png');
        $input = $this->input();
        $this->assertSame(['version' => 2], $l->save($f['ctx'], $a['id'], $input));
        $this->assertSame(['version' => 2], $l->save($f['ctx'], $a['id'], $input));
        $this->assertThrows(fn () => $l->save($f['ctx'], $a['id'], [...$input, 'title' => 'Different']), ConflictException::class);
        $this->assertThrows(fn () => $l->save($f['ctx'], $a['id'], $this->input()), ConflictException::class);
        foreach (['Team', 'IMG_4827', 'discussing', 'Our team'] as $q) {
            $this->assertSame($a['id'], $l->browse($f['ctx'], $q)['items'][0]['id']);
        }
        $this->assertSame(['version' => 2], $l->save($f['ctx'], $a['id'], array_reverse($input, true)));
        $this->assertThrows(fn () => $l->save($f['ctx'], $a['id'], [...$this->input(2), 'alt' => str_repeat('😀', 151)]), ValidationException::class);
        $this->assertSame(0, $l->browse($f['ctx'], '%')['total']);
        $this->assertSame(1, $l->browse($f['ctx'])['total']);
        $d = $l->detail($f['ctx'], $a['id']);
        $this->assertSame('IMG_4827.png', $d['originalName']);
        $this->assertSame($a['url'], $d['url']);
        $this->assertSame(1, DB::table('media_metadata_saves')->count());
        $this->assertThrows(fn () => $l->detail($f['ctx'], $foreign['id']), NotFoundException::class);
        $viewer = $this->addMember($f['siteId'], 'viewer');
        $this->assertSame('Team meeting', $l->detail($viewer, $a['id'])['title']);
        $this->assertThrows(fn () => $l->save($viewer, $a['id'], $this->input(2)), ForbiddenException::class);
    }

    public function test_removal_preserves_live_delivery_and_immutable_html(): void
    {
        $f = $this->siteFixture();
        $this->addDomain($f['siteId'], 'site-a.test');
        $m = app(MediaService::class);
        $l = app(MediaLibrary::class);
        $a = $m->upload($f['ctx'], self::png(), 'photo.png');
        $p = app(PageService::class);
        $p->saveDraft($f['ctx'], ['pageId' => $f['pageId'], 'baseVersion' => 1, 'saveKey' => self::key(), 'operations' => [['op' => 'updateProps', 'nodeId' => $f['heroId'], 'set' => ['image' => ['assetId' => $a['id'], 'alt' => '']]]]]);
        $p->publish($f['ctx'], ['pageId' => $f['pageId'], 'expectedVersion' => 2, 'idempotencyKey' => self::key()]);
        $html = $p->livePage($f['siteId'], '/')->html;
        $this->assertStringContainsString('alt=""', $html);
        $this->assertSame(['Home'], $l->detail($f['ctx'], $a['id'])['usage']['livePages']);
        $l->save($f['ctx'], $a['id'], $this->input());
        $this->assertSame($html, $p->livePage($f['siteId'], '/')->html);
        $editor = $this->addMember($f['siteId'], 'editor');
        $this->assertThrows(fn () => $l->archive($editor, $a['id'], 2), ForbiddenException::class);
        $l->archive($f['ctx'], $a['id'], 2);
        $l->archive($f['ctx'], $a['id'], 2);
        $this->assertSame(0, $l->browse($f['ctx'])['total']);
        $this->assertCount(0, $m->list($f['ctx']));
        $this->assertSame($html, $p->livePage($f['siteId'], '/')->html);
        $this->assertCount(1, $m->mediaMap($f['siteId'], [$a['id']]));
        $this->assertSame('public', $m->resolveAccess(substr($a['url'], 7), 'site-a.test', null, null, app(MediaSigner::class))['access']);
    }

    public function test_pagination_is_bounded_and_sorting_is_stable(): void
    {
        $f = $this->siteFixture();
        $m = app(MediaService::class);
        $l = app(MediaLibrary::class);
        for ($i = 0; $i < 38; $i++) {
            $m->upload($f['ctx'], self::png(), sprintf('photo-%02d.png', $i));
        }
        $first = $l->browse($f['ctx'], '', 'name');
        $this->assertCount(36, $first['items']);
        $this->assertSame(38, $first['total']);
        $this->assertSame('photo-00.png', $first['items'][0]['title']);
        $this->assertCount(2, $l->browse($f['ctx'], '', 'name', 2)['items']);
    }

    public function test_http_empty_alt_and_caption_are_valid_and_canonical_urls_have_no_tokens(): void
    {
        $f = $this->siteFixture();
        $a = app(MediaService::class)->upload($f['ctx'], self::png(), 'test.png');
        $user = User::find($f['ctx']->userId);
        $this->actingAs($user);
        $this->postJson('/admin/api/media/'.$a['id'].'/metadata', [...$this->input(), 'alt' => '', 'caption' => '', 'description' => ''])->assertOk()->assertJsonPath('data.version', 2);
        $this->getJson('/admin/api/media/'.$a['id'])->assertOk()->assertJsonPath('data.alt', '')->assertJsonPath('data.url', $a['url']);
    }
}
