<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use Closure;

final readonly class WorkflowCli
{
    private string $rootPath;

    private Closure $recallRunner;

    /** @param callable(list<string>): int $recallRunner */
    public function __construct(string $rootPath, callable $recallRunner)
    {
        $this->rootPath = $rootPath;
        $this->recallRunner = Closure::fromCallable($recallRunner);
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
            'execution-profile' => (new WorkflowExecutionProfileCommand($this->rootPath))->run($rest),
            'attention' => (new WorkflowAttentionCommand($this->rootPath))->run($rest),
            'contract' => (new WorkflowContractCommand($this->rootPath))->run($rest),
            'status' => (new WorkflowStatusCommand($this->rootPath))->run($rest),
            'manifest' => (new WorkflowManifestCommand($this->rootPath))->run($rest),
            'context' => (new WorkflowContextCommand($this->rootPath))->run($rest),
            'report' => (new WorkflowReportCommand($this->rootPath))->run($rest),
            'review' => (new WorkflowHumanReviewCommand($this->rootPath))->run($rest),
            'reflect' => (new WorkflowReflectCommand($this->rootPath, $this->recallRunner))->run($rest),
            'handoff' => (new WorkflowHandoffCommand($this->rootPath, $this->recallRunner))->run($rest),
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
  agent-loop workflow execution-profile <task-id> [--profile manual|surgical|standard|hardened --by <actor>]
  agent-loop workflow attention <task-id> --resolve <attention-id> --by <actor>
  agent-loop workflow contract <task-id> --status ready --from <l1.md> --by <actor>
  agent-loop workflow contract <task-id> --status blocked|rejected --reason <text> --evidence <text> [--evidence <text> ...] --minimum-change <text> [--affected-constraint <text>] --by <actor>
  agent-loop workflow status <task-id> [--format text|json|toon] [--expect blocked|experiment|incomplete|ready_to_close|complete]
  agent-loop workflow manifest <task-id> [--write] [--format text|json]
  agent-loop workflow context <task-id> [--max-lines N] [--max-bytes N] [--format text|json]
  agent-loop workflow report <task-id> [--format text|json] [--changed-file <path> ...]
  agent-loop workflow review <task-id>
  agent-loop workflow reflect <task-id> [--scope project|task]
  agent-loop workflow handoff <task-id> (--context <text> | --context-file <path>)
  agent-loop workflow learn <task-id> --status findings_recorded|no_durable_learning|follow_up_required --by <actor> --reason <text> [--finding <id> ...] [--follow-up <ref>]
  agent-loop workflow close <task-id> --status done [--accept-risk <reason> --accept-risk-by <name>]

Commands:
  plan               Create or revise a durable candidate Contract, including explicit required acceptance outcomes when supplied. PLAN creates no Session and no Run.
  approve            Approve the exact Contract revision only; deterministic Run/Session/Recall preparation belongs to `agent-loop enter`.
  execution-profile  Select the explicit execution topology for an approved Contract before its Run exists; absent selection means manual.
  attention          Resolve pending human-owned execution Attention through an explicit actor-owned workflow transition; runner-facing APIs cannot manufacture this authority.
  contract           Persist the project-specific L1 execution contract, or an explicit BLOCKED/REJECTED result.
  status             Show the read-only cross-package Run projection and one next action; --expect makes an exact state CI-assertable.
  manifest           Inspect or atomically persist the cross-package Run projection.
  context            Render bounded read-only context from the durable Contract and current owner artifacts.
  report             Show an auditable task/Run completion report.
  review             Write a self-contained human review workbench from the existing audit projection. The HTML is non-authoritative and cannot acknowledge a review.
  reflect            Emit a context-light project/task future-work prompt only after the task is review-ready or complete; never mutates workflow state.
  handoff            Compile a self-contained TODO/card handoff prompt from explicit bounded current-session notes plus the governed Session identity, durable Contract evidence, and current board-card projection when available. The acting agent still updates the existing task owner.
  learn              Record the durable Run Learning close-out through agent-learning.
  close              Close the governed Run through safety gates and preserve durable close evidence.

Built-in L1 control prompts:
  Source checkout manifest: `resources/operating-prompts.json`.
  Composer consumer manifest: `vendor/voku/agent-loop/resources/operating-prompts.json`.
  `checkpoint-autonomy` requires `{"anchor_point":"..."}` and self-checks bounded steps without fabricating human approval.
  `momentum` reuses still-valid fresh context while revalidating authority/freshness instead of restarting discovery.
  Select either or both through the normal `--operating-prompt-manifest` + `--operating-prompt` Contract policy.
  They are context-independent L1 controls and do not create an L2 execution-contract construction pass.

Governed flow:
  PLAN -> APPROVE -> EXECUTION PROFILE (optional; default manual) -> ENTER/PREPARE -> CONTRACT -> IMPLEMENT -> VALIDATE -> REVIEW -> LEARN -> CLOSE

Ownership:
  Contract/approval, execution topology, Attention resolution, and Run lifecycle are durable agent-loop state.
  Session is pruneable working memory and raw run-local observations; the current governed Run supplies the exact Session identity used by handoff.
  Recall owns deterministic briefing/verification-plan artifacts and the bundled `todo-card-handoff` L2 recipe.
  agent-learning owns durable Learning close-out and guidance evolution.
  The repository's task/board owner owns durable handoff text; `workflow handoff` only compiles the prompt used to update it.

`workflow review` is a disposable human-facing projection over existing Contract, validation, blind-spot and implementation evidence. It may use Git to orient the reviewer when the approved Contract has a base commit, but Git/browser state never becomes lifecycle authority.

`workflow handoff` intentionally does not persist or copy an opaque chat transcript. The acting host supplies a bounded summary of the useful current-session context through `--context` or `--context-file`; Recall tells the next agent to re-ground material claims before persisting them.

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
