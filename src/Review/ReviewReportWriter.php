<?php

declare(strict_types=1);

namespace voku\AgentLoop\Review;

use RuntimeException;

final class ReviewReportWriter
{
    public function __construct(
        private readonly string $rootPath,
    ) {
    }

    public function write(ReviewReport $report): void
    {
        if (!BlindSpotReviewer::isValidTaskId($report->taskId)) {
            throw new RuntimeException('Invalid task id.');
        }

        $directory = rtrim($this->rootPath, '/') . '/.agent-loop/reviews';
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create review directory: {$directory}");
        }

        $json = json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Unable to encode review report JSON: ' . json_last_error_msg());
        }

        $jsonPath = $directory . '/' . $report->taskId . '.blindspots.json';
        $markdownPath = $directory . '/' . $report->taskId . '.blindspots.md';
        $promptPath = $directory . '/' . $report->taskId . '.blindspots.prompt.md';

        if (file_put_contents($jsonPath, $json . "\n") === false) {
            throw new RuntimeException("Unable to write review JSON report: {$jsonPath}");
        }

        if (file_put_contents($markdownPath, $this->toMarkdown($report)) === false) {
            throw new RuntimeException("Unable to write review Markdown report: {$markdownPath}");
        }

        $prompt = (new BlindSpotPromptBuilder($this->rootPath))->build($report);
        if (file_put_contents($promptPath, $prompt) === false) {
            throw new RuntimeException("Unable to write review L2 prompt: {$promptPath}");
        }
    }

    private function toMarkdown(ReviewReport $report): string
    {
        $lines = [
            '# Blind-spot review for ' . $report->taskId,
            '',
            'Status: ' . $report->status(),
            '',
            '## Findings',
            '',
        ];

        if ($report->findings === []) {
            $lines[] = '- [OK] no_findings: No deterministic blind spots were found.';
        }

        foreach ($report->findings as $finding) {
            $lines[] = '- [' . $finding->severity->value . '] ' . $finding->id . ': ' . $finding->message;
            foreach ($finding->evidence as $evidence) {
                $lines[] = '  - ' . $evidence;
            }
        }

        return implode("\n", $lines) . "\n";
    }
}
