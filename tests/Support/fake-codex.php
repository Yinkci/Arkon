<?php

$args = array_slice($argv, 1);
$scenario = 'ok';
foreach ($args as $a) {
    if (str_starts_with($a, '--scenario=')) {
        $scenario = substr($a, 11);
    }
}
if (in_array('--version', $args, true)) {
    echo 'codex-cli 0.160.0';
    exit;
}
if (in_array('--help', $args, true)) {
    echo '--ignore-user-config --ephemeral';
    exit;
}
if (in_array('login', $args, true)) {
    if ($scenario === 'loggedout') {
        fwrite(STDERR, 'Not logged in');
        exit(1);
    } fwrite(STDERR, $scenario === 'apikey' ? 'Logged in using an API key' : 'Logged in using ChatGPT');
    exit;
}
if (in_array('features', $args, true)) {
    echo "apps                                     stable             true\n";
    echo 'image_generation                         stable             '.($scenario === 'noimages' ? 'false' : 'true')."\n";
    exit;
}
if (in_array('features.image_generation=true', $args, true)) {
    // An image run: like Codex, the image is saved under CODEX_HOME/generated_images/<thread id>/.
    $home = getenv('CODEX_HOME');
    $thread = '019fa89a-bb96-7262-9fed-'.substr(bin2hex(random_bytes(6)), 0, 12);
    file_put_contents($home.'/last-args.json', json_encode(['args' => $args, 'stdin' => stream_get_contents(STDIN), 'env' => array_keys(getenv())]));
    echo json_encode(['type' => 'thread.started', 'thread_id' => $thread])."\n";
    if ($scenario === 'limit') {
        echo json_encode(['type' => 'turn.failed', 'error' => ['message' => 'You have hit your usage limit.']])."\n";
        exit(1);
    }
    // Another thread's image (the user's own Codex work) must never be picked up.
    @mkdir($home.'/generated_images/019f0000-0000-7000-8000-000000000000', 0777, true);
    copy($home.'/source.png', $home.'/generated_images/019f0000-0000-7000-8000-000000000000/call_other.png');
    if ($scenario !== 'nosave' && $scenario !== 'refuse') {
        @mkdir($home.'/generated_images/'.$thread, 0777, true);
        copy($home.'/source.png', $home.'/generated_images/'.$thread.'/call_image.png');
    }
    $output = $args[array_search('--output-last-message', $args, true) + 1];
    file_put_contents($output, json_encode($scenario === 'refuse' ? ['generated' => false, 'problem' => 'This request was refused.'] : ['generated' => true, 'problem' => null]));
    echo json_encode(['type' => 'turn.completed', 'usage' => ['input_tokens' => 50, 'output_tokens' => 10]])."\n";
    exit;
}
// The model stream as Codex traces it on stderr (RUST_LOG tungstenite::protocol=trace): one line per frame.
$frame = function (string $type, ?string $delta = null) {
    $event = ['type' => $type, ...($delta !== null ? ['delta' => $delta] : [])];
    fwrite(STDERR, gmdate('Y-m-d\TH:i:s').'.000000Z TRACE tungstenite::protocol: Received message '.json_encode($event)."\n");
};
if (in_array($scenario, ['streaming', 'runaway', 'stall'], true)) {
    file_put_contents(getenv('CODEX_HOME').'/rust-log.txt', (string) getenv('RUST_LOG'));
    echo json_encode(['type' => 'turn.started']).PHP_EOL;
    $frame('response.created');
    $frame('response.output_text.delta', '{"ok":true,"secret":"SECRET_TOKEN"');
    if ($scenario === 'runaway') {
        // The degenerate reply: whitespace without end.
        for ($i = 0; $i < 4000; $i++) {
            $frame('response.output_text.delta', "     \t     \n");
            usleep(2000);
        }
        exit;
    }
    if ($scenario === 'stall') {
        sleep(20);
        exit;
    }
    // Writing for longer than the step would allow without stream evidence (stdout stays silent meanwhile).
    for ($i = 0; $i < 20; $i++) {
        usleep(200_000);
        $frame('response.output_text.delta', ',"n":'.$i);
    }
    $frame('response.completed');
    file_put_contents($args[array_search('--output-last-message', $args, true) + 1], json_encode(['ok' => true]));
    echo json_encode(['type' => 'turn.completed', 'usage' => ['input_tokens' => 5, 'output_tokens' => 2]])."\n";
    exit;
}
if ($scenario === 'limit') {
    echo json_encode(['type' => 'turn.started']).PHP_EOL;
    echo json_encode(['type' => 'turn.failed', 'error' => ['message' => 'You’ve hit your usage limit. Upgrade to Pro, visit https://chatgpt.com/codex/settings/usage to purchase more credits or try again at 5:02 PM.']]).PHP_EOL;
    exit(1);
}
if ($scenario === 'silent') {
    // Started, but the model turn never begins (no events at all).
    sleep(20);
    exit;
}
if ($scenario === 'reconnect') {
    echo json_encode(['type' => 'turn.started']).PHP_EOL;
    foreach ([1, 2] as $n) {
        echo json_encode(['type' => 'item.completed', 'item' => ['id' => 'e'.$n, 'type' => 'error', 'message' => "Reconnecting... {$n}/5 (stream disconnected before completion)"]]).PHP_EOL;
    }
}
if ($scenario === 'timeout') {
    sleep(15);
    exit;
}
if ($scenario === 'schema') {
    echo json_encode(['type' => 'turn.failed', 'error' => ['message' => "Invalid schema for response_format 'codex_output_schema': reference to component 'pageProposal' which was not found. SECRET_TOKEN"]]).'
';
    exit(1);
}
if ($scenario === 'failure') {
    fwrite(STDERR, 'private provider diagnostic SECRET_TOKEN');
    exit(1);
}
$stdin = stream_get_contents(STDIN);
$output = $args[array_search('--output-last-message', $args, true) + 1];
$result = $scenario === 'invalid' ? 'not json' : json_encode(['ok' => true, 'stdin' => $stdin, 'args' => $args, 'env' => array_keys(getenv()), 'cwd' => getcwd()]);
file_put_contents($output, $result);
echo json_encode(['type' => 'turn.completed', 'usage' => ['input_tokens' => 5, 'output_tokens' => 2]])."\n";
