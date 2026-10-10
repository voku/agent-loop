<?php

declare(strict_types=1);

use voku\AgentLoop\AgentGuidance\AgentDisciplineHook;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Workflow\WorkflowDreamAutoRun;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$layout = new ProjectLayout($root);
$sources = ['AGENTS.md', 'MEMORY.md', 'resources/skills/*/SKILL.md', 'docs/workflow/*.md'];
$stateFile = $layout->stateRoot() . '/dream/auto.json';

putenv('AGENT_LOOP_DREAM_AUTORUN=1');
putenv('AGENT_LOOP_GUIDANCE_SOURCES=' . json_encode($sources, JSON_THROW_ON_ERROR));

/** @return array<string, string> */
function sourceHashes(string $root, array $sources, string $learningRoot): array
{
    $result = [];
    foreach ($sources as $source) {
        foreach (glob($root . '/' . $source) ?: [] as $file) {
            if (is_file($file)) {
                $result[substr($file, strlen($root) + 1)] = (string) hash_file('sha256', $file);
            }
        }
    }
    if (is_dir($learningRoot)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($learningRoot, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            if ($file->isFile()) {
                $result[substr($file->getPathname(), strlen($root) + 1)] =
                    (string) hash_file('sha256', $file->getPathname());
            }
        }
    }
    ksort($result);

    return $result;
}

$before = sourceHashes($root, $sources, $layout->learningRoot());
$hook = new AgentDisciplineHook($root);
$session = $hook->contextOutput(
    'SessionStart',
    json_encode(['hook_event_name' => 'SessionStart'], JSON_THROW_ON_ERROR),
);
$context = $session['hookSpecificOutput']['additionalContext'];
if (!str_contains($context, 'guidance-consistency inspected')) {
    throw new RuntimeException('SessionStart did not surface guidance-consistency evidence.');
}
if (!is_file($stateFile)) {
    throw new RuntimeException('SessionStart did not write its regenerable Dream review state.');
}

$state = json_decode((string) file_get_contents($stateFile), true, flags: JSON_THROW_ON_ERROR);
if (
    !is_array($state)
    || ($state['guidance_sources'] ?? null) !== $sources
    || !is_int($state['guidance_candidates_total'] ?? null)
    || !is_array($state['guidance_candidates'] ?? null)
    || count($state['guidance_candidates']) > 25
    || $state['guidance_candidates_total'] < count($state['guidance_candidates'])
) {
    throw new RuntimeException('Incomplete or inconsistent guidance-consistency evidence.');
}
foreach ($state['guidance_candidates'] as $candidate) {
    if (
        !is_array($candidate)
        || !in_array($candidate['kind'] ?? null, ['unresolved_path', 'duplicate_wording'], true)
        || !is_string($candidate['source_a'] ?? null)
        || !is_string($candidate['evidence'] ?? null)
    ) {
        throw new RuntimeException('A guidance candidate is missing its source-backed facts.');
    }
}

$stateHash = hash_file('sha256', $stateFile);
$second = (new WorkflowDreamAutoRun($root))->runIfDue();
if (($second['ran'] ?? false) || hash_file('sha256', $stateFile) !== $stateHash) {
    throw new RuntimeException('Identical inputs unexpectedly rewrote the Dream review state.');
}

$after = sourceHashes($root, $sources, $layout->learningRoot());
if ($after !== $before) {
    throw new RuntimeException('Dream guidance review modified source guidance or Learning evidence.');
}

$report = [
    'schema_version' => '1.0',
    'repository' => 'voku/agent-loop',
    'commit_sha' => getenv('GITHUB_SHA') ?: null,
    'source_globs' => $sources,
    'source_files_hashed' => count($before),
    'guidance_candidate_total' => $state['guidance_candidates_total'],
    'guidance_candidate_shown' => count($state['guidance_candidates']),
    'guidance_candidates' => $state['guidance_candidates'],
    'dream_review_decisions' => $state['reviewDecisions'] ?? null,
    'source_bytes_unchanged' => true,
    'learning_bytes_unchanged' => true,
    'second_pass_noop' => true,
    'semantic_verdict' => null,
    'human_review_required' => $state['guidance_candidates_total'] > 0,
    'next_step' => 'Review each source-backed candidate; decisions and changes remain governed by their human owners.',
];
if (!is_dir($root . '/build') && !mkdir($root . '/build', 0o775, true) && !is_dir($root . '/build')) {
    throw new RuntimeException('Cannot create dogfood evidence directory.');
}
if (file_put_contents(
    $root . '/build/guidance-consistency-dogfood.json',
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
) === false) {
    throw new RuntimeException('Could not write dogfood evidence.');
}

printf(
    "Guidance audit on real repository: %d source-backed candidate(s), %d shown; no durable mutation.\n",
    $report['guidance_candidate_total'],
    $report['guidance_candidate_shown'],
);
