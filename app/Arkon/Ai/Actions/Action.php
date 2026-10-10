<?php

namespace App\Arkon\Ai\Actions;

use App\Arkon\Sites\SiteContext;
use Closure;

/**
 * One operation AI may perform on Arkon, described for the model (name, description, input
 * schema) and carried out through the domain services. Input is checked against the schema
 * before the handler runs; the services then authorize and validate as for any other caller.
 *
 * A handler receives the acting member's context (via "ai"), the checked input and the
 * connection id, and returns structured data, never prose to parse.
 */
final class Action
{
    /**
     * @param  array  $input  JSON schema of the arguments (an object schema)
     * @param  Closure(SiteContext, array, string): array  $handler
     * @param  ?string  $permission  the role permission it needs, for capability discovery
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $input,
        public readonly Closure $handler,
        public readonly bool $readOnly = true,
        public readonly ?string $permission = null,
        public readonly string $area = 'pages',
    ) {}
}
