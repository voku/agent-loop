<?php

declare(strict_types=1);

use Fixture\RequestService;
use Fixture\RetryPolicy;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$service = new RequestService(new RetryPolicy());
$actual = $service->retryDelayForTimeout(2);
if ($actual !== 400) {
    fwrite(STDERR, sprintf("Expected retry delay 400 after the fixture edit, got %d.\n", $actual));

    exit(1);
}

$skillRoot = $root . '/managed-skills/demo-skill';
mkdir($skillRoot, 0o775, true);
file_put_contents($skillRoot . '/SKILL.md', "# Managed drift fixture\n");

try {
    runFixtureCommand($root, [
        'vendor/bin/agent-loop',
        'init',
        'sync-skills',
        '--agent=codex',
        '--skills-root=managed-skills',
        '--force',
    ]);

    $manifestPath = $root . '/.codex/skills/.agent-loop-manifest.json';
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($manifest)) {
        throw new RuntimeException('Managed projection manifest did not decode to an object.');
    }
    $manifest['required_capabilities'] = ['fixture-unsupported-runtime-capability'];
    file_put_contents(
        $manifestPath,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
    );

    $statusCommand = ['vendor/bin/agent-loop', 'init', 'status', '--skills-root=managed-skills'];
    $mismatch = runFixtureCommand($root, $statusCommand);
    $incompatible = '[WARN] codex skills: incompatible with installed runtime capabilities: demo-skill';
    if (!str_contains($mismatch, $incompatible)) {
        throw new RuntimeException('Installed runtime did not diagnose deliberate managed capability drift.');
    }
    echo 'managed drift diagnosis: ' . $incompatible . "\n";

    runFixtureCommand($root, [
        'vendor/bin/agent-loop',
        'init',
        'sync-skills',
        '--agent=codex',
        '--skills-root=managed-skills',
        '--force',
    ]);
    $repaired = runFixtureCommand($root, $statusCommand);
    $current = '[OK] codex skills: current: demo-skill';
    if (!str_contains($repaired, $current) || str_contains($repaired, $incompatible)) {
        throw new RuntimeException('Managed capability drift repair did not restore a current projection baseline.');
    }
    echo 'managed drift repair: ' . $current . "\n";
} finally {
    removeFixtureTree($root . '/managed-skills');
    removeFixtureTree($root . '/.codex/skills');
    if (is_dir($root . '/.codex') && (scandir($root . '/.codex') ?: []) === ['.', '..']) {
        rmdir($root . '/.codex');
    }
}

echo "fixture validation passed\n";

/** @param list<string> $command */
function runFixtureCommand(string $root, array $command): string
{
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $root,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start fixture command: ' . implode(' ', $command));
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        throw new RuntimeException(sprintf(
            "Fixture command failed (%d): %s\n%s",
            $exit,
            implode(' ', $command),
            is_string($stderr) ? $stderr : '',
        ));
    }

    return is_string($stdout) ? $stdout : '';
}

function removeFixtureTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    foreach (array_values(array_diff(scandir($path) ?: [], ['.', '..'])) as $entry) {
        $child = $path . '/' . $entry;
        if (is_dir($child) && !is_link($child)) {
            removeFixtureTree($child);
        } else {
            unlink($child);
        }
    }
    rmdir($path);
}
