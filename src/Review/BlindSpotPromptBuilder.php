<?php

declare(strict_types=1);

namespace voku\AgentLoop\Review;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class BlindSpotPromptBuilder
{
    private const int MAX_ARTIFACT_BYTES = 3000;

    public function __construct(private readonly string $rootPath)
    {
    }

    public function build(ReviewReport $report): string
    {
        if (!BlindSpotReviewer::isValidTaskId($report->taskId)) {
            throw new RuntimeException('Invalid task id.');
        }

        $artifacts = $this->collectArtifacts($report->taskId);
        $lines = [
            '# L2 blind-spot analysis prompt for ' . $report->taskId,
            '',
            'You are the task blind-spot reviewer for this repository. Use the workflow artifacts below as your only source of truth.',
            'Be direct, concrete, and uncomfortable when the evidence supports it, but do not invent facts that are not in the artifacts.',
            '',
            '## Focus',
            '',
            '- Task id: ' . $report->taskId,
            '- Review report status before LLM analysis: ' . $report->status(),
            '- Goal: identify hidden workflow, validation, scope, security, review, and handoff blind spots before a human closes the task.',
            '',
            '## Deterministic preflight findings',
            '',
        ];

        if ($report->findings === []) {
            $lines[] = '- [OK] no deterministic preflight findings.';
        } else {
            foreach ($report->findings as $finding) {
                $lines[] = '- [' . $finding->severity->value . '] ' . $finding->id . ': ' . $finding->message;
                foreach ($finding->evidence as $evidence) {
                    $lines[] = '  - ' . $evidence;
                }
            }
        }

        $lines = array_merge($lines, [
            '',
            '## Analysis phases',
            '',
            '1. Understand: What workflow pattern is most likely to be fooling the implementer?',
            '2. Explore: What future failure or maintenance cost is being traded for short-term convenience?',
            '3. Attempt: What single check, test, or artifact would expose the most buried dysfunction?',
            '4. Inspect: What claim of correctness, coverage, or readiness is least supported by the artifacts?',
            '5. Evolve: What concrete uncomfortable next ritual should be required before task close?',
            '',
            '## Output contract',
            '',
            'Return Markdown with exactly these headings: Summary, Critical blind spots, Evidence, Required next action, Close readiness.',
            'Mark close readiness as one of: BLOCKED, NEEDS HUMAN REVIEW, READY FOR HUMAN CLOSE.',
            'Do not approve code. Do not approve durable learning. Do not claim you ran commands.',
            '',
            '## Workflow artifacts',
            '',
        ]);

        if ($artifacts === []) {
            $lines[] = '_No workflow artifacts were found for this task._';
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

        foreach ($this->candidatePaths($taskId) as $relative) {
            $this->addFileArtifact($artifacts, $relative);
        }

        foreach ($this->relatedSessionFiles($taskId) as $relative) {
            $this->addFileArtifact($artifacts, $relative);
        }

        $this->addMatchingLineArtifact($artifacts, 'TODO.md', $taskId);
        $this->addMatchingLineArtifact($artifacts, 'todo/board.md', $taskId);

        ksort($artifacts);

        return $artifacts;
    }

    /**
     * @return list<string>
     */
    private function candidatePaths(string $taskId): array
    {
        return [
            'tasks/' . $taskId . '.md',
            'todo/cards/' . $taskId . '.md',
            'todo/jira/' . $taskId . '.md',
            'recall/' . $taskId . '/meta.json',
            'recall/' . $taskId . '/system.md',
            'recall/' . $taskId . '/validation-plan.md',
        ];
    }

    /**
     * @return list<string>
     */
    private function relatedSessionFiles(string $taskId): array
    {
        $root = $this->path('session_plan');
        if (!is_dir($root)) {
            return [];
        }

        /** @var array<string, array{related: bool, files: list<string>}> $sessionGroups */
        $sessionGroups = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile() || !$item->isReadable() || !$this->looksTextFile($item)) {
                continue;
            }

            $path = $item->getPathname();
            $content = file_get_contents($path);
            if ($content === false) {
                continue;
            }

            $relative = $this->relativePath($path);
            $groupKey = $this->sessionGroupKey($relative);
            $sessionGroups[$groupKey] ??= ['related' => false, 'files' => []];
            $sessionGroups[$groupKey]['files'][] = $relative;
            if (str_contains($relative, $taskId) || str_contains($content, $taskId)) {
                $sessionGroups[$groupKey]['related'] = true;
            }
        }

        $files = [];
        foreach ($sessionGroups as $group) {
            if (!$group['related']) {
                continue;
            }
            array_push($files, ...$group['files']);
        }
        sort($files);

        return $files;
    }

    /**
     * @param array<string, string> $artifacts
     */
    private function addFileArtifact(array &$artifacts, string $relative): void
    {
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

    /**
     * @param array<string, string> $artifacts
     */
    private function addMatchingLineArtifact(array &$artifacts, string $relative, string $taskId): void
    {
        $path = $this->path($relative);
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return;
        }

        $matches = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            if (str_contains($line, $taskId)) {
                $matches[] = $line;
            }
        }

        if ($matches !== []) {
            $artifacts[$relative . ' (matching lines)'] = $this->truncate(implode("\n", $matches));
        }
    }

    private function truncate(string $content): string
    {
        if (strlen($content) <= self::MAX_ARTIFACT_BYTES) {
            return rtrim($content);
        }

        return rtrim(substr($content, 0, self::MAX_ARTIFACT_BYTES)) . "\n[truncated]";
    }

    private function looksTextFile(SplFileInfo $file): bool
    {
        $extension = strtolower($file->getExtension());

        return in_array($extension, ['md', 'txt', 'json', 'log', ''], true);
    }

    private function sessionGroupKey(string $relative): string
    {
        $prefix = 'session_plan/';
        $withoutRoot = str_starts_with($relative, $prefix) ? substr($relative, strlen($prefix)) : $relative;
        $separator = strpos($withoutRoot, '/');

        return $separator === false ? $withoutRoot : substr($withoutRoot, 0, $separator);
    }

    private function path(string $relative): string
    {
        return rtrim($this->rootPath, '/') . '/' . $relative;
    }

    private function relativePath(string $path): string
    {
        $root = rtrim($this->rootPath, '/') . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}
