<?php

declare(strict_types=1);

$workspace = dirname(__DIR__);
$target = $workspace . '/build/post-execution-evidence-replay';
$cli = $workspace . '/bin/agent-loop';

$remove = static function (string $directory) use (&$remove): void {
    if (!is_dir($directory)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    ) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($directory);
};
$remove($target);
mkdir($target . '/src', 0o775, true);

$run = static function (array $arguments, bool $mustPass = true) use ($target, $cli): array {
    $command = [PHP_BINARY, $cli, ...$arguments];
    $escaped = implode(' ', array_map('escapeshellarg', $command));
    $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($escaped, $descriptor, $pipes, $target);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start: ' . $escaped);
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $result = ['exit_code' => $exit, 'stdout' => (string) $stdout, 'stderr' => (string) $stderr, 'command' => $escaped];
    if ($mustPass && $exit !== 0) {
        throw new RuntimeException("Command failed ({$exit}): {$escaped}\n{$stdout}\n{$stderr}");
    }

    return $result;
};

$run(['init', 'scaffold']);
$initStatus = $run(['init', 'status']);
file_put_contents($target . '/init-status.txt', $initStatus['stdout'] . $initStatus['stderr']);
if (!str_contains($initStatus['stdout'], '[OK] CLI:')) {
    throw new RuntimeException('init status did not prove an available CLI.');
}

file_put_contents($target . '/src/Example.php', "<?php\nreturn 'A';\n");
$run([
    'workflow', 'plan', 'DEMO-1',
    '--by', 'chatgpt-host',
    '--file', 'src/Example.php',
    '--goal', 'Prove stale post-execution evidence from implementation A cannot close implementation B and fresh B evidence recovers.',
    '--validation', 'php -l src/Example.php',
    '--behavior-anchor', 'A evidence must be rejected after B; B evidence must close to complete without a separate pre-close verify.',
]);
$run(['map', 'build', '--paths=src']);
$run(['map', 'search-index', 'build']);
$run(['workflow', 'approve', 'DEMO-1', '--by', 'voku']);
$context = $run(['workflow', 'context', 'DEMO-1', '--format', 'json']);
file_put_contents($target . '/context.json', $context['stdout']);
$systemPath = $target . '/.agent-loop/recall/DEMO-1/system.md';
if (!is_file($systemPath) || filesize($systemPath) === 0) {
    throw new RuntimeException('Recall system.md was not compiled.');
}
copy($systemPath, $target . '/consumed-system.md');

$lintACommand = [PHP_BINARY, '-l', 'src/Example.php'];
$lintA = static function (array $command, string $cwd): array {
    $escaped = implode(' ', array_map('escapeshellarg', $command));
    $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($escaped, $descriptor, $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to execute validation command.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    return ['exit_code' => $exit, 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
};
$validationA = $lintA($lintACommand, $target);
if ($validationA['exit_code'] !== 0) {
    throw new RuntimeException('Implementation A validation failed unexpectedly.');
}
$run([
    'session', 'validation', 'record', 'DEMO-1',
    '--contract-revision', '1',
    '--command', 'php -l src/Example.php',
    '--status', 'passed',
    '--exit-code', '0',
    '--by', 'dogfood-replay',
]);
$run(['review', 'blindspots', 'DEMO-1']);
$run([
    'workflow', 'learn', 'DEMO-1',
    '--status', 'no_durable_learning',
    '--by', 'dogfood-replay',
    '--reason', 'Implementation A produced no durable Learning beyond the tested evidence-lineage invariant.',
]);

file_put_contents($target . '/src/Example.php', "<?php\nreturn 'B';\n");
$staleClose = $run(['workflow', 'close', 'DEMO-1', '--status', 'done'], false);
$staleText = $staleClose['stdout'] . $staleClose['stderr'];
file_put_contents($target . '/stale-close.txt', $staleText);
if ($staleClose['exit_code'] === 0) {
    throw new RuntimeException('False green: implementation B closed using implementation A evidence.');
}
if (!str_contains($staleText, 'stale validation evidence')) {
    throw new RuntimeException('Stale close failed without identifying stale validation evidence.');
}

$validationB = $lintA($lintACommand, $target);
if ($validationB['exit_code'] !== 0) {
    throw new RuntimeException('Implementation B validation failed unexpectedly.');
}
$run([
    'session', 'validation', 'record', 'DEMO-1',
    '--contract-revision', '1',
    '--command', 'php -l src/Example.php',
    '--status', 'passed',
    '--exit-code', '0',
    '--by', 'dogfood-replay',
]);
$run(['review', 'blindspots', 'DEMO-1']);
$run([
    'workflow', 'learn', 'DEMO-1',
    '--status', 'no_durable_learning',
    '--by', 'dogfood-replay',
    '--reason', 'Implementation B refreshed validation/review evidence and the stale A close was correctly refused.',
]);

$closeB = $run(['workflow', 'close', 'DEMO-1', '--status', 'done']);
file_put_contents($target . '/close-b.txt', $closeB['stdout'] . $closeB['stderr']);
$status = $run(['workflow', 'status', 'DEMO-1', '--format', 'json']);
$statusData = json_decode($status['stdout'], true, 512, JSON_THROW_ON_ERROR);
$finalState = $statusData['manifest']['state'] ?? null;
if ($finalState !== 'complete') {
    throw new RuntimeException('Final workflow state is not complete: ' . var_export($finalState, true));
}

$sessionDirectories = glob($target . '/.agent-loop/sessions/*', GLOB_ONLYDIR) ?: [];
$validationRows = [];
foreach ($sessionDirectories as $sessionDirectory) {
    $path = $sessionDirectory . '/validation-evidence.jsonl';
    if (!is_file($path)) {
        continue;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($row)) {
            $validationRows[] = $row;
        }
    }
}
if (count($validationRows) !== 2) {
    throw new RuntimeException('Expected append-only A and B validation evidence.');
}
$snapshots = array_values(array_unique(array_filter(array_column($validationRows, 'implementation_snapshot'), 'is_string')));
if (count($snapshots) !== 2) {
    throw new RuntimeException('Expected distinct implementation snapshots for A and B.');
}

$report = [
    'schema_version' => '1.0',
    'task_id' => 'DEMO-1',
    'contract_revision' => 1,
    'implementation_snapshot_a' => $snapshots[0],
    'implementation_snapshot_b' => $snapshots[1],
    'stale_close_exit_code' => $staleClose['exit_code'],
    'stale_close_identified_validation' => str_contains($staleText, 'stale validation evidence'),
    'stale_close_identified_review' => str_contains($staleText, 'stale review evidence'),
    'stale_close_identified_learning' => str_contains($staleText, 'stale Learning decision'),
    'validation_history_rows' => count($validationRows),
    'final_close_exit_code' => $closeB['exit_code'],
    'final_state' => $finalState,
    'standalone_verify_invoked_before_close' => false,
    'recall_system_artifact_consumed_by_replay_harness' => is_file($target . '/consumed-system.md'),
    'local_git_hooks' => 'not_exercised',
];
file_put_contents($target . '/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

echo "[OK] post-execution evidence replay: A evidence refused for B; fresh B evidence closed to complete.\n";
