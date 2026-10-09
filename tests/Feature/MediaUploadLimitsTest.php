<?php

namespace Tests\Feature;

use App\Arkon\Errors\ValidationException;
use App\Arkon\Media\MediaService;
use App\Arkon\Media\MediaStorage;
use App\Arkon\Media\MediaVariants;
use App\Arkon\Media\UploadPolicy;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

class MediaUploadLimitsTest extends DatabaseTestCase
{
    public function test_trusted_bytes_enforce_inclusive_boundary_and_small_images_succeed(): void
    {
        $f = $this->siteFixture();
        $max = UploadPolicy::rules()['maxImageUploadBytes'];
        foreach ([1024, 2800000, (int) (2.8 * 1024 ** 2), 4900000, $max] as $size) {
            $data = str_pad(self::png(), $size, "\0");
            $asset = app(MediaService::class)->upload($f['ctx'], $data, 'boundary.png');
            $this->assertSame($size, $asset['bytes']);
            $this->assertSame($data, app(MediaStorage::class)->read(substr($asset['url'], 7)));
        }
        foreach ([$max + 1, 6 * 1024 ** 2] as $size) {
            $this->assertThrows(fn () => app(MediaService::class)->upload($f['ctx'], str_pad(self::png(), $size, "\0"), 'too-large.png'), ValidationException::class, 'maximum image size is 5 MiB');
        }
        $this->assertSame(5, DB::table('media_assets')->count());
    }

    public function test_content_errors_are_distinct_from_size_errors(): void
    {
        $f = $this->siteFixture();
        $media = app(MediaService::class);
        $this->assertThrows(fn () => $media->upload($f['ctx'], '', 'empty.png'), ValidationException::class, 'empty');
        $this->assertThrows(fn () => $media->upload($f['ctx'], '<script>not an image</script>', 'misleading.jpg'), ValidationException::class, 'supported');
        $this->assertThrows(fn () => $media->upload($f['ctx'], "\xff\xd8\xff".str_repeat('x', 100), 'broken.jpg'), ValidationException::class, 'corrupt');
        $huge = substr_replace(self::png(), pack('NN', 10000, 10000), 16, 8);
        $this->assertThrows(fn () => $media->upload($f['ctx'], $huge, 'huge.png'), ValidationException::class, 'total pixels');
    }

    public function test_jpeg_png_and_webp_are_detected_from_bytes_and_optimized(): void
    {
        $f = $this->siteFixture();
        $image = imagecreatetruecolor(32, 24);
        foreach (['jpeg', 'png', 'webp'] as $format) {
            ob_start();
            match ($format) {
                'jpeg' => imagejpeg($image), 'png' => imagepng($image), 'webp' => imagewebp($image)
            };
            $data = str_pad((string) ob_get_clean(), 2800000, "\0");
            $asset = app(MediaService::class)->upload($f['ctx'], $data, 'wrong-extension.txt');
            $this->assertSame('image/'.$format, $asset['mime']);
            $this->assertSame([32, 24], [$asset['width'], $asset['height']]);
            if ($format !== 'webp') {
                $this->assertGreaterThan(0, DB::table('media_variants')->where('asset_id', $asset['id'])->count());
            }
        }
        imagedestroy($image);
    }

    public function test_php_upload_errors_and_body_rejections_do_not_claim_an_application_size_failure(): void
    {
        $f = $this->siteFixture();
        $this->actingAs(User::find($f['ctx']->userId));
        $path = tempnam(sys_get_temp_dir(), 'arkon-upload');
        file_put_contents($path, self::png());
        try {
            foreach ([UPLOAD_ERR_INI_SIZE => 'PHP upload limit', UPLOAD_ERR_PARTIAL => 'interrupted', UPLOAD_ERR_CANT_WRITE => 'temporary storage'] as $error => $message) {
                $file = new UploadedFile($path, 'image.png', null, $error, true);
                $response = $this->post('/admin/api/media', ['file' => $file], ['Accept' => 'application/json']);
                $response->assertStatus(422);
                $this->assertStringContainsString($message, $response->json('message'));
                $this->assertStringNotContainsString('maximum image size', $response->json('message'));
            }
            $this->withServerVariables(['CONTENT_LENGTH' => 100 * 1024 ** 2])->postJson('/admin/api/media')->assertStatus(413)->assertJsonPath('code', 'REQUEST_TOO_LARGE');
        } finally {
            unlink($path);
        }
    }

    public function test_optimization_checks_decoded_memory_before_allocating_pixels(): void
    {
        $f = $this->siteFixture();
        $asset = app(MediaService::class)->upload($f['ctx'], self::png(), 'memory.png');
        $row = DB::table('media_assets')->where('id', $asset['id'])->first();
        $row->width = 10000;
        $row->height = 3000;
        $before = ini_get('memory_limit');
        ini_set('memory_limit', '128M');
        try {
            $this->assertThrows(fn () => app(MediaVariants::class)->generate($row), \RuntimeException::class, 'processing memory');
        } finally {
            ini_set('memory_limit', $before);
        }
    }

    public function test_optimizer_failure_keeps_original_and_reports_processing_warning(): void
    {
        $f = $this->siteFixture();
        $this->mock(MediaVariants::class, fn ($mock) => $mock->shouldReceive('generate')->once()->andThrow(new \RuntimeException('WebP encoding failed')));
        $asset = app(MediaService::class)->upload($f['ctx'], self::png(), 'safe.png');
        $this->assertStringContainsString('optimization failed', $asset['optimizationWarning']);
        $this->assertNotNull(app(MediaStorage::class)->path(substr($asset['url'], 7)));
        $this->assertSame(1, DB::table('media_assets')->count());
    }
}
