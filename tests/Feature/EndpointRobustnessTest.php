<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\DatabaseTestCase;

/**
 * Every admin endpoint, given bodies that are well formed JSON but wrong for it. None may answer
 * with a server error: a refusal is a 4xx that says what was wrong. A 500 here means something
 * assumed a shape without checking it, which is how the content-type settings bug reached the
 * admin in the first place — an empty JSON object arrives as an object, not an array.
 */
final class EndpointRobustnessTest extends DatabaseTestCase
{
    /** Bodies that have tripped real code: empty maps, empty lists, nulls and wrong scalars. */
    private static function bodies(): array
    {
        $awkward = ['settings' => new \stdClass, 'style' => new \stdClass, 'props' => new \stdClass, 'seo' => new \stdClass,
            'definition' => new \stdClass, 'tokens' => new \stdClass, 'fields' => [], 'terms' => new \stdClass,
            'operations' => [], 'items' => [], 'ids' => [], 'blocks' => []];

        return [
            'empty object' => [],
            'empty maps everywhere' => $awkward,
            'nulls' => array_map(fn () => null, $awkward),
            'scalars where structures belong' => array_map(fn () => 'nonsense', $awkward),
            'numbers where structures belong' => array_map(fn () => 7, $awkward),
        ];
    }

    public function test_no_admin_endpoint_answers_a_server_error_for_a_wrong_body(): void
    {
        $f = $this->siteFixture();
        $this->addDomain($f['siteId'], 'robust.test');
        $this->actingAs(User::findOrFail($f['ctx']->userId));
        $this->withoutVite();

        $broken = [];
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'admin/api/') || ! in_array('POST', $route->methods(), true)) {
                continue;
            }
            $path = str_replace(['{page}', '{taxonomy}', '{term}', '{key}', '{form}', '{menu}', '{asset}', '{component}', '{proposal}', '{id}'],
                [$f['pageId'], 'category', $f['pageId'], 'service', $f['pageId'], $f['pageId'], $f['pageId'], $f['pageId'], $f['pageId'], $f['pageId']], $uri);
            if (str_contains($path, '{')) {
                continue;
            }
            foreach (self::bodies() as $name => $body) {
                $status = $this->postJson('/'.$path, $body)->getStatusCode();
                $checked++;
                if ($status >= 500) {
                    $broken[] = "{$path} ({$name}) → {$status}";
                }
            }
        }
        $this->assertGreaterThan(100, $checked, 'the probe should reach every admin write endpoint');
        $this->assertSame([], $broken, "endpoints failed with a server error:\n".implode("\n", $broken));
    }
}
