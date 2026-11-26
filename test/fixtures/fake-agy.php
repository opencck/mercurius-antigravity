<?php

declare(strict_types=1);

// Stands in for the Antigravity CLI in the checks. What it prints follows what the real agy 1.5.18 was seen to print
// without a sign-in and what the two Hermes plugins read from a signed-in one. A marker in the prompt picks the answer.

$args = array_slice($argv, 1);
$home = (string) getenv('HOME');
$data = $home . '/.gemini/antigravity-cli';
$signIn = $data . '/jetski-standalone-oauth-token';
$out = static function (array $event): void {
    echo json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
    flush();
};
$option = static function (string $name) use ($args): ?string {
    $at = array_search($name, $args, true);
    return $at === false ? null : ($args[$at + 1] ?? null);
};

if ($args === ['--version']) {
    echo "1.5.18\n";
    exit(0);
}
if ($args === ['models']) {
    echo "Fetching available models...\n";
    if (!is_file($signIn)) {
        echo "Error: Please sign in to view available models. Launch the CLI without arguments to sign in.\n";
        exit(0);
    }
    echo "gemini-4-hydrogen-low     Gemini 4 Hydrogen (low)\ngemini-4-hydrogen-high\ngemini-4-hydrogen-xhigh\ngemini-3.8-flash-low\ngemini-3.8-flash-medium\ngemini-3.8-flash-high\nclaude-sonnet-4-6\n";
    exit(0);
}
if (in_array('-p', $args, true)) {
    if (!is_file($signIn)) {
        echo "Authentication required. Please visit the URL to log in:\n  https://accounts.google.com/o/oauth2/auth?access_type=offline&client_id=fake&redirect_uri=https%3A%2F%2Fantigravity.google%2Foauth-callback&state=abc\n\n";
        echo "Waiting for authentication (timeout 60s)...\nOr, paste the authorization code here and press Enter:\n";
        flush();
        $code = trim((string) fgets(STDIN));
        if ($code === 'good-code') {
            @mkdir($data, 0700, true);
            file_put_contents($signIn, str_repeat('t', 64));
            echo "{\"status\":\"SUCCESS\"}\n";
            exit(0);
        }
        echo "\nError: authentication interrupted.\nerror: authentication failed or timed out\n";
        echo '{"conversation_id":"","status":"ERROR","response":"","error":"authentication failed or timed out","finished_reason":"error"}', "\n";
        exit(0);
    }
    $own = $home . '/usage.json';
    echo is_file($own) ? (string) file_get_contents($own) : json_encode(['conversation_id' => 'c', 'status' => 'SUCCESS', 'command' => ['name' => 'usage', 'data' => ['description' => 'Usage', 'groups' => [
        ['name' => 'Gemini Models', 'description' => 'Gemini Flash, Gemini Pro', 'buckets' => [
            ['id' => 'gemini-5h', 'window' => '5h', 'remaining_fraction' => 0.75, 'reset_time' => '2099-01-01T00:00:00Z'],
            ['id' => 'gemini-weekly', 'window' => 'weekly', 'remaining_fraction' => 0.9, 'reset_time' => '2099-01-05T00:00:00Z'],
        ]],
        ['name' => 'Claude and GPT models', 'description' => 'Claude Opus, Claude Sonnet, GPT-OSS', 'buckets' => [
            ['id' => '3p-5h', 'window' => '5h', 'remaining_fraction' => 0, 'reset_time' => '2099-01-01T00:00:00Z'],
        ]],
    ]]]], JSON_THROW_ON_ERROR);
    echo "\n";
    exit(0);
}

// a model call in stream-json
$log = (string) $option('--log-file');
$write = static function (string $line) use ($log): void {
    if ($log !== '') {
        file_put_contents($log, $line . "\n", FILE_APPEND);
    }
};
$frame = json_decode((string) fgets(STDIN), true);
$prompt = is_array($frame) ? (string) ($frame['message']['content'] ?? '') : '';
$id = '0f8fad5b-d9cb-469f-a165-70867728950e';
$usage = ['input_tokens' => 1000, 'output_tokens' => 5, 'thinking_tokens' => 3, 'cache_read_tokens' => 200, 'total_tokens' => 1005];
$step = static fn(array $fields): array => ['event' => 'step_update', 'step_update' => ['conversation_id' => $id] + $fields];
$write('I1126 20:59:16.853715 1 resolver.go:85] Model ID ' . $option('--model') . ' not in local config, defaulting to CCPA');

