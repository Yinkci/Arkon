<?php

namespace App\Arkon\Ai;

/** Readiness of Claude Code on this machine, as reported by the helper. No credentials or account details. */
final class RunnerStatus
{
    public function __construct(
        public readonly bool $ready,
        public readonly ?string $code,
        public readonly string $message,
        public readonly ?string $version = null,
        public readonly ?string $authMethod = null,
        public readonly ?string $subscriptionType = null,
    ) {}

    public function toArray(): array
    {
        return [
            'ready' => $this->ready,
            'code' => $this->code,
            'message' => $this->message,
            'claudeVersion' => $this->version,
            'authMethod' => $this->authMethod,
            'subscriptionType' => $this->subscriptionType,
        ];
    }
}
