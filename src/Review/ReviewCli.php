<?php

declare(strict_types=1);

namespace voku\AgentLoop\Review;

use RuntimeException;

final class ReviewCli
{
    public function __construct(
        private readonly string $rootPath,
    ) {
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';

        if (in_array($command, ['help', '--help', '-h', ''], true)) {
            echo $this->usage();
            return 0;
        }

        if ($command === 'code') {
            return $this->runCode($argv[2] ?? '');
        }

        if ($command !== 'blindspots') {
            fwrite(\STDERR, "Unknown review command: {$command}\n\n");
            fwrite(\STDERR, $this->usage());
            return 1;
        }

        $taskId = $argv[2] ?? '';
        if (!BlindSpotReviewer::isValidTaskId($taskId)) {
            fwrite(\STDERR, "[ERROR] Invalid or missing task id. Use only letters, numbers, dots, underscores, and hyphens.\n");
            return 1;
        }

        try {
            $report = (new BlindSpotReviewer($this->rootPath))->review($taskId);
            (new ReviewReportWriter($this->rootPath))->write($report);
        } catch (RuntimeException $exception) {
            fwrite(\STDERR, '[ERROR] ' . $exception->getMessage() . "\n");
            return 1;
        }

        $basePath = '.agent-loop/reviews/' . $taskId . '.blindspots';
        echo 'Review blindspots for ' . $taskId . ': ' . $report->status() . "\n";
        echo 'Markdown report: ' . $basePath . ".md\n";
        echo 'JSON report: ' . $basePath . ".json\n";
        echo 'L2 prompt: ' . $basePath . ".prompt.md\n";
        echo 'Findings: ' . count($report->findings) . "\n";

        return $report->status() === 'fail' ? 1 : 0;
    }

    private function runCode(string $taskId): int
    {
        if (!BlindSpotReviewer::isValidTaskId($taskId)) {
            fwrite(\STDERR, "[ERROR] Invalid or missing task id. Use only letters, numbers, dots, underscores, and hyphens.\n");
            return 1;
        }

        try {
            $directory = rtrim($this->rootPath, '/') . '/.agent-loop/reviews';
            if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
                throw new RuntimeException("Unable to create review directory: {$directory}");
            }
            $promptPath = $directory . '/' . $taskId . '.code.prompt.md';
            $prompt = (new CodeReviewPromptBuilder($this->rootPath))->build($taskId);
            if (file_put_contents($promptPath, $prompt) === false) {
                throw new RuntimeException("Unable to write review code L2 prompt: {$promptPath}");
            }
        } catch (RuntimeException $exception) {
            fwrite(\STDERR, '[ERROR] ' . $exception->getMessage() . "\n");
            return 1;
        }

        echo 'Review code prompt for ' . $taskId . ": .agent-loop/reviews/" . $taskId . ".code.prompt.md\n";

        return 0;
    }

    private function usage(): string
    {
        return <<<'TXT'
agent-loop review - deterministic review helpers.

Usage:
  agent-loop review help
  agent-loop review blindspots <task-id>
  agent-loop review code <task-id>

Commands:
  help                  Show review help.
  blindspots <task-id>  Review deterministic blind spots for a task.
  code <task-id>        Generate an L2 code-review prompt for a task.

TXT;
    }
}
