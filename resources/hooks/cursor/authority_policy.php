<?php

declare(strict_types=1);

use JsonException;
use stdClass;

const AGENT_LOOP_CURSOR_MAX_INPUT_BYTES = 1_048_576;

$rawPayload = stream_get_contents(STDIN, AGENT_LOOP_CURSOR_MAX_INPUT_BYTES + 1);
if (!is_string($rawPayload)) {
    fwrite(STDERR, "Unable to read Cursor hook payload.\n");
    exit(1);
}
if ($rawPayload === '' || strlen($rawPayload) > AGENT_LOOP_CURSOR_MAX_INPUT_BYTES) {
    fwrite(STDERR, "Cursor hook payload is empty or exceeds 1 MiB.\n");
    exit(1);
}

try {
    $payload = json_decode($rawPayload, false, 64, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fwrite(STDERR, 'Cursor hook payload is not valid JSON: ' . $exception->getMessage() . "\n");
    exit(1);
}

if (!$payload instanceof stdClass) {
    fwrite(STDERR, "Cursor hook payload must be a JSON object.\n");
    exit(1);
}

$command = $payload->command ?? null;
if (!is_string($command) || trim($command) === '') {
    fwrite(STDERR, "Cursor beforeShellExecution payload is missing a non-empty command.\n");
    exit(1);
}

$blockedCommands = [
    '~(?:^|&&|\\|\\||[;|()\\n])\\s*git[ \\t]+push(?:[ \\t]|$)~' => 'git push',
    '~(?:^|&&|\\|\\||[;|()\\n])\\s*gh[ \\t]+pr[ \\t]+create(?:[ \\t]|$)~' => 'gh pr create',
    '~(?:^|&&|\\|\\||[;|()\\n])\\s*gh[ \\t]+pr[ \\t]+merge(?:[ \\t]|$)~' => 'gh pr merge',
];

foreach ($blockedCommands as $pattern => $label) {
    $matched = preg_match($pattern, $command);
    if ($matched === false) {
        fwrite(STDERR, "Cursor authority policy regex failed.\n");
        exit(1);
    }
    if ($matched !== 1) {
        continue;
    }

    echo json_encode([
        'permission' => 'deny',
        'user_message' => 'agent-loop blocks authority-bearing remote mutation: ' . $label . '.',
        'agent_message' => 'Remote mutation requires explicit human authority outside the autonomous Cursor path.',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

echo json_encode(
    ['permission' => 'allow'],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
) . "\n";
