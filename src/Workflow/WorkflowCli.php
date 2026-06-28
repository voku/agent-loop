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
            'start' => (new WorkflowStartCommand($this->sessionRunner, $this->recallRunner))->run($rest),
            'status' => (new WorkflowStatusCommand($this->rootPath))->run($rest),
            'close' => (new WorkflowCloseCommand($this->rootPath, $this->sessionRunner, $this->verifyRunner))->run($rest),
            default => $this->unknown($command),
        };
    }

    private function printHelp(): int
    {
        echo <<<'TXT'
Usage:
  agent-loop workflow help
  agent-loop workflow start <task-id> --by <actor> --learning-root <path> --file <path> [--file <path> ...] [--base-commit <sha>]
  agent-loop workflow status <task-id>
  agent-loop workflow close <task-id> --status done [--accept-risk <reason>]

Commands:
  help      Show workflow help.
  start     Start a task workflow by creating a session and compiling recall artifacts.
  status    Show read-only workflow status for a task.
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
