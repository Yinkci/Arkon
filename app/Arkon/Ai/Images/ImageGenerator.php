<?php

namespace App\Arkon\Ai\Images;

use App\Arkon\Ai\RunnerStatus;

/**
 * A provider connection that can generate images. Kept separate from text generation: a provider
 * may support one without the other, and Arkon reports and selects the two capabilities separately.
 */
interface ImageGenerator
{
    /** Whether image generation can run now. Never starts a model request. */
    public function imageStatus(?RunnerStatus $text = null): RunnerStatus;

    /**
     * Generates one image. The result is untrusted bytes until Arkon's media pipeline accepts them.
     *
     * @param  callable(): bool  $keepGoing  false stops the provider
     */
    public function generate(string $prompt, string $size, callable $keepGoing): GeneratedImage;
}
