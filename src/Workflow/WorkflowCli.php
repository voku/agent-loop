<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

final readonly class WorkflowCli
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $command = $args[0] ?? 'help';
        $rest = array_slice($args, 1);

        return match ($command) {
            'help', '--help', '-h', '' => $this->printHelp(),
            'plan' => (new WorkflowPlanCommand($this->rootPath))->run($rest),
            'approve' => (new WorkflowApproveCommand($this->rootPath))->run($rest),
            'contract' => (new WorkflowContractCommand($this->rootPath))->run($rest),
            'status' => (new WorkflowStatusCommand($this->rootPath))->run($rest),
            'manifest' => (new WorkflowManifestCommand($this->rootPath))->run($rest),
            'context' => (new WorkflowContextCommand($this->rootPath))->run($rest),
            'report' => (new WorkflowReportCommand($this->rootPath))->run($rest),
            'reflect' => (new WorkflowReflectCommand($this->rootPath))->run($rest),
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
  agent-loop workflow plan <task-id> --by <actor> --file <path> [--file <path> ...] --goal <text> [--scope <path> ...] [--non-goal <text> ...] [--acceptance <text> ...] --validation <command> [--validation <command> ...] [--tag <label> ...] [--behavior-anchor <text> ...] [--operating-prompt-manifest <path> --operating-prompt <json> ...] [--base-commit <sha>]
  agent-loop workflow approve <task-id> --by <actor>
  agent-loop workflow contract <task-id> --status ready --from <l1.md> --by <actor>
  agent-loop workflow contract <task-id> --status blocked|rejected --reason <text> --evidence <text> [--evidence <text> ...] --minimum-change <text> [--affected-constraint <text>] --by <actor>
  agent-loop workflow status <task-id> [--format text|json|toon] [--expect blocked|experiment|incomplete|ready_to_close|complete]
  agent-loop workflow manifest <task-id> [--write] [--format text|json]
  agent-loop workflow context <task-id> [--max-lines N] [--max-bytes N] [--format text|json]
  agent-loop workflow report <task-id> [--format text|json] [--changed-file <path> ...]
  agent-loop workflow reflect <task-id> [--scope project|task]
  agent-loop workflow learn <task-id> --status findings_recorded|no_durable_learning|follow_up_required --by <actor> --reason <text> [--finding <id> ...] [--follow-up <ref>]
  agent-loop workflow close <task-id> --status done [--accept-risk <reason> --accept-risk-by <name>]

Normal governed lifecycle:
  agent-loop workflow plan <task-id> ...
  agent-loop workflow approve <task-id> --by <actor>
  agent-loop enter <task-id>
  <host-native implementation>
  agent-loop finish <task-id>

`enter` owns deterministic Run / Session / Recall preparation after approval.
`finish` owns deterministic validation, review preparation, evidence binding and ordinary close.
When human or agent judgment is required, `finish` returns the exact next authority-bearing input instead of fabricating it.

Specialist / recovery commands:
  contract  Persist an explicit L1 execution decision when selected policy requires one.
  status    Inspect canonical lifecycle policy and its one next action.
  manifest  Inspect or atomically persist the cross-package Run projection.
  context   Render bounded read-only context from durable Contract and current owner artifacts.
  report    Render an auditable completion report.
  reflect   Emit the Recall-owned future-work prompt after review readiness or completion.
  learn     Record Learning directly for advanced/manual recovery; ordinary close-out uses `finish`.
  close     Perform specialist/manual close or explicit accepted-risk recovery; ordinary close-out uses `finish`.

Built-in L1 control prompts:
  Source checkout manifest: `resources/operating-prompts.json`.
  Composer consumer manifest: `vendor/voku/agent-loop/resources/operating-prompts.json`.
  `checkpoint-autonomy` requires `{"anchor_point":"..."}` and self-checks bounded steps without fabricating human approval.
  `momentum` reuses still-valid fresh context while revalidating authority/freshness instead of restarting discovery.
  Select either or both through the normal `--operating-prompt-manifest` + `--operating-prompt` Contract policy.
  They are context-independent L1 controls and do not create an L2 execution-contract construction pass.

Ownership:
  Contract approval is authority-bearing durable agent-loop state.
  Run lifecycle policy is durable agent-loop state; deterministic lifecycle preparation/reconciliation belongs to `enter` / `finish`.
  Session is pruneable working memory and raw run-local observations.
  Recall owns deterministic briefing, review and reflection semantics.
  agent-learning owns durable Findings and Run Learning close-out.

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
