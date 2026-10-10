<?php

declare(strict_types=1);

namespace voku\AgentLoop\Dogfood;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\AgentGuidance\AgentDisciplineHook;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Workflow\WorkflowDreamAutoRun;

/**
 * Read-only real-repository integration evidence, never a contradiction verdict.
 *
 * The dev-only runner is owned by the statically checked Dogfood namespace.
 */
final readonly class GuidanceConsistencyDogfood
{
    /** @var list<string> */
    private const array SOURCES = [
        'AGENTS.md',
        'MEMORY.md',
        'resources/skills/*/SKILL.md',
        'docs/workflow/*.md',
    ];

    public function __construct(private string $repositoryRoot)
    {
    }

    public function run(): void
    {
        if (
            getenv('AGENT_LOOP_GUIDANCE_SOURCES') !== json_encode(self::SOURCES, JSON_THROW_ON_ERROR)
            || getenv('AGENT_LOOP_DREAM_AUTORUN') !== '1'
        ) {
            throw new RuntimeException('Dogfood needs its exact guidance source configuration and enabled Dream preview.');
        }

        $layout = new ProjectLayout($this->repositoryRoot);
        $stateFile = $layout->stateRoot() . '/dream/auto.json';
        $before = $this->sourceHashes($layout->learningRoot());

        $session = (new AgentDisciplineHook($this->repositoryRoot))->contextOutput(
            'SessionStart',
            json_encode(['hook_event_name' => 'SessionStart'], JSON_THROW_ON_ERROR),
        );
        if (!str_contains($session['hookSpecificOutput']['additionalContext'], 'guidance-consistency inspected')) {
            throw new RuntimeException('SessionStart did not surface owner guidance-consistency evidence.');
        }
        if (!is_file($stateFile)) {
            throw new RuntimeException('SessionStart did not write its regenerable Dream preview.');
        }

        $state = json_decode((string) file_get_contents($stateFile), true, flags: JSON_THROW_ON_ERROR);
        if (
            !is_array($state)
            || ($state['guidance_sources'] ?? null) !== self::SOURCES
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
                throw new RuntimeException('Guidance candidate lacks source-backed evidence.');
            }
        }

        $stateHash = $this->fileHash($stateFile);
        $second = (new WorkflowDreamAutoRun($this->repositoryRoot))->runIfDue();
        if (($second['ran'] ?? false) || $this->fileHash($stateFile) !== $stateHash) {
            throw new RuntimeException('Identical input unexpectedly rewrote the Dream preview.');
        }

        if ($this->sourceHashes($layout->learningRoot()) !== $before) {
            throw new RuntimeException('Dream review modified guidance or Learning evidence.');
        }

        $report = [
            'schema_version' => '1.0',
            'repository' => 'voku/agent-loop',
            'commit_sha' => getenv('GITHUB_SHA') ?: null,
            'source_globs' => self::SOURCES,
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
            'next_step' => 'Review source-backed candidates; changes remain human-governed.',
        ];
        $directory = $this->repositoryRoot . '/build';
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create dogfood evidence directory.');
        }
        if (file_put_contents(
            $directory . '/guidance-consistency-dogfood.json',
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        ) === false) {
            throw new RuntimeException('Could not write the dogfood report.');
        }
        printf(
            "Real repository guidance audit: %d candidate(s), %d shown; no durable mutation.\n",
            $report['guidance_candidate_total'],
            $report['guidance_candidate_shown'],
        );
    }

    /** @return array<string, non-empty-string> repository-relative path => digest */
    private function sourceHashes(string $learningRoot): array
    {
        $result = [];
        foreach (self::SOURCES as $source) {
            foreach (glob($this->repositoryRoot . '/' . $source) ?: [] as $file) {
                if (is_file($file)) {
                    $result[substr($file, strlen($this->repositoryRoot) + 1)] = $this->fileHash($file);
                }
            }
        }

        if (is_dir($learningRoot)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($learningRoot, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($files as $file) {
                if ($file->isFile()) {
                    $result[substr($file->getPathname(), strlen($this->repositoryRoot) + 1)] =
                        $this->fileHash($file->getPathname());
                }
            }
        }
        ksort($result);

        return $result;
    }

    /** @return non-empty-string */
    private function fileHash(string $path): string
    {
        $hash = hash_file('sha256', $path);
        if (!is_string($hash)) {
            throw new RuntimeException('Cannot hash guidance or Learning evidence: ' . $path);
        }

        return $hash;
    }
}
