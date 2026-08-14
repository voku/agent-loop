<?php

declare(strict_types=1);

/**
 * Repository self-dogfood: agent-loop governs and validates its own diff.
 *
 * Was Bash. The move is not about portability - Git for Windows ships bash -
 * but about testability: every decision this gate makes now lives in
 * src/Dogfood/ with unit tests, and the two defects it shipped this month were
 * both in decisions a shell script could not test.
 *
 * This file is deliberately orchestration only. It runs commands, writes
 * evidence and reports; anything it decides, it asks a tested class.
 */

use voku\AgentLoop\Dogfood\ProcessRunner;
use voku\AgentLoop\Dogfood\RecallOutcomeDraft;
use voku\AgentLoop\Dogfood\RunProjectionAssertion;
use voku\AgentLoop\Dogfood\SelfShapeEvidence;
use voku\AgentLoop\Dogfood\SelfShapeRunRecovery;
use voku\AgentLoop\ProjectLayout;
use voku\AgentSession\SessionStore;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

const TASK = 'SELF-SHAPE';
const PLANNER = 'agent-loop-self-shape';

$options = [
    'base-ref' => getenv('GITHUB_BASE_REF') ?: 'main',
    'goal' => getenv('SELF_SHAPE_GOAL') ?: 'Govern and validate the current agent-loop diff through agent-loop itself.',
    'approver' => getenv('SELF_SHAPE_APPROVER') ?: 'ci-self-shape-approval-fixture',
    'pr-number' => getenv('SELF_SHAPE_PR_NUMBER') ?: '',
];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--(base-ref|goal|approver|pr-number)=(.*)$/', $argument, $matches) !== 1) {
        fwrite(STDERR, 'Unknown option: ' . $argument . "\n");
        exit(2);
    }
    $options[$matches[1]] = $matches[2];
}

$fail = static function (string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(1);
};

if (preg_match('#^[A-Za-z0-9._/-]+$#', $options['base-ref']) !== 1 || str_contains($options['base-ref'], '..')) {
    $fail('Invalid --base-ref value: ' . $options['base-ref']);
}
if (trim($options['goal']) === '') {
    $fail('Self-shape requires a non-empty goal.');
}
if (trim($options['approver']) === '' || $options['approver'] === PLANNER) {
    $fail('Self-shape approval fixture must name an actor distinct from the planner.');
}
if ($options['pr-number'] !== '' && preg_match('/^\d+$/', $options['pr-number']) !== 1) {
    $fail('Invalid --pr-number value: ' . $options['pr-number']);
}

$runner = new ProcessRunner($root);
$git = static fn (array $arguments): string => trim($runner->mustRun(['git', ...$arguments])['stdout']);
// Deliberately without `-n`, unlike the repository's *validation* subprocesses.
// The hermeticity rule exists so a `php -l` check cannot be swayed by a
// machine-local php.ini; it does not follow that our own CLI should run
// without one. agent-loop needs mbstring, which `-n` removes, so applying the
// rule here replaced an ambient-configuration risk with a hard failure.
$loop = static fn (array $arguments): array => $runner->mustRun([PHP_BINARY, 'bin/agent-loop', ...$arguments]);

$sessionToDrop = (new SelfShapeRunRecovery())->sessionToDrop(
    (new SessionStore())->all((new ProjectLayout($root))->sessionsRoot()),
    TASK,
    PLANNER,
);
if ($sessionToDrop !== null) {
    echo '[WARN] self-shape: dropping abandoned governed Session ' . $sessionToDrop . PHP_EOL;
    $loop(['session', 'close', $sessionToDrop, '--status', 'dropped']);
}

$base = $git(['merge-base', 'HEAD', 'origin/' . $options['base-ref']]);
$head = $git(['rev-parse', 'HEAD']);

$lines = static fn (string $output): array => array_values(array_filter(array_map('trim', explode("\n", $output))));
$changedFiles = $lines($git(['diff', '--name-only', '--diff-filter=ACMRTUXB', $base, 'HEAD', '--']));
if ($changedFiles === []) {
    $fail('Self-shape requires at least one changed file between the merge-base and HEAD.');
}

$evidence = new SelfShapeEvidence(
    $lines($git(['diff', '--name-only', '--diff-filter=AR', $base, 'HEAD', '--', '.agent-loop/learning/findings'])),
    $runner->run(['git', 'diff', '--quiet', $base, 'HEAD', '--', 'MEMORY.md'])['exit_code'] !== 0,
);
$learningStatus = $evidence->learningStatus();

if (!is_dir($root . '/build') && !mkdir($root . '/build', 0o775, true) && !is_dir($root . '/build')) {
    $fail('Unable to create build/.');
}
$rawDiff = 'build/self-shape-raw.diff';
file_put_contents($root . '/' . $rawDiff, $runner->mustRun(['git', 'diff', '--no-ext-diff', '--binary', $base, 'HEAD', '--'])['stdout']);
if (filesize($root . '/' . $rawDiff) === 0) {
    $fail('Self-shape raw diff evidence is empty.');
}

