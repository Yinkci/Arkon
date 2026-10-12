<?php

namespace Tests\Unit;

use App\Arkon\Ai\ProposalSchema;
use App\Arkon\Ai\SchemaCompactor;
use App\Arkon\Ai\WebsiteProposalService;
use App\Arkon\Ai\WebsiteRevisions;
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

    public function test_a_schema_with_nothing_to_share_has_no_empty_defs_list(): void
    {
        $compact = SchemaCompactor::compact(['type' => 'object', 'additionalProperties' => false, 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']]);
        $this->assertStringNotContainsString('"$defs":[]', json_encode($compact), 'an empty list is invalid JSON Schema (Claude Code refuses it)');
    }

    public function test_command_length_matches_how_windows_escapes_quotes(): void
    {
        // Measured on Windows: a quote-dense 23,978-character argument (6,850 quotes) starts, 25,973 (7,420 quotes) does not.
        $unit = '{"type":"string","enum":["a","b"]},';
        $arg = fn (int $n) => '['.str_repeat($unit, intdiv($n, strlen($unit))).'1]';
        $this->assertLessThan(32767, SchemaCompactor::windowsCommandLength(['php.exe', 'child.php', $arg(24000)]));
        $this->assertGreaterThan(32767, SchemaCompactor::windowsCommandLength(['php.exe', 'child.php', $arg(26000)]));
    }

    /**
     * Every schema a helper can send, as sent: each $ref resolves, every node has a type (or anyOf/$ref),
     * objects are closed with all properties required (strict structured outputs), and it fits the
     * Windows command line. A broken schema makes the provider refuse the request outright.
     */
    public function test_every_website_and_revision_schema_is_valid_for_strict_structured_output_and_fits(): void
    {
        $service = app(WebsiteProposalService::class);
        $plain = ['assets' => ['00000000-0000-4000-8000-000000000001' => []], 'componentIds' => [], 'themeTypes' => null, 'pages' => ['00000000-0000-4000-8000-000000000002' => []]];
        $generate = [...$plain, 'images' => ['mode' => 'generate', 'requested' => true, 'reserved' => ['01a00000-0000-7000-8000-000000000001', '01a00000-0000-7000-8000-000000000002'], 'inherited' => []]];
        $revision = fn (array $snapshot, string $scope) => app(WebsiteRevisions::class)->format($service, [...$snapshot, 'baseline' => null, 'revision' => ['scope' => $scope, 'pageId' => 'p', 'nodeId' => 'n', 'label' => 'Hero']])['schema'];
        $schemas = ['plain' => $service->format($plain)['schema'], 'generate' => $service->format($generate)['schema']];
        foreach (['section', 'page', 'website'] as $scope) {
            $schemas["{$scope} revision"] = $revision($plain, $scope);
            $schemas["{$scope} revision with generation"] = $revision($generate, $scope);
        }
        foreach ($schemas as $label => $schema) {
            $json = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            // Claude Code on Windows gets the schema on its command line: it must fit, loosened if needed, and stay valid.
            $claude = SchemaCompactor::loosenToFit($schema, fn ($s) => SchemaCompactor::windowsCommandLength(['C:\Users\someone\.local\bin\claude.exe', '-p', '--json-schema', json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), '--system-prompt-file', 'C:\Users\someone\AppData\Local\Temp\arkon-ai-0123456789abcdef\instructions.txt', str_repeat('x', 400)]) <= 32000);
            $this->assertNotNull($claude, "{$label}: fits the Claude Code command line");
            $this->assertSame([], $this->strictProblems($claude), "{$label} (Claude Code guide)");
            $this->assertStringNotContainsString('01a00000-0000-7000-8000-000000000001', $json, "{$label}: placeholders, not reserved ids");
            $this->assertSame([], $this->strictProblems($schema), $label);
        }
    }

    private function strictProblems(array $root): array
    {
        $problems = [];
        $walk = function (mixed $node, string $path) use (&$walk, &$problems, $root): void {
            if (! is_array($node)) {
                return;
            }
            if (isset($node['$ref'])) {
                $name = substr($node['$ref'], strlen('#/$defs/'));
                if (! isset($root['$defs'][$name])) {
                    $problems[] = "unresolved {$node['$ref']} at {$path}";
                }

                return;
            }
            if (! isset($node['type']) && ! isset($node['anyOf'])) {
                $problems[] = "no type at {$path}";
            }
            if (isset($node['properties'])) {
                if (($node['additionalProperties'] ?? null) !== false) {
                    $problems[] = "open object at {$path}";
                }
                if (($missing = array_diff(array_keys($node['properties']), $node['required'] ?? [])) !== []) {
                    $problems[] = "optional properties at {$path}: ".implode(', ', $missing);
                }
                foreach ($node['properties'] as $key => $child) {
                    $walk($child, "{$path}/{$key}");
                }
            }
            foreach (['items', 'additionalProperties'] as $key) {
                if (is_array($node[$key] ?? null)) {
                    $walk($node[$key], "{$path}/{$key}");
                }
            }
            foreach ($node['anyOf'] ?? [] as $i => $child) {
                $walk($child, "{$path}/anyOf/{$i}");
            }
        };
        $walk($root, '#');
        foreach ($root['$defs'] ?? [] as $name => $def) {
            $walk($def, "#/\$defs/{$name}");
        }

        return $problems;
    }
}
