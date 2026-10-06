<?php

namespace App\Arkon\Sites;

/**
 * Every service call is scoped to exactly one site. Membership is resolved for
 * that site only, so access to one site never grants anything on another.
 */
final class SiteContext
{
    /** @param 'ui'|'ai'|'api'|'cli' $via */
    public function __construct(
        public readonly string $siteId,
        public readonly string $userId,
        public readonly string $via = 'ui',
    ) {}
}
