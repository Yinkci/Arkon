<?php

namespace App\Arkon\Ai;

/**
 * Readiness of a local provider, as reported by the helper. No credentials or account details.
 * Text generation (pages and websites) and image generation are reported separately: `images` is
 * null when the provider cannot generate images at all.
 */
final class RunnerStatus
{
    public function __construct(
        public readonly bool $ready,
        public readonly ?string $code,
        public readonly string $message,
        public readonly ?string $version = null,
        public readonly ?string $authMethod = null,
        public readonly ?string $subscriptionType = null,
        public readonly ?RunnerStatus $images = null,
    ) {}

    public function withImages(RunnerStatus $images): self
    {
        return new self($this->ready, $this->code, $this->message, $this->version, $this->authMethod, $this->subscriptionType, $images);
    }

    public function toArray(string $provider = 'claude-code'): array
    {
        return [
            'ready' => $this->ready,
            'code' => $this->code,
            'message' => $this->message,
            'version' => $this->version,
            'claudeVersion' => $provider === 'claude-code' ? $this->version : null,
            'authMethod' => $this->authMethod,
            'subscriptionType' => $this->subscriptionType,
            'imageGeneration' => $this->images === null ? null : ['ready' => $this->images->ready, 'code' => $this->images->code, 'message' => $this->images->message],
        ];
    }
}
