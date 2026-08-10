<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

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
            'contract' => (new WorkflowContractCommand($this->rootPath))->run($rest),
            'status' => (new WorkflowStatusCommand($this->rootPath))->run($rest),
            'manifest' => (new WorkflowManifestCommand($this->rootPath))->run($rest),
            'context' => (new WorkflowContextCommand($this->rootPath))->run($rest),
            'report' => (new WorkflowReportCommand($this->rootPath))->run($rest),
            'learn' => (new WorkflowLearningCommand($this->rootPath))->run($rest),
            'close' => (new WorkflowCloseCommand($this->rootPath))->run($rest),
            default => $this->unknown($command),
        };
    }

    private function printHelp(): int
    {
        echo <<<'TXT'
Usage:
  agent-loop workflow help
  agent-loop workflow plan <task-id> --by <actor> [--learning-root <path>] --file <path> [--file <path> ...] --goal <text> [--scope <path> ...] [--non-goal <text> ...] --validation <command> [--validation <command> ...] [--tag <label> ...] [--behavior-anchor <text> ...] [--operating-prompt-manifest <path> --operating-prompt <json> ...] [--base-commit <sha>]
  agent-loop workflow approve <task-id> --by <actor> [--learning-root <path>]
  agent-loop workflow contract <task-id> --status ready --from <l1.md> --by <actor>
  agent-loop workflow contract <task-id> --status blocked|rejected --reason <text> --evidence <text> [--evidence <text> ...] --minimum-change <text> [--affected-constraint <text>] --by <actor>
  agent-loop workflow status <task-id> [--format text|json]
  agent-loop workflow manifest <task-id> [--write] [--format text|json]
  agent-loop workflow context <task-id> [--max-lines N] [--max-bytes N] [--format text|json] [--learning-root <path>]
  agent-loop workflow report <task-id> [--format text|json] [--learning-root <path>] [--changed-file <path> ...]
  agent-loop workflow learn <task-id> --status findings_recorded|no_durable_learning|follow_up_required --by <actor> --reason <text> [--finding <id> ...] [--follow-up <ref>] [--learning-root <path>]
  agent-loop workflow close <task-id> --status done [--accept-risk <reason> --accept-risk-by <name>] [--learning-root <path>]

Commands:
  plan      Create or revise a durable candidate Contract. PLAN creates no Session and no Run.
  approve   Approve the exact Contract revision, prepare/resume its governed Run and working Session, then compile Run-bound Recall.
  contract  Persist the project-specific L1 execution contract, or an explicit BLOCKED/REJECTED result.
  status    Show the read-only cross-package Run projection and one next action.
  manifest  Inspect or atomically persist the cross-package Run projection.
  context   Render bounded read-only context from the durable Contract and current owner artifacts.
  report    Show an auditable task/Run completion report.
  learn     Record the durable Run Learning close-out through agent-learning.
  close     Close the governed Run through safety gates and preserve durable close evidence.

Governed flow:
  PLAN -> APPROVE/PREPARE -> CONTEXT -> CONTRACT -> IMPLEMENT -> VALIDATE -> REVIEW -> LEARN -> VERIFY -> CLOSE

Ownership:
  Contract/approval and Run lifecycle are durable agent-loop state.
  Session is pruneable working memory and raw run-local observations.
  Recall owns deterministic briefing/verification-plan artifacts.
  agent-learning owns durable Learning close-out and guidance evolution.

For ungoverned experiments use `agent-loop session start --ephemeral`; there is no workflow shortcut that can masquerade as governed work.

TXT;
        return 0;
    }

    private function unknown(string $command): int
    {
        fwrite(STDERR, "Unknown workflow command: {$command}\n\n");
        $this->printHelp();

        return 1;
    }
}
