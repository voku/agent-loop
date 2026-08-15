<?php

declare(strict_types=1);

use voku\AgentLoop\Dogfood\ProcessRunner;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$runner = new ProcessRunner($root);
$run = static fn (array $command): array => $runner->mustRun($command);

$init = $run([PHP_BINARY, 'bin/agent-loop', 'init', 'status']);
file_put_contents($root . '/build/post-execution-init-status.txt', $init['stdout'] . $init['stderr']);
if (preg_match('/\[OK\]\s+CLI:\s+(\S+)/', $init['stdout'], $matches) !== 1) {
    throw new RuntimeException('init status did not report an available agent-loop CLI path.');
}
$cli = $matches[1];
$loop = static fn (array $arguments): array => $run([PHP_BINARY, $cli, ...$arguments]);

$existing = $runner->run([PHP_BINARY, $cli, 'workflow', 'status', 'LOOP-132', '--format', 'json']);
file_put_contents(
    $root . '/build/post-execution-status-before.json',
    $existing['stdout'] !== '' ? $existing['stdout'] : json_encode(['exit_code' => $existing['exit_code']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
);

$loop([
    'workflow', 'plan', 'LOOP-132',
    '--by', 'chatgpt-host',
    '--file', 'src/Workflow',
    '--file', 'src/Dispatcher.php',
    '--file', 'src/Run/RunVerificationReceiptStore.php',
    '--file', 'tests',
    '--file', 'tools/self-shape-dogfood.php',
    '--file', 'composer.json',
    '--file', 'docs/agents/LIFECYCLE.md',
    '--file', 'docs/agents/skills/agent-loop-workflow/SKILL.md',
    '--file', 'docs/agents/skills/agent-loop-task-start/SKILL.md',
    '--file', 'docs/agents/skills/agent-loop-review-close/SKILL.md',
    '--goal', 'Bind post-execution validation, review, and Learning evidence to the implementation being closed, make CLOSE prove complete, and remove duplicate mandatory final verification without redesigning agent architecture.',
    '--non-goal', 'Do not add a generic evidence subsystem, require commits before validation, redesign MEMORY/Proposal/map ownership, authenticate humans, or merge implementation snapshot identity with shipping identity.',
    '--validation', 'composer ci',
    '--behavior-anchor', 'implementation A -> validation/review/learning A -> implementation B -> stale A evidence must not close B -> current B evidence -> close -> complete',
]);

// Scope exists now. Build readiness for the approved Contract after PLAN, not before it.
$loop(['map', 'build', '--paths=src,tests,tools']);
$loop(['map', 'search-index', 'build']);
$loop(['workflow', 'approve', 'LOOP-132', '--by', 'voku']);

file_put_contents($root . '/build/post-execution-context.json', $loop(['workflow', 'context', 'LOOP-132', '--format', 'json'])['stdout']);
file_put_contents($root . '/build/post-execution-status-approved.json', $loop(['workflow', 'status', 'LOOP-132', '--format', 'json'])['stdout']);

$system = $root . '/.agent-loop/recall/LOOP-132/system.md';
if (!is_file($system) || filesize($system) === 0) {
    throw new RuntimeException('Governed Recall did not produce LOOP-132/system.md.');
}
copy($system, $root . '/build/post-execution-system.md');

file_put_contents(
    $root . '/build/post-execution-capabilities.json',
    json_encode([
        'schema_version' => '1.0',
        'task_id' => 'LOOP-132',
        'cli_path_from_init_status' => $cli,
        'plan_before_scope_readiness' => true,
        'recall_compiled' => true,
        'recall_system_consumption_by_acting_host' => 'pending_external_host_read',
        'local_git_hooks' => 'not_exercised',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
);

echo "[OK] LOOP-132 PLAN -> scope-aware map readiness -> APPROVE -> CONTEXT completed.\n";
