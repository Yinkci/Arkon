<?php

namespace Tests\Unit;

use App\Arkon\Ai\ProposalSchema;
use App\Arkon\Ai\SchemaCompactor;
use App\Arkon\Ai\WebsiteProposalService;
use Tests\TestCase;

class SchemaCompactorTest extends TestCase
{
    private function expand(mixed $node, array $root): mixed
    {
        if (! is_array($node)) {
            return $node;
        }
        if (isset($node['$ref'])) {
            $name = substr($node['$ref'], strlen('#/$defs/'));

            return $this->expand($root['$defs'][$name], $root);
        }
        unset($node['$defs']);
        foreach ($node as $key => $value) {
            $node[$key] = $this->expand($value, $root);
        }

        return $node;
    }

    public function test_catalogue_rules_expand_identically_after_compaction(): void
    {
        $schema = app(ProposalSchema::class)->schema(['00000000-0000-4000-8000-000000000001']);
        $compact = SchemaCompactor::compact($schema);
        $this->assertSame($this->expand($schema, $schema), $this->expand($compact, $compact));
        $this->assertLessThan(strlen(json_encode($schema)), strlen(json_encode($compact)));
    }

    public function test_website_schema_fits_windows_preflight_with_registered_blocks(): void
    {
        $format = app(WebsiteProposalService::class)->format(['assets' => ['00000000-0000-4000-8000-000000000001' => []], 'componentIds' => [], 'themeTypes' => null, 'pages' => ['00000000-0000-4000-8000-000000000002' => []]]);
        $json = json_encode($format['schema'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // Reserve command/path/quoting overhead rather than just checking bare JSON.
        $this->assertLessThan(32000, (strlen($json) + 1500) * 1.1);
    }
}
