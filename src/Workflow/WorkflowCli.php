<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use Throwable;
use voku\AgentLoop\Run\RunManifestTransitionWriter;

final readonly class WorkflowCli
{
    /** @param callable(list<string>): int $recallRunner */
    public function __construct(
        private string $rootPath,
        private mixed $recallRunner,
    ) {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $command = $args[0] ?? 'help';
        $rest = array_slice($args, 1);

        return match ($command) {
            'help', '--help', '-h', '' => $this->printHelp(),
            'plan' => (new WorkflowPlanCommand($this->rootPath))->run($rest),
            'approve' => (new WorkflowApproveCommand($this->rootPath, $this->recallRunner))->run($rest),
            'start' => (new WorkflowStartCommand($this->rootPath, $this->recallRunner))->run($rest),
            'status' => (new WorkflowStatusCommand($this->rootPath))->run($rest),
            'manifest' => (new WorkflowManifestCommand($this->rootPath))->run($rest),
            'context' => (new WorkflowContextCommand($this->rootPath))->run($rest),
            'report' => (new WorkflowReportCommand($this->rootPath))->run($rest),
            'close' => $this->runClose($rest),
            default => $this->unknown($command),
        };
    }

    /** @param list<string> $args */
    private function runClose(array $args): int
    {
        $exit = (new WorkflowCloseCommand($this->rootPath))->run($args);
        if ($exit !== 0) {
            return $exit;
        }

        try {
            $taskId = new WorkflowTaskId($args[0] ?? '');
            $manifestPath = (new RunManifestTransitionWriter($this->rootPath))->write($taskId->value);
            echo "[OK] workflow close: final run manifest refreshed at {$manifestPath}\n";

            return 0;
        } catch (Throwable $exception) {
            fwrite(
                STDERR,
                '[FAIL] workflow close: session was closed, but final run-manifest refresh failed: '
                . $exception->getMessage()
                . "\n[ACTION REQUIRED] Run agent-loop workflow manifest <task-id> --write after repairing the projection error.\n",
            );

            return 1;
        }
    }

    private function printHelp(): int
    {
        echo <<<'TXT'
Usage:
  agent-loop workflow help
  agent-loop workflow plan <task-id> --by <actor> [--learning-root <path>] --file <path> [--file <path> ...] --goal <text> [--scope <path> ...] [--non-goal <text> ...] --validation <command> [--validation <command> ...] [--tag <label> ...] [--behavior-anchor <text> ...] [--base-commit <sha>] [--ephemeral]
  agent-loop workflow approve <task-id> --by <actor> [--learning-root <path>]
  agent-loop workflow start <task-id> --by <actor> [--learning-root <path>] --file <path> [--file <path> ...] [--base-commit <sha>]
  agent-loop workflow status <task-id> [--format text|json]
  agent-loop workflow manifest <task-id> [--write] [--format text|json]
  agent-loop workflow context <task-id> [--max-lines N] [--max-bytes N] [--format text|json] [--learning-root <path>]
  agent-loop workflow report <task-id> [--format text|json] [--learning-root <path>] [--changed-file <path> ...]
  agent-loop workflow close <task-id> --status done [--accept-risk <reason> --accept-risk-by <name>]

Commands:
  help      Show workflow help.
  plan      Start a session and create a candidate work brief.
  approve   Approve the brief, then compile recall from that sealed context.
  start     Start a task workflow by creating a session and compiling recall artifacts.
  status    Show the read-only cross-package run projection and one next action.
  manifest  Inspect or atomically persist the cross-package run projection.
  context   Render a bounded, read-only task context from existing artifacts.
  report    Show a read-only, auditable completion report for a task.
  close     Close a task through workflow safety gates.

TXT;
        return 0;
    }

    private function unknown(string $command): int
    {
        fwrite(\STDERR, "Unknown workflow command: {$command}\n\n");
        $this->printHelp();

        return 1;
    }
}
