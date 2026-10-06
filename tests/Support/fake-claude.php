<?php

/*
 * A stand-in for the Claude Code CLI, for ClaudeCodeCliTest: no network, no login.
 * Usage (as ARKON_CLAUDE_COMMAND / ClaudeCodeCli command): [php, fake-claude.php, --scenario=NAME, ...claude args]
 *
 *   --version                  "2.1.292 (Claude Code)"  (scenario "old": 2.1.100; "noversion": exits 1)
 *   auth status --json         subscription login       ("apikey": api_key mode, "loggedout": not signed in)
 *   -p ...                     reads the prompt from stdin, then per scenario:
 *       echo       success; structured_output reports what it received (args, stdin, env names, cwd files, system prompt)
 *       limit      the subscription usage limit error, as Claude Code reports it
 *       login      a login error
 *       garbage    not JSON
 *       noschema   success without structured_output and with a non-JSON result
 *       slow       sleeps 20 s first
 */

$args = array_slice($argv, 1);
$scenario = 'echo';
if (isset($args[0]) && str_starts_with($args[0], '--scenario=')) {
    $scenario = substr(array_shift($args), 11);
}

if ($args === ['--version']) {
    if ($scenario === 'noversion') {
        exit(1);
    }
    echo ($scenario === 'old' ? '2.1.100' : '2.1.292')." (Claude Code)\n";
    exit(0);
}

if (array_slice($args, 0, 2) === ['auth', 'status']) {
    $status = match ($scenario) {
        'apikey' => ['loggedIn' => true, 'authMethod' => 'api_key', 'apiProvider' => 'firstParty'],
        'loggedout' => ['loggedIn' => false, 'authMethod' => 'none'],
        default => ['loggedIn' => true, 'authMethod' => 'claude.ai', 'apiProvider' => 'firstParty', 'email' => 'someone@example.com', 'subscriptionType' => 'pro'],
    };
    echo json_encode($status)."\n";
    exit($status['loggedIn'] ? 0 : 1);
}

$stdin = stream_get_contents(STDIN);
if ($scenario === 'slow') {
    sleep(20);
}
$value = fn (string $flag) => ($i = array_search($flag, $args, true)) === false ? null : ($args[$i + 1] ?? null);
$result = fn (array $fields) => print json_encode(['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'num_turns' => 2, 'session_id' => 'fake', ...$fields])."\n";

switch ($scenario) {
    case 'limit':
        $result(['is_error' => true, 'result' => 'Claude AI usage limit reached|1791331200', 'api_error_status' => 429]);
        exit(1);
    case 'login':
        $result(['is_error' => true, 'result' => 'Invalid API key · Please run /login']);
        exit(1);
    case 'garbage':
        echo "this is not json\n";
        exit(0);
    case 'noschema':
        $result(['result' => 'Here is your page!']);
        exit(0);
    case 'empty':
        $result(['result' => '{}', 'structured_output' => ['summary' => 'Nothing to change: '.substr(trim($stdin), -40), 'notes' => ['Answered by the fake CLI.'], 'changes' => []]]);
        exit(0);
    default:
        $systemPromptFile = $value('--system-prompt-file');
        $result(['result' => '{}', 'structured_output' => [
            'args' => $args,
            'stdin' => $stdin,
            'env' => array_keys(getenv()),
            'cwd' => getcwd(),
            'cwdFiles' => array_values(array_diff(scandir(getcwd()) ?: [], ['.', '..'])),
            'systemPrompt' => $systemPromptFile && is_file($systemPromptFile) ? file_get_contents($systemPromptFile) : null,
            'schema' => json_decode((string) $value('--json-schema'), true),
        ]]);
        exit(0);
}
