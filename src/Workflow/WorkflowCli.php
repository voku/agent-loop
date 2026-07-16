<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

final readonly class WorkflowCli
{
    /** @param callable(list<string>): int $sessionRunner @param callable(list<string>): int $recallRunner @param callable(list<string>): int $verifyRunner */
    public function __construct(
        private string $rootPath,
        private mixed $sessionRunner,
        private mixed $recallRunner,
        private mixed $verifyRunner,
    ) {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $command = $args[0] ?? 'help';
        $rest = array_slice($args, 1);

        return match ($command) {
            'help', '--help', '-h', '' => $this->printHelp(),
            'plan' => (new WorkflowPlanCommand($this->rootPath, $this->sessionRunner, $this->recallRunner))->run($rest),
            'approve' => (new WorkflowApproveCommand($this->sessionRunner))->run($rest),
            'start' => (new WorkflowStartCommand($this->rootPath, $this->sessionRunner, $this->recallRunner))->run($rest),
            'status' => (new WorkflowStatusCommand($this->rootPath))->run($rest),
            'context' => (new WorkflowContextCommand($this->rootPath))->run($rest),
            'report' => (new WorkflowReportCommand($this->rootPath))->run($rest),
            'close' => (new WorkflowCloseCommand($this->rootPath, $this->sessionRunner, $this->verifyRunner))->run($rest),
            default => $this->unknown($command),
        };
    }

    private function printHelp(): int
    {
        echo <<<'TXT'
Usage:
  agent-loop workflow help
  agent-loop workflow plan <task-id> --by <actor> [--learning-root <path>] --file <path> [--file <path> ...] --goal <text> [--scope <path> ...] [--non-goal <text> ...] --validation <command> [--validation <command> ...] [--base-commit <sha>]
  agent-loop workflow approve <task-id> --by <actor>
  agent-loop workflow start <task-id> --by <actor> [--learning-root <path>] --file <path> [--file <path> ...] [--base-commit <sha>]
  agent-loop workflow status <task-id>
  agent-loop workflow context <task-id> [--max-lines N] [--max-bytes N] [--format text|json] [--learning-root <path>]
  agent-loop workflow report <task-id> [--format text|json] [--learning-root <path>] [--changed-file <path> ...]
  agent-loop workflow close <task-id> --status done [--accept-risk <reason>]

Commands:
  help      Show workflow help.
  plan      Start a session, compile recall, and create a candidate work brief.
  approve   Approve the current candidate work brief for a task.
  start     Start a task workflow by creating a session and compiling recall artifacts.
  status    Show read-only workflow status for a task.
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
