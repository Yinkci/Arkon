<?php

namespace Tests\Unit;

use App\Arkon\Schema\Operations;
use App\Arkon\Support\Json;
use Tests\Conformance\Conformance;
use Tests\TestCase;

/**
 * The PHP half of the conformance suite: the server's validation and operation
 * semantics must still produce tests/Conformance/fixtures.json, which the
 * editor's TypeScript twin is held to as well (tests/Conformance/conformance.test.ts).
 */
class ConformanceTest extends TestCase
{
    public function test_php_reproduces_the_shared_fixtures(): void
    {
        $stored = json_decode((string) file_get_contents(base_path('tests/Conformance/fixtures.json')), true);
        $current = json_decode(Json::encode(Conformance::evaluate(Conformance::cases())), true);
        $this->assertSame($stored, $current, 'PHP behaviour changed: review it, then run `php tests/Conformance/build.php` and make the TypeScript twin match.');
    }

    public function test_every_successful_operation_is_undone_exactly_by_its_inverse(): void
    {
        $fixtures = json_decode((string) file_get_contents(base_path('tests/Conformance/fixtures.json')), true);
        foreach ($fixtures['operations'] as $case) {
            if ($case['error'] !== null) {
                continue;
            }
            $original = Json::decode($case['document']);
            $applied = Operations::apply($original, Json::decode($case['operations']));
            $undone = Operations::apply($applied['doc'], $applied['inverse'])['doc'];
            $this->assertSame(Json::canonical($original), Json::canonical($undone), $case['name']);
        }
    }
}
