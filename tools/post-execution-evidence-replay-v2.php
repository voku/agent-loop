<?php

declare(strict_types=1);

$workspace = dirname(__DIR__);
$target = $workspace . '/build/post-execution-evidence-replay';
$cli = $workspace . '/bin/agent-loop';

$remove = static function (string $directory): void {
    if (!is_dir($directory)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($directory);
};
$remove($target);
mkdir($target . '/src', 0o775, true);

$exec = static function (array $command, string $cwd, bool $mustPass = true): array {
    $escaped = implode(' ', array_map('escapeshellarg', $command));
    $process = proc_open($escaped, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start: ' . $escaped);
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    file_put_contents($cwd . '/command-log.txt', sprintf("\n$ %s\nexit=%d\n%s%s", $escaped, $exit, $stdout, $stderr), FILE_APPEND);
    if ($mustPass && $exit !== 0) {
        throw new RuntimeException("Command failed ({$exit}): {$escaped}\n{$stdout}\n{$stderr}");
    }
    return ['exit_code' => $exit, 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
};
$agent = static fn (array $args, bool $mustPass = true): array => $exec([PHP_BINARY, $cli, ...$args], $target, $mustPass);

$exec(['git', 'init', '-q'], $target);
$exec(['git', 'config', 'user.name', 'agent-loop-dogfood'], $target);
$exec(['git', 'config', 'user.email', 'agent-loop-dogfood@users.noreply.github.com'], $target);
file_put_contents($target . '/.gitignore', "/.agent-loop/\n");
$exec(['git', 'add', '.gitignore'], $target);
$exec(['git', 'commit', '-qm', 'baseline'], $target);

$agent(['init', 'scaffold']);
$initStatus = $agent(['init', 'status']);
file_put_contents($target . '/init-status.txt', $initStatus['stdout'] . $initStatus['stderr']);

file_put_contents($target . '/src/Example.php', "<?php\nfinal class Example { public static function value(): string { return 'A'; } }\n");
$agent([
    'workflow', 'plan', 'DEMO-1',
    '--by', 'chatgpt-host',
    '--file', 'src/Example.php',
    '--goal', 'Prove stale post-execution evidence from implementation A cannot close implementation B and fresh B evidence recovers.',
    '--validation', 'php -l src/Example.php',
]);
$agent(['map', 'build', '--paths=src']);
$agent(['map', 'search-index', 'build']);
$agent(['workflow', 'approve', 'DEMO-1', '--by', 'voku']);
$context = $agent(['workflow', 'context', 'DEMO-1', '--format', 'json']);
file_put_contents($target . '/context.json', $context['stdout']);
$systemPath = $target . '/.agent-loop/recall/DEMO-1/system.md';
if (!is_file($systemPath) || filesize($systemPath) === 0) {
    throw new RuntimeException('Recall system.md was not compiled.');
}
copy($systemPath, $target . '/consumed-system.md');

$lint = static fn (): array => $exec([PHP_BINARY, '-l', 'src/Example.php'], $target);
$recordValidation = static function () use ($agent): void {
    $agent([
        'session', 'validation', 'record', 'DEMO-1',
        '--contract-revision', '1',
        '--command', 'php -l src/Example.php',
        '--status', 'passed',
        '--exit-code', '0',
        '--by', 'dogfood-replay',
    ]);
};
$reviewAndLearn = static function (string $reason) use ($agent): void {
    $agent(['review', 'blindspots', 'DEMO-1']);
    $agent(['workflow', 'learn', 'DEMO-1', '--status', 'no_durable_learning', '--by', 'dogfood-replay', '--reason', $reason]);
};

$lint();
$recordValidation();
$reviewAndLearn('Implementation A evidence boundary recorded before any implementation commit.');

file_put_contents($target . '/src/Example.php', "<?php\nfinal class Example { public static function value(): string { return 'B'; } }\n");
$stale = $agent(['workflow', 'close', 'DEMO-1', '--status', 'done'], false);
$staleText = $stale['stdout'] . $stale['stderr'];
file_put_contents($target . '/stale-close.txt', $staleText);
if ($stale['exit_code'] === 0 || !str_contains($staleText, 'stale validation evidence')) {
    throw new RuntimeException('Implementation B was not explicitly blocked by stale A validation evidence.');
}

$lint();
$recordValidation();
$reviewAndLearn('Implementation B refreshed validation, review, and Learning after the stale A close was refused.');

$closeB = $agent(['workflow', 'close', 'DEMO-1', '--status', 'done']);
file_put_contents($target . '/close-b.txt', $closeB['stdout'] . $closeB['stderr']);
$status = $agent(['workflow', 'status', 'DEMO-1', '--format', 'json']);
$statusData = json_decode($status['stdout'], true, 512, JSON_THROW_ON_ERROR);
$finalState = $statusData['manifest']['state'] ?? null;
if ($finalState !== 'complete') {
    throw new RuntimeException('Final workflow state is not complete: ' . var_export($finalState, true));
}

$rows = [];
foreach (glob($target . '/.agent-loop/sessions/*/validation-evidence.jsonl') ?: [] as $path) {
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($row)) {
            $rows[] = $row;
        }
    }
}
$snapshots = array_values(array_unique(array_filter(array_column($rows, 'implementation_snapshot'), 'is_string')));
if (count($rows) !== 2 || count($snapshots) !== 2) {
    throw new RuntimeException('Expected append-only A/B validation history with two distinct snapshots.');
}
$gitStatus = $exec(['git', 'status', '--porcelain'], $target);
if (!str_contains($gitStatus['stdout'], 'src/Example.php')) {
    throw new RuntimeException('Replay did not preserve an uncommitted implementation state.');
}

$report = [
    'schema_version' => '1.0',
    'task_id' => 'DEMO-1',
    'contract_revision' => 1,
    'implementation_snapshot_a' => $snapshots[0],
    'implementation_snapshot_b' => $snapshots[1],
    'stale_close_exit_code' => $stale['exit_code'],
    'stale_validation_detected' => str_contains($staleText, 'stale validation evidence'),
    'validation_history_rows' => count($rows),
    'final_close_exit_code' => $closeB['exit_code'],
    'final_state' => $finalState,
    'implementation_committed_before_validation' => false,
    'standalone_verify_invoked_before_close' => false,
    'recall_system_consumed' => is_file($target . '/consumed-system.md'),
    'local_git_hooks' => 'not_exercised',
];
file_put_contents($target . '/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo "[OK] stale A evidence refused; fresh B evidence closed to complete.\n";
