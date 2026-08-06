<?php

declare(strict_types=1);

use voku\AgentLoop\AgentGuidance\AgentDisciplineHook;

$repositoryRoot = dirname(__DIR__, 2);
$autoload = $repositoryRoot . '/vendor/autoload.php';
$classFile = $repositoryRoot . '/src/AgentGuidance/AgentDisciplineHook.php';
if (is_file($autoload)) {
    require $autoload;
} elseif (is_file($classFile)) {
    require $classFile;
} else {
    fwrite(STDERR, "agent-loop hook runtime is unavailable.\n");
    exit(1);
}

$rawPayload = stream_get_contents(STDIN, 1_048_577);
if (!is_string($rawPayload)) {
    fwrite(STDERR, "Unable to read hook payload.\n");
    exit(1);
}

try {
    echo json_encode(
        (new AgentDisciplineHook($repositoryRoot))->preToolUseOutput($rawPayload),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    ) . "\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, 'agent-loop PreToolUse hook failed: ' . $throwable->getMessage() . "\n");
    exit(1);
}
