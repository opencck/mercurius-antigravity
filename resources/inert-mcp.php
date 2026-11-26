<?php

declare(strict_types=1);

/**
 * The inert MCP server `agy` starts for a model call: it only lists the tools of the application, so `agy` knows them
 * by name. It runs nothing: a call of a tool gets no answer — the service sees the call in the stream of `agy` and
 * stops the process; only the application runs tools. It reads the list from the file named in the first argument
 * and speaks JSON-RPC over stdin and stdout, one message per line; it ends when `agy` closes its input.
 *
 * spec: services/antigravity/BRIEF.md#один-вызов-модели
 * ref: references/hermes-plugin-antigravity-agy/inert_mcp.py@ec07f86:L14-L40 — the same methods and answers, in PHP:
 *      the image of the service has no Python. The plugin also writes each call to a file nobody reads; here it is not
 *      written
 */

$manifest = json_decode((string) file_get_contents((string) ($argv[1] ?? '')), false, 512, JSON_THROW_ON_ERROR);
while (($line = fgets(STDIN)) !== false) {
    $row = json_decode($line, true);
    if (!is_array($row) || !array_key_exists('id', $row)) {
        continue;
    }
    $method = $row['method'] ?? null;
    if ($method === 'tools/call') {
        continue;
    }
    $result = match ($method) {
        'initialize' => ['protocolVersion' => is_string($row['params']['protocolVersion'] ?? null) ? $row['params']['protocolVersion'] : '2024-11-05', 'capabilities' => ['tools' => new stdClass()], 'serverInfo' => ['name' => 'mercurius', 'version' => '1']],
        'tools/list' => ['tools' => $manifest],
        'ping' => new stdClass(),
        default => null,
    };
    $answer = $result === null
        ? ['jsonrpc' => '2.0', 'id' => $row['id'], 'error' => ['code' => -32601, 'message' => 'Method not found']]
        : ['jsonrpc' => '2.0', 'id' => $row['id'], 'result' => $result];
    fwrite(STDOUT, json_encode($answer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    fflush(STDOUT);
}
