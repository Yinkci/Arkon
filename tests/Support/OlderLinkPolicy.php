<?php

namespace Tests\Support;

use App\Arkon\Support\Rules;
use ReflectionProperty;

/**
 * Runs code under the link policy in force before backslashes were refused
 * (`patterns.linkRecorded`), to create content exactly as older code stored it.
 */
trait OlderLinkPolicy
{
    protected function underOlderLinkPolicy(callable $callback): mixed
    {
        $rules = new ReflectionProperty(Rules::class, 'rules');
        $compiled = new ReflectionProperty(Rules::class, 'compiled');
        $original = Rules::all();
        $changed = $original;
        $changed['patterns']['link'] = $original['patterns']['linkRecorded'];
        $rules->setValue(null, $changed);
        $compiled->setValue(null, []);
        try {
            return $callback();
        } finally {
            $rules->setValue(null, $original);
            $compiled->setValue(null, []);
        }
    }
}
