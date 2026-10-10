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
if ($scenario === 'timeout') {
    sleep(15);
    exit;
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
