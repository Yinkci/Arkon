<?php

/*
 * Builds tests/Conformance/fixtures.json: tricky inputs plus the results of the
 * PHP implementation. Review the diff when it changes, then commit it.
 *
 *   php tests/Conformance/build.php
 *
 * PHPUnit (tests/Unit/ConformanceTest.php) fails if PHP no longer produces these
 * results; Vitest (tests/Conformance/conformance.test.ts) fails if TypeScript
 * does not produce exactly the same ones. That keeps the server's and the
 * editor's validation in step.
 */

use Illuminate\Contracts\Console\Kernel;
use Tests\Conformance\Conformance;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$fixtures = Conformance::evaluate(Conformance::cases());
file_put_contents(__DIR__.'/fixtures.json', json_encode($fixtures, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
echo 'Wrote '.count($fixtures['documents']).' document, '.count($fixtures['operations']).' operation and '.count($fixtures['paths'])." path cases\n";