$planGoal = $options['pr-number'] === ''
    ? $options['goal']
    : sprintf('PR #%s: %s', $options['pr-number'], $options['goal']);

$json = static function (string $path, array $data): void {
    file_put_contents(
        $path,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n",
    );
};
$json($root . '/build/self-shape-input.json', [
    'task' => TASK,
    'planner' => PLANNER,
    'approval' => [
        'actor' => $options['approver'],
        'evidence_kind' => 'ci_pr_author_fixture',
        'independent_human_review' => false,
    ],
    'base' => $base,
    'head' => $head,
    'pr_number' => $options['pr-number'] === '' ? null : (int) $options['pr-number'],
    'goal' => $planGoal,
    'changed_files' => $changedFiles,
    'raw_diff' => $rawDiff,
]);

$loop(['learn', 'validate', '--root', '.agent-loop/learning']);

$plan = ['workflow', 'plan', TASK, '--by', PLANNER, '--base-commit', $base];
foreach ($changedFiles as $file) {
    $plan = [...$plan, '--file', $file];
}
$loop([
    ...$plan,
    '--goal', $planGoal,
    '--non-goal', 'Do not mutate the PR checkout, move package ownership, auto-promote findings to durable memory, or represent the CI approval fixture as independent human review.',
    '--validation', 'composer ci',
    '--behavior-anchor', 'real pull-request diff -> durable Contract -> governed Run -> bounded context -> observed validation -> review -> durable Learning decision -> Verification receipt -> pruneable Session',
]);
$loop(['workflow', 'approve', TASK, '--by', $options['approver']]);

$decode = static function (string $raw) use ($fail): array {
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        $fail('Expected JSON from agent-loop: ' . $exception->getMessage());
    }

    if (!is_array($decoded)) {
        $fail('Expected a JSON object from agent-loop.');
    }

    return $decoded;
};

$report = $decode($loop(['workflow', 'report', TASK, '--format', 'json'])['stdout']);
$metaPath = $report['recall']['meta_path'] ?? null;
if (!is_string($metaPath) || $metaPath === '') {
    $fail('Unable to resolve the Recall output root from agent-loop.');
}
$recallRoot = dirname($metaPath, 2);

$statusBefore = $decode($loop(['workflow', 'status', TASK, '--format', 'json'])['stdout']);
$runId = $statusBefore['manifest']['run_id'] ?? null;
if (!is_string($runId) || !str_starts_with($runId, 'run:')) {
    $fail('Unable to resolve the governed Run id.');
}
// Asked, not assumed. This was pinned to 1, which is right on a fresh CI
// checkout and wrong the moment a Contract is revised: the observation then
// attaches to a superseded revision and `workflow close` reports the validation
// evidence as missing, with nothing pointing at the revision as the cause.
$contractRevision = $statusBefore['manifest']['references']['contract']['revision'] ?? null;
if (!is_int($contractRevision) || $contractRevision < 1) {
    $fail('Unable to resolve the approved Contract revision.');
}

$loop([
    'session', 'checkpoint', TASK,
    '--title', 'CI approval fixture boundary',
    '--body', sprintf(
        "The approval transition was exercised with '%s'. This proves gate mechanics only; it is not independent human review evidence for the pull request.",
        $options['approver'],
    ),
]);
file_put_contents($root . '/build/self-shape-context.json', $loop(['workflow', 'context', TASK, '--format', 'json'])['stdout']);

$startedMs = (int) round(microtime(true) * 1000);
$validation = $runner->run(['composer', 'ci', '--no-ansi']);
$durationMs = (int) round(microtime(true) * 1000) - $startedMs;
echo $validation['stdout'], $validation['stderr'];
if ($validation['exit_code'] !== 0) {
    $fail('composer ci failed with exit ' . $validation['exit_code'] . '.');
}

$loop([
    'session', 'validation', 'record', TASK,
    '--contract-revision', (string) $contractRevision,
    '--command', 'composer ci',
    '--status', 'passed',
    '--exit-code', '0',
    '--duration-ms', (string) $durationMs,
    '--by', PLANNER,
]);

// `review blindspots` exits 1 on a fail status; before close-out evidence
// exists a warn is expected, so the first pass is informational.
$runner->run([PHP_BINARY, 'bin/agent-loop', 'review', 'blindspots', TASK]);
$outcomeDraft = $recallRoot . '/' . TASK . '/recall-log.draft.json';
$withheldCount = RecallOutcomeDraft::withholdInFile(
    $outcomeDraft,
    'The self-shape runner compiles Recall to exercise the governed lifecycle and never reads system.md, so it has no evidence about whether the selected guidance helped.',
);
echo '[OK] self-shape: withheld ' . $withheldCount . ' compiled guidance judgement(s); selection is still recorded.' . "\n";
$loop([
    'recall', 'log-outcome',
    '--root', '.agent-loop/learning',
    '--draft', $outcomeDraft,
    '--by', PLANNER,
    '--commit', $head,
]);
$loop([
    'session', 'checkpoint', TASK,
    '--title', 'Review close-out',
    '--body', 'Recall outcomes were logged and the deterministic blind-spot report was inspected against ' . $base . '.',
]);
$runner->run([PHP_BINARY, 'bin/agent-loop', 'review', 'blindspots', TASK]);
$loop(['review', 'code', TASK]);

