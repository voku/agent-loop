<?php

declare(strict_types=1);

namespace voku\AgentLoop\Review;

use RuntimeException;

final class CodeReviewPromptBuilder
{
    private const int MAX_ARTIFACT_BYTES = 5000;

    public function __construct(private readonly string $rootPath)
    {
    }

    public function build(string $taskId): string
    {
        if (!BlindSpotReviewer::isValidTaskId($taskId)) {
            throw new RuntimeException('Invalid task id.');
        }

        $artifacts = $this->collectArtifacts($taskId);
        $lines = [
            '# L2 code review prompt for ' . $taskId,
            '',
            'You are the task code reviewer for this repository. Anchor everything in the artifacts below. Do not invent history, intent, commands, or memories.',
            'Tone: direct, unsympathetic, slightly ironic, never mean. If the evidence is thin, say that plainly instead of performing confidence.',
            '',
            '## Primary concern',
            '',
            'Expose purpose mismatch: code that does not reflect the real domain goal, data contracts, invariants, edge cases, or workflow purpose.',
            '',
            '## Operating rules',
            '',
            '- Confirm purpose, inputs, outputs, data shapes, invariants, and error handling.',
            '- Call out missing types, mixed shapes, global state, hidden side effects, silent failures, and magic behavior.',
            '- Prefer minimal, purpose-aligned refactors over decorative abstraction.',
            '- Demand tests for edge cases, security-sensitive paths, and regression risks.',
            '- OWASP-aware by default; no silent normalization of unsafe inputs.',
            '- Do not approve code. Do not claim you ran commands. This is a prompt for receiving LLM review plus human judgment.',
            '',
            '## Report format',
            '',
            '1. Understand (The Core Loop - Expose the Pattern): Name the repeated weakness plainly.',
            '2. Explore (Future vs. Now - The Cost of Convenience): Explain maintainability, testability, correctness, and future-change cost.',
            '3. Attempt (Find the Rotting Core): Pick one area most likely to explode; propose one uncomfortable test or change.',
            '4. Inspect (Challenge the Delusion): Identify the trusted belief/process/result that is flawed.',
            '5. Evolve (Force the Next Level): Demand one specific purpose-first ritual before close.',
            '',
            '## Code and workflow artifacts',
            '',
        ];

        if ($artifacts === []) {
            $lines[] = '_No code or workflow artifacts were found for this task._';
        } else {
            foreach ($artifacts as $path => $content) {
                $lines[] = '### ' . $path;
                $lines[] = '';
                $lines[] = '```text';
                $lines[] = $content;
                $lines[] = '```';
                $lines[] = '';
            }
        }

        return rtrim(implode("\n", $lines)) . "\n";
    }

    /**
     * @return array<string, string>
     */
    private function collectArtifacts(string $taskId): array
    {
        $artifacts = [];

        foreach ($this->workflowPaths($taskId) as $relative) {
            $this->addFileArtifact($artifacts, $relative);
        }

        foreach ($this->taskFilesFromRecall($taskId) as $relative) {
            $this->addFileArtifact($artifacts, $relative);
        }

        ksort($artifacts);

        return $artifacts;
    }

    /**
     * @return list<string>
     */
    private function workflowPaths(string $taskId): array
    {
        return [
            'tasks/' . $taskId . '.md',
            'todo/cards/' . $taskId . '.md',
            'todo/jira/' . $taskId . '.md',
            'recall/' . $taskId . '/meta.json',
            'recall/' . $taskId . '/validation-plan.md',
            '.agent-loop/reviews/' . $taskId . '.blindspots.md',
            '.agent-loop/reviews/' . $taskId . '.blindspots.json',
        ];
    }

    /**
     * @return list<string>
     */
    private function taskFilesFromRecall(string $taskId): array
    {
        $metaPath = $this->path('recall/' . $taskId . '/meta.json');
        if (!is_file($metaPath) || !is_readable($metaPath)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($metaPath), true);
        if (!is_array($decoded) || !isset($decoded['task_files']) || !is_array($decoded['task_files'])) {
            return [];
        }

        $files = [];
        foreach ($decoded['task_files'] as $file) {
            if (!is_string($file) || !$this->isSafeRelativePath($file)) {
                continue;
            }
            $files[] = $file;
        }

        sort($files);

        return $files;
    }

    /**
     * @param array<string, string> $artifacts
     */
    private function addFileArtifact(array &$artifacts, string $relative): void
    {
        if (!$this->isSafeRelativePath($relative)) {
            return;
        }

        $path = $this->path($relative);
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $content = file_get_contents($path, false, null, 0, self::MAX_ARTIFACT_BYTES + 1);
        if ($content === false) {
            return;
        }

        $artifacts[$relative] = $this->truncate($content);
    }

    private function isSafeRelativePath(string $relative): bool
    {
        return $relative !== ''
            && !str_starts_with($relative, '/')
            && !str_contains($relative, '\\')
            && !str_contains($relative, '..');
    }

    private function truncate(string $content): string
    {
        if (strlen($content) <= self::MAX_ARTIFACT_BYTES) {
            return rtrim($content);
        }

        return rtrim(substr($content, 0, self::MAX_ARTIFACT_BYTES)) . "\n[truncated]";
    }

    private function path(string $relative): string
    {
        return rtrim($this->rootPath, '/') . '/' . $relative;
    }
}
