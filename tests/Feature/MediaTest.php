<?php

namespace Tests\Feature;

use App\Arkon\Errors\ForbiddenException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Media\ImageType;
use App\Arkon\Media\MediaService;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Media\MediaStorage;
use App\Arkon\Pages\PageService;
use Illuminate\Support\Facades\File;
use Tests\DatabaseTestCase;

/** Port of packages/core/test/media.test.ts and media-access.test.ts. */
class MediaTest extends DatabaseTestCase
{
    private array $f;

    private array $asset;

    private string $storageKey;

    private MediaSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();
        File::deleteDirectory(app(MediaStorage::class)->root);
        $this->f = $this->siteFixture();
        $this->addDomain($this->f['siteId'], 'site-a.test');
        $this->signer = new MediaSigner('base64:'.base64_encode(str_repeat('k', 32)));
        $this->asset = $this->media()->upload($this->f['ctx'], self::png(), 'a.png');
        $this->storageKey = substr($this->asset['url'], strlen('/media/'));
    }

    private function media(): MediaService
    {
        return app(MediaService::class);
    }

    private function access(?string $host = null, ?string $userId = null, ?string $token = null, ?string $key = null): ?string
    {
        return $this->media()->resolveAccess($key ?? $this->storageKey, $host, $userId, $token, $this->signer)['access'] ?? null;
    }

    private function useImage(int $version, mixed $image): void
    {
        app(PageService::class)->saveDraft($this->f['ctx'], [
            'pageId' => $this->f['pageId'], 'baseVersion' => $version, 'saveKey' => self::key(),
            'operations' => [['op' => 'updateProps', 'nodeId' => $this->f['heroId'], 'set' => ['image' => $image]]],
        ]);
    }

    private function publish(int $version): void
    {
        app(PageService::class)->publish($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => $version, 'idempotencyKey' => self::key()]);
    }

    // ── uploads ──

    public function test_detects_types_by_magic_bytes_not_names(): void
    {
        $this->assertSame(['mime' => 'image/png', 'ext' => 'png'], ImageType::detect(self::png()));
        $this->assertNull(ImageType::detect("<svg xmlns='http://www.w3.org/2000/svg'></svg>"));
        $this->assertSame(['mime' => 'image/gif', 'ext' => 'gif'], ImageType::detect("GIF89a\0\0\0\0\0\0"));
    }

    public function test_stores_a_valid_image_with_its_dimensions(): void
    {
        $this->assertSame([1, 1, 'image/png'], [$this->asset['width'], $this->asset['height'], $this->asset['mime']]);
        $this->assertSame(self::png(), app(MediaStorage::class)->read($this->storageKey));
    }

    public function test_rejects_non_images_regardless_of_the_file_name(): void
    {
        $this->assertThrows(fn () => $this->media()->upload($this->f['ctx'], "<?php echo 'hi'; ?>          ", 'photo.png'), ValidationException::class);
        $this->assertThrows(fn () => $this->media()->upload($this->f['ctx'], '', 'empty.png'), ValidationException::class, 'empty');
    }

    public function test_requires_upload_permission(): void
    {
        $viewer = $this->addMember($this->f['siteId'], 'viewer');
        $this->assertThrows(fn () => $this->media()->upload($viewer, self::png(), 'x.png'), ForbiddenException::class);
    }

    public function test_refuses_path_traversal_in_storage_keys(): void
    {
        $this->assertNull($this->access(userId: $this->f['ctx']->userId, key: '../../.env'));
        $this->assertNull(app(MediaStorage::class)->read('../../.env'));
    }

    public function test_a_page_can_use_its_own_sites_image_and_publishes_it_with_dimensions(): void
    {
        $this->useImage(1, ['assetId' => $this->asset['id'], 'alt' => 'A dot']);
        $this->publish(2);
        $live = app(PageService::class)->livePage($this->f['siteId'], '/');
        $this->assertStringContainsString('src="'.$this->asset['url'].'" alt="A dot" width="1" height="1"', $live->html);
    }

    public function test_a_page_cannot_reference_another_sites_image(): void
    {
        $other = $this->siteFixture();
        $foreign = $this->media()->upload($other['ctx'], self::png(), 'theirs.png');
        $this->assertThrows(fn () => $this->useImage(1, ['assetId' => $foreign['id'], 'alt' => 'x']), ValidationException::class, 'does not exist');
        $this->assertCount(1, $this->media()->list($this->f['ctx'])); // only its own upload
    }

    // ── delivery policy ──

    public function test_a_fresh_upload_is_not_public_even_on_the_right_host(): void
    {
        $this->assertNull($this->access('site-a.test'));
        $this->assertNull($this->access());
    }

    public function test_an_image_in_a_saved_but_unpublished_draft_is_still_not_public(): void
    {
        $this->useImage(1, ['assetId' => $this->asset['id'], 'alt' => 'A']);
        $this->assertNull($this->access('site-a.test'));
    }

    public function test_members_of_the_assets_site_get_private_access_and_others_do_not(): void
    {
        $this->assertSame('private', $this->access(userId: $this->f['ctx']->userId));
        $viewer = $this->addMember($this->f['siteId'], 'viewer');
        $this->assertSame('private', $this->access(userId: $viewer->userId));
        $outsider = $this->siteFixture();
        $this->assertNull($this->access(userId: $outsider['ctx']->userId));
    }

    public function test_signed_tokens_grant_private_access_to_that_file_only_until_they_expire(): void
    {
        $token = $this->signer->token($this->storageKey);
        $this->assertSame('private', $this->access(token: $token));
        $this->assertMatchesRegularExpression('#^/media/.+\?t=\d+\.#', $this->signer->signUrl($this->asset['url']));

        $other = $this->media()->upload($this->f['ctx'], self::png(), 'b.png');
        $this->assertFalse($this->signer->verify(substr($other['url'], 7), $token));

        $expired = $this->signer->token($this->storageKey, (int) (microtime(true) * 1000) - 5 * 60 * 60 * 1000);
        $this->assertNull($this->access(token: $expired));
        $tampered = substr($token, 0, -1).(str_ends_with($token, 'A') ? 'B' : 'A');
        $this->assertNull($this->access(token: $tampered));
        $this->assertNull($this->access(token: (new MediaSigner('base64:'.base64_encode(str_repeat('z', 32))))->token($this->storageKey)));
    }

    public function test_publishing_makes_the_image_public_on_its_own_sites_hosts_only(): void
    {
        $this->useImage(1, ['assetId' => $this->asset['id'], 'alt' => 'A']);
        $this->publish(2);
        $this->assertSame('public', $this->access('site-a.test'));
        $this->assertSame('public', $this->access('SITE-A.test'));
        $this->assertNull($this->access('unknown.test'));

        $other = $this->siteFixture();
        $this->addDomain($other['siteId'], 'site-b.test');
        $this->assertNull($this->access('site-b.test'));
    }

    public function test_an_image_stops_being_public_when_the_live_page_no_longer_uses_it(): void
    {
        $this->useImage(1, ['assetId' => $this->asset['id'], 'alt' => 'A']);
        $this->publish(2);
        $this->useImage(2, null);
        // Unpublished removal: the live page still shows it, so it stays public.
        $this->assertSame('public', $this->access('site-a.test'));
        $this->publish(3);
        $this->assertNull($this->access('site-a.test'));
        $this->assertSame('private', $this->access(userId: $this->f['ctx']->userId));
    }
}