$codeReviewPrompt = $recallRoot . '/' . TASK . '/reviews/' . TASK . '.code.prompt.md';
if (!is_file($codeReviewPrompt) || filesize($codeReviewPrompt) === 0) {
    $fail('Missing code-review prompt: ' . $codeReviewPrompt);
}
$blindSpotReport = $recallRoot . '/' . TASK . '/reviews/' . TASK . '.blindspots.json';
if (!is_file($blindSpotReport)) {
    $fail('Missing blind-spot report: ' . $blindSpotReport);
}

// The status is read, not inferred from the exit code: `workflow close` owns
// the gate that refuses a missing, invalid or fail report, so the harness
// records the residual result instead of asserting a second copy of it.
$blindSpots = $decode((string) file_get_contents($blindSpotReport));
$blindSpotStatus = $blindSpots['status'] ?? null;
if (!is_string($blindSpotStatus)) {
    $fail('Blind-spot report has no machine-readable status.');
}
$residual = [];
foreach (is_array($blindSpots['findings'] ?? null) ? $blindSpots['findings'] : [] as $finding) {
    $id = is_array($finding) ? ($finding['id'] ?? null) : null;
    if (is_string($id)) {
        $residual[] = $id;
    }
}
$json($root . '/build/self-shape-review-evidence.json', [
    'raw_diff' => ['path' => $rawDiff, 'sha256' => hash_file('sha256', $root . '/' . $rawDiff), 'complete' => true],
    'code_review_prompt' => ['path' => $codeReviewPrompt, 'sha256' => hash_file('sha256', $codeReviewPrompt)],
    'blindspot_review' => ['path' => $blindSpotReport, 'status' => $blindSpotStatus, 'residual_findings' => $residual],
    'correctness_review' => [
        'status' => 'external_required',
        'note' => 'CI preserves the complete raw diff and bounded review input; it does not claim independent human/model correctness review occurred.',
    ],
]);

$learn = ['workflow', 'learn', TASK, '--status', $learningStatus, '--by', PLANNER, '--reason', $evidence->learningReason()];
foreach ($evidence->recordedFindingIds() as $findingId) {
    $learn = [...$learn, '--finding', $findingId];
}
$loop($learn);

$memoryReview = $loop(['memory', 'review', '--file=MEMORY.md'])['stdout'];
echo $memoryReview;
if (!str_contains($memoryReview, 'Rows still needing promotion review: 0')) {
    $fail('MEMORY still has rows needing promotion review.');
}

file_put_contents($root . '/build/self-shape-manifest-before-close.json', $loop(['workflow', 'manifest', TASK, '--write', '--format', 'json'])['stdout']);
$loop(['verify', '--task-id=' . TASK]);

$reportArgs = ['workflow', 'report', TASK, '--format', 'json'];
foreach ($changedFiles as $file) {
    $reportArgs = [...$reportArgs, '--changed-file', $file];
}
file_put_contents($root . '/build/self-shape-report.json', $loop($reportArgs)['stdout']);
$loop(['workflow', 'close', TASK, '--status', 'done']);

$assertion = new RunProjectionAssertion();
$statusRaw = $loop(['workflow', 'status', TASK, '--format', 'json'])['stdout'];
file_put_contents($root . '/build/self-shape-status.json', $statusRaw);
$problems = $assertion->beforePrune($decode($statusRaw), $runId, $learningStatus);
if ($problems !== []) {
    $fail("Final workflow projection is not complete and owner-consistent:\n- " . implode("\n- ", $problems));
}

$loop(['session', 'prune', '--keep-days', '0', '--status', 'done']);
$postPruneRaw = $loop(['workflow', 'status', TASK, '--format', 'json'])['stdout'];
file_put_contents($root . '/build/self-shape-status-post-prune.json', $postPruneRaw);
$problems = $assertion->afterPrune($decode($postPruneRaw), $runId);
if ($problems !== []) {
    $fail("Pruning Session working memory changed durable Run semantics:\n- " . implode("\n- ", $problems));
}

$postPruneReport = $loop([...$reportArgs])['stdout'];
file_put_contents($root . '/build/self-shape-report-post-prune.json', $postPruneReport);
if (!str_contains($postPruneReport, '"source": "verification_receipt"')) {
    $fail('The post-prune report no longer explains itself from the verification receipt.');
}

printf(
    "Self-shape dogfood: PASSED\nBase: %s\nHead: %s\nRun: %s\nChanged files: %d\nGoal: %s\nApproval fixture: %s\nValidation duration: %d ms\nLearning decision: %s\nSession prune replay: passed\n",
    $base,
    $head,
    $runId,
    count($changedFiles),
    $planGoal,
    $options['approver'],
    $durationMs,
    $learningStatus,
);
