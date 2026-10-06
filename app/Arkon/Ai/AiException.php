<?php

namespace App\Arkon\Ai;

use App\Arkon\Errors\ArkonException;

/**
 * AI failures with a stable code. The same codes are stored on failed requests
 * (ai_proposals.error_code) and shown with an understandable message. Messages never
 * contain credentials, tokens or raw process output beyond a short excerpt.
 */
class AiException extends ArkonException
{
    // Requesting
    public const HELPER_OFFLINE = 'AI_HELPER_OFFLINE';

    public const RATE_LIMITED = 'AI_RATE_LIMITED';

    public const LIMIT_REACHED = 'AI_LIMIT_REACHED';

    public const UNAUTHENTICATED = 'AI_UNAUTHENTICATED';

    // Running Claude Code
    public const CLAUDE_MISSING = 'CLAUDE_MISSING';

    public const CLAUDE_OUTDATED = 'CLAUDE_OUTDATED';

    public const CLAUDE_NOT_LOGGED_IN = 'CLAUDE_NOT_LOGGED_IN';

    public const CLAUDE_BILLING_MODE = 'CLAUDE_BILLING_MODE';

    public const CLAUDE_LIMIT = 'CLAUDE_LIMIT';

    public const CLAUDE_TIMEOUT = 'CLAUDE_TIMEOUT';

    public const CLAUDE_FAILED = 'CLAUDE_FAILED';

    // Results and lifecycle
    public const INVALID_OUTPUT = 'AI_INVALID_OUTPUT';

    public const STALE_DRAFT = 'AI_STALE_DRAFT';

    public const CANCELLED = 'AI_CANCELLED';

    public const SUPERSEDED = 'AI_SUPERSEDED';

    public const EXPIRED = 'AI_EXPIRED';

    public const INTERRUPTED = 'AI_INTERRUPTED';

    public const STALE_PROPOSAL = 'STALE_PROPOSAL';

    private const STATUS = [
        self::HELPER_OFFLINE => 503,
        self::RATE_LIMITED => 429,
        self::LIMIT_REACHED => 429,
        self::UNAUTHENTICATED => 401,
        self::INVALID_OUTPUT => 422,
        self::STALE_DRAFT => 409,
        self::STALE_PROPOSAL => 409,
    ];

    /** @param list<array{message: string, path?: string}> $issues */
    public function __construct(private readonly string $errorCode, string $message, public readonly array $issues = [])
    {
        parent::__construct($message);
    }

    public function code(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return self::STATUS[$this->errorCode] ?? 502;
    }

    public function toArray(): array
    {
        return $this->issues === [] ? parent::toArray() : [...parent::toArray(), 'issues' => $this->issues];
    }
}
