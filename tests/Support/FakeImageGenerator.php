<?php

namespace Tests\Support;

use App\Arkon\Ai\AiException;
use App\Arkon\Ai\Images\GeneratedImage;
use App\Arkon\Ai\Images\ImageGenerator;
use App\Arkon\Ai\RunnerStatus;
use Closure;

/**
 * A scripted stand-in for an image-generating provider in tests: each generate() takes the next
 * reply (raw bytes, an AiException, or a closure). The default reply is a real photographic-size
 * PNG drawn with GD. It never starts a process and never uses an account.
 */
final class FakeImageGenerator implements ImageGenerator
{
    /** @var list<array{prompt: string, size: string}> */
    public array $calls = [];

    public function __construct(private array $replies = [], private ?RunnerStatus $status = null) {}

    public function imageStatus(?RunnerStatus $text = null): RunnerStatus
    {
        return $this->status ?? new RunnerStatus(true, null, 'Fake image generation.');
    }

    public function generate(string $prompt, string $size, callable $keepGoing): GeneratedImage
    {
        $this->calls[] = ['prompt' => $prompt, 'size' => $size];
        $reply = $this->replies === [] ? null : array_shift($this->replies);
        if ($reply instanceof Closure) {
            $reply = $reply($prompt, $size, $keepGoing);
        }
        if ($reply instanceof AiException) {
            throw $reply;
        }

        return new GeneratedImage($reply ?? self::photo($size), 'codex', 'Revised: '.mb_substr($prompt, 0, 40));
    }

    /** A noisy gradient PNG of the requested size (compresses like a photograph, not like a flat colour). */
    public static function photo(string $size = '1536x1024'): string
    {
        [$w, $h] = array_map('intval', explode('x', $size));
        $image = imagecreatetruecolor($w, $h);
        mt_srand(7);
        for ($y = 0; $y < $h; $y += 4) {
            for ($x = 0; $x < $w; $x += 4) {
                $c = imagecolorallocate($image, ($x * 255 / $w + mt_rand(0, 40)) % 256, ($y * 255 / $h + mt_rand(0, 40)) % 256, mt_rand(60, 160));
                imagefilledrectangle($image, $x, $y, $x + 3, $y + 3, $c);
            }
        }
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
