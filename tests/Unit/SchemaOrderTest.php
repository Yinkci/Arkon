<?php

namespace Tests\Unit;

use App\Arkon\Ai\SchemaOrder;
use Tests\TestCase;

class SchemaOrderTest extends TestCase
{
    /**
     * Regression (October 2026): `seo` after the long `proposal` made the model's reply run away in whitespace.
     * Only bulky parts move; small keys keep their authored order (sorting them made the model mislabel blocks).
     */
    public function test_bulky_parts_are_written_last_and_small_keys_keep_their_order(): void
    {
        $bulkyBlock = ['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'enum' => array_map(fn ($i) => 'component-type-'.$i, range(1, 120))]]];
        $schema = [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['summary', 'proposal', 'seo'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'proposal' => ['$ref' => '#/$defs/proposal'],
                'seo' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['title'], 'properties' => ['title' => ['type' => 'string']]],
            ],
            '$defs' => ['proposal' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['summary', 'notes', 'changes'], 'properties' => [
                'summary' => ['type' => 'string'],
                'notes' => ['type' => 'array', 'items' => ['type' => 'string']],
                'changes' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['action', 'parent', 'index', 'ref', 'block'], 'properties' => [
                    'action' => ['type' => 'string', 'const' => 'add'],
                    'parent' => ['type' => 'string'],
                    'index' => ['type' => ['integer', 'null']],
                    'ref' => ['type' => ['string', 'null']],
                    'block' => $bulkyBlock,
                ]]],
            ]]],
        ];
        $ordered = SchemaOrder::tailHeavy($schema);
        $this->assertSame(['summary', 'seo', 'proposal'], array_keys($ordered['properties']), 'a $ref weighs what it names; the long proposal goes last');
        $this->assertSame(['summary', 'notes', 'changes'], array_keys($ordered['$defs']['proposal']['properties']));
        $this->assertSame(['action', 'parent', 'index', 'ref', 'block'], array_keys($ordered['$defs']['proposal']['properties']['changes']['items']['properties']), 'small keys keep their authored order');
        $this->assertSame($schema['required'], $ordered['required'], 'required lists are unchanged');
        $this->assertEquals(self::sorted($schema), self::sorted($ordered), 'same schema apart from property order');
    }

    private static function sorted(mixed $node): mixed
    {
        if (! is_array($node)) {
            return $node;
        }
        if (! array_is_list($node)) {
            ksort($node);
        }

        return array_map(self::sorted(...), $node);
    }
}