if (str_contains($prompt, '[[auth]]')) {
    fwrite(STDERR, "Error: authentication required. Run 'agy' to log in, then retry.\nerror: authentication failed or timed out\n");
    $out(['event' => 'result', 'result' => ['conversation_id' => '', 'status' => 'ERROR', 'response' => '', 'error' => 'authentication failed or timed out', 'finished_reason' => 'error']]);
    exit(1);
}
@mkdir($data . '/conversations', 0700, true);
file_put_contents($data . '/conversations/' . $id . '.pb', 'record');
if (str_contains($prompt, '[[fallback]]')) {
    $write('W1126 agents.go:12] agent "mercurius" not found, falling back to default');
}
$out(['event' => 'init', 'conversation_id' => $id, 'init' => ['model' => $option('--model'), 'agent' => $option('--agent'), 'effort' => $option('--effort'), 'cwd' => getcwd()]]);
if (str_contains($prompt, '[[silent]]')) {
    sleep(30);
}
if (str_contains($prompt, '[[quota]]')) {
    $write('I0921 21:19:51.264799 299 run.go:395] Run: attempt 1 failed (RESOURCE_EXHAUSTED (code 429): Individual quota reached. Please upgrade your subscription to increase your limits. Resets in 1h49m22s.), retrying in 4s');
    sleep(30);
}
foreach (['[[quota-result]]' => 'You have exhausted your capacity on this model', '[[verify]]' => 'Please verify your account to continue', '[[capacity]]' => 'No capacity available for this model'] as $marker => $error) {
    if (str_contains($prompt, $marker)) {
        fwrite(STDERR, 'AGY_ERROR: ' . json_encode(['short_error' => $error]) . "\n");
        $out(['event' => 'result', 'result' => ['conversation_id' => $id, 'status' => 'ERROR', 'response' => '', 'usage' => $usage, 'error' => $error]]);
        exit(1);
    }
}
$out($step(['step_index' => 0, 'state' => 'DONE', 'step_type' => 'user_input']));
if (str_contains($prompt, '[[unknown-step]]')) {
    $out($step(['step_index' => 1, 'state' => 'ACTIVE', 'step_type' => 'shell', 'command' => 'id']));
    sleep(30);
}
if (str_contains($prompt, '[[crash-with-log]]')) {
    $write('I1126 notice.go:1] By using this service you agree to the terms of service; verify your account settings');
    exit(1);
}
if (str_contains($prompt, '[[env]]')) {
    $text = json_encode(['env' => array_keys(getenv()), 'home' => $home, 'cwd' => getcwd(), 'files' => array_values(array_diff((array) scandir((string) getcwd()), ['.', '..'])), 'args' => $args], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $out(['event' => 'result', 'result' => ['conversation_id' => $id, 'status' => 'SUCCESS', 'response' => $text, 'usage' => $usage]]);
    exit(0);
}
foreach (['[[tool]]' => ['call_mcp_tool', ['ServerName' => 'mercurius', 'ToolName' => 'read', 'Arguments' => ['path' => 'a.txt']]],
    '[[tool-string]]' => ['call_mcp_tool', ['ServerName' => 'mercurius', 'ToolName' => 'read', 'Arguments' => '{"path":"b.txt"}']],
    '[[tool-broken]]' => ['call_mcp_tool', ['ServerName' => 'mercurius', 'ToolName' => 'read', 'Arguments' => '{"path":']],
    '[[foreign]]' => ['call_mcp_tool', ['ServerName' => 'mercurius', 'ToolName' => 'not_offered', 'Arguments' => []]],
    '[[other-server]]' => ['call_mcp_tool', ['ServerName' => 'github', 'ToolName' => 'read', 'Arguments' => []]],
    '[[native]]' => ['run_command', ['CommandLine' => 'cat /etc/passwd']]] as $marker => [$tool, $parameters]) {
    if (str_contains($prompt, $marker)) {
        $out($step(['step_index' => 1, 'state' => 'ACTIVE', 'step_type' => 'agent_response', 'text_delta' => 'Let me check.']));
        $out($step(['step_index' => 1, 'state' => 'DONE', 'step_type' => 'agent_response', 'text_delta' => '', 'usage' => $usage]));
        $out($step(['step_index' => 2, 'state' => 'ACTIVE', 'step_type' => 'tool', 'tool_name' => $tool, 'tool_info' => ['name' => $tool, 'parameters' => $parameters]]));
        // the real agy waits for the tool here; the service must stop it
        sleep(30);
        exit(0);
    }
}
$out($step(['step_index' => 1, 'state' => 'ACTIVE', 'step_type' => 'agent_response', 'text_delta' => 'Hello']));
$out($step(['step_index' => 1, 'state' => 'ACTIVE', 'step_type' => 'agent_response', 'text_delta' => ' world']));
$out($step(['step_index' => 1, 'state' => 'DONE', 'step_type' => 'agent_response', 'text_delta' => "\n", 'usage' => $usage]));
$out(['event' => 'result', 'result' => ['conversation_id' => $id, 'status' => 'SUCCESS', 'response' => "Hello world\n", 'duration_seconds' => 0.1, 'num_turns' => 1, 'usage' => $usage]]);
