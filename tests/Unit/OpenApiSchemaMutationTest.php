<?php

namespace Tests\Unit;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Tests\Support\OpenApiSchema;

/** The contract checker itself: it must reject undocumented fields, missing required ones and wrong types. */
class OpenApiSchemaMutationTest extends TestCase
{
    public function test_the_contract_checker_rejects_shape_changes(): void
    {
        $spec = json_decode((string) file_get_contents(__DIR__.'/../../resources/api/openapi.json'), true);
        $term = ['id' => 'x', 'taxonomy' => 'tag', 'name' => 'A', 'slug' => 'a', 'description' => '', 'count' => 1];
        OpenApiSchema::assert($spec, ['$ref' => '#/components/schemas/Term'], $term);
        foreach ([[...$term, 'secret' => 1], array_diff_key($term, ['slug' => 1]), [...$term, 'count' => '1']] as $broken) {
            try {
                OpenApiSchema::assert($spec, ['$ref' => '#/components/schemas/Term'], $broken);
                $this->fail('accepted '.json_encode($broken));
            } catch (AssertionFailedError $e) {
                $this->assertStringNotContainsString('accepted', $e->getMessage());
            }
        }
    }
}
