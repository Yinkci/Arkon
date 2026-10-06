<?php

/*
 * Runs one service call in its own PHP process, so tests get real concurrency
 * (separate connections, real lock waits). Started by Tests\Support\Parallel.
 *
 * Protocol: base64 JSON job in argv[1]. After booting and connecting, prints
 * "READY" and waits for the job's go-file ({"startAt": <unix seconds>}); then
 * starts the call at that instant (plus the job's delayMs) and prints one JSON
 * line with the result.
 */

use App\Arkon\Errors\ArkonException;
use App\Arkon\Pages\PageManagement;
use App\Arkon\Pages\PageService;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Json;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Raw JSON form, exactly like a request body (empty objects stay objects).
$job = Json::decode(base64_decode($argv[1]));
DB::connection()->getPdo();
echo "READY\n";
fflush(STDOUT);

$deadline = microtime(true) + 300;
while (! is_file($job['goFile'])) {
    if (microtime(true) > $deadline) {
        exit(1);
    }
    usleep(2_000);
}
$go = json_decode((string) file_get_contents($job['goFile']), true);
while (microtime(true) < ($go['startAt'] ?? 0)) {
    usleep(500);
}
usleep((int) (($job['delayMs'] ?? 0) * 1000));

$ctx = new SiteContext($job['ctx']['siteId'], $job['ctx']['userId']);
try {
    $result = match ($job['call']) {
        'saveDraft' => app(PageService::class)->saveDraft($ctx, $job['input']),
        'publish' => app(PageService::class)->publish($ctx, $job['input']),
        'createPage' => app(PageManagement::class)->create($ctx, $job['input']),
        'updateSettings' => app(PageManagement::class)->updateSettings($ctx, $job['input']),
        'restore' => app(PageService::class)->restoreRevision($ctx, $job['input']),
        'delete' => app(PageManagement::class)->delete($ctx, $job['input']),
        'unpublish' => app(PageManagement::class)->unpublish($ctx, $job['input']),
    };
    echo json_encode(['ok' => true, 'result' => $result])."\n";
} catch (ArkonException $error) {
    echo json_encode(['ok' => false, 'code' => $error->code(), 'class' => $error::class, 'message' => $error->getMessage()])."\n";
}
