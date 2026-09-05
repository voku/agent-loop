<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use voku\AgentLoop\Cli\OptionTokens;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Execution\ExecutionPlanStore;
use voku\AgentLoop\Execution\ExecutionProfileName;
use voku\AgentLoop\Execution\ExecutionProfileSelectionStore;
use voku\AgentLoop\Execution\ExecutionProjection;
use voku\AgentLoop\Execution\ExecutionStageKind;
use voku\AgentLoop\Execution\StageExecutionBundle;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoop\Execution\StageResult;
use voku\AgentLoop\Run\GovernedRun;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunPolicyEvaluation;

/**
 * Turnkey multi-stage pipeline runner for governed execution profiles.
 *
 * Automates progression through multi-stage profiles (surgical, standard, hardened)
 * with role-based briefings, review feedback loops, and deterministic verification.
 */
final readonly class WorkflowPipelineCommand
{
    public function __construct(private string $rootPath)
    {
    }

    /**
     * @param list<string> $args
     */
    public function run(array $args): int
    {
        $format = "text";
        try {
            if ($args === [] || in_array($args[0], ["help", "--help", "-h"], true)) {
                $this->printHelp();

                return 0;
            }

            $subcommand = $args[0];
            $rest = array_slice($args, 1);

            $knownSubcommands = ["status", "stage", "bundle", "run", "submit"];
            if (!in_array($subcommand, $knownSubcommands, true)) {
                // If first arg looks like a task-id, default to "run"
                $taskId = $subcommand;
                $tokens = $rest;
                $format = OptionTokens::value($tokens, "format") ?? "text";

                return $this->pipelineRun($taskId, $tokens, $format);
            }

            if ($rest === [] || in_array($rest[0], ["help", "--help", "-h"], true)) {
                $this->printHelp();

                return 0;
            }

            $taskId = $rest[0];
            $tokens = array_slice($rest, 1);
            $format = OptionTokens::value($tokens, "format") ?? "text";
            if (!in_array($format, ["text", "json"], true)) {
                throw new InvalidArgumentException("--format must be text or json.");
            }

            return match ($subcommand) {
                "status" => $this->status($taskId, $tokens, $format),
                "stage", "bundle" => $this->stage($taskId, $tokens, $format),
                "run" => $this->pipelineRun($taskId, $tokens, $format),
                default => $this->submit($taskId, $tokens, $format),
            };
        } catch (InvalidArgumentException $exception) {
            if ($format === "json") {
                echo json_encode([
                    "schema_version" => "1.0",
                    "command" => "pipeline",
                    "status" => "error",
                    "message" => $exception->getMessage(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            } else {
                fwrite(STDERR, "[FAIL] pipeline: " . $exception->getMessage() . "\n");
            }

            return 1;
        } catch (Throwable $exception) {
            if ($format === "json") {
                echo json_encode([
                    "schema_version" => "1.0",
                    "command" => "pipeline",
                    "status" => "error",
                    "message" => $exception->getMessage(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            } else {
                fwrite(STDERR, "[FAIL] pipeline: " . $exception->getMessage() . "\n");
            }

            return 1;
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function status(string $taskIdString, array $tokens, string $format): int
    {
        $taskId = (new WorkflowTaskId($taskIdString))->value;
        $gateway = new ExecutionGateway($this->rootPath);
        $projection = $gateway->projection($taskId);
        $plan = (new ExecutionPlanStore($this->rootPath))->load($taskId);

        $isComplete = $projection->complete();
        $currentStage = null;
        $currentStageKind = null;
        $currentRole = null;
        $mayMutate = false;

        if ($projection->currentStageId !== null) {
            $stage = $plan->stage($projection->currentStageId);
            $currentStage = $stage->id;
            $currentStageKind = $stage->kind->value;
            $currentRole = $stage->roleId;
            $mayMutate = $stage->mayMutate;
        }

        $handoffs = array_map(
            static fn ($h) => $h->toArray(),
            $projection->handoffs,
        );

        $statusStr = "in_progress";
        $nextActionKind = RunPolicyEvaluation::KIND_HOST_WORK;
        $nextAction = "agent-loop pipeline stage " . $taskId;

        if ($isComplete) {
            $statusStr = "complete";
            $nextActionKind = RunPolicyEvaluation::KIND_COMMAND;
            $nextAction = "agent-loop finish " . $taskId;
        } elseif ($projection->attention !== null) {
            $statusStr = "waiting_for_attention";
            $nextActionKind = RunPolicyEvaluation::KIND_DECISION_REQUIRED;
            $nextAction = sprintf(
                "agent-loop workflow attention %s --resolve %s --by <actor>",
                $taskId,
                $projection->attention->id,
            );
        } elseif ($currentStageKind === ExecutionStageKind::DETERMINISTIC->value) {
            $nextActionKind = RunPolicyEvaluation::KIND_COMMAND;
            $nextAction = "agent-loop pipeline run " . $taskId;
        }

        $payload = [
            "schema_version" => "1.0",
            "command" => "pipeline status",
            "task_id" => $taskId,
            "profile" => $projection->profile->value,
            "status" => $statusStr,
            "complete" => $isComplete,
            "current_stage" => $currentStage,
            "stage_kind" => $currentStageKind,
            "role" => $currentRole,
            "may_mutate" => $mayMutate,
            "attempt" => $projection->currentAttempt,
            "candidate_revision" => $projection->candidateRevision,
            "attention" => $projection->attention?->toArray(),
            "handoffs_count" => count($projection->handoffs),
            "handoffs" => $handoffs,
            "next_action" => $nextAction,
            "next_action_kind" => $nextActionKind,
        ];

        if ($format === "json") {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        } else {
            echo "agent-loop pipeline status " . $taskId . "\n";
            echo "Profile: " . $projection->profile->value . "\n";
            echo "Status: " . $statusStr . "\n";
            echo "Complete: " . ($isComplete ? "yes" : "no") . "\n";
            if ($currentStage !== null) {
                echo "Current stage: " . $currentStage . " (attempt " . $projection->currentAttempt . ")\n";
                echo "Stage role: " . ($currentRole ?? "none") . " (mutation: " . ($mayMutate ? "allowed" : "read-only") . ")\n";
            }
            if ($projection->attention !== null) {
                echo "[ATTENTION] " . $projection->attention->message . " (ID: " . $projection->attention->id . ")\n";
            }
            echo "Next: " . $nextAction . "\n";
        }

        return 0;
    }

    /**
     * @param list<string> $tokens
     */
    private function stage(string $taskIdString, array $tokens, string $format): int
    {
        $taskId = (new WorkflowTaskId($taskIdString))->value;
        $gateway = new ExecutionGateway($this->rootPath);
        $projection = $gateway->projection($taskId);

        if ($projection->complete()) {
            if ($format === "json") {
                echo json_encode([
                    "schema_version" => "1.0",
                    "command" => "pipeline stage",
                    "task_id" => $taskId,
                    "status" => "complete",
                    "message" => "Execution pipeline is already complete.",
                    "next_action" => "agent-loop finish " . $taskId,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            } else {
                echo "[OK] Pipeline is complete for " . $taskId . ". Next: agent-loop finish " . $taskId . "\n";
            }

            return 0;
        }

        if ($projection->attention !== null) {
            throw new RuntimeException(sprintf(
                "Pipeline is waiting for attention %s: %s",
                $projection->attention->id,
                $projection->attention->message,
            ));
        }

        $currentStageId = $projection->currentStageId;
        if ($currentStageId === null) {
            throw new RuntimeException("No current execution stage available.");
        }

        $bundle = $gateway->prepareStage($taskId, $currentStageId);
        $acceptedOutcomes = array_map(static fn (StageOutcome $o): string => $o->value, $bundle->acceptedOutcomes);

        $nextAction = sprintf(
            "agent-loop pipeline submit %s --outcome %s --summary \"<summary>\"",
            $taskId,
            $acceptedOutcomes[0] ?? "completed",
        );

        $payload = [
            "schema_version" => "1.0",
            "command" => "pipeline stage",
            "task_id" => $taskId,
            "stage_id" => $bundle->stageId,
            "attempt" => $bundle->attempt,
            "kind" => $bundle->kind->value,
            "role" => $bundle->roleId,
            "may_mutate" => $bundle->mayMutate,
            "allowed_scope" => $bundle->allowedScope,
            "required_validation" => $bundle->requiredValidation,
            "accepted_outcomes" => $acceptedOutcomes,
            "prior_handoff" => $bundle->priorHandoff?->toArray(),
            "completion_marker" => $bundle->completionMarker,
            "prompt" => $bundle->prompt,
            "next_action" => $nextAction,
            "next_action_kind" => RunPolicyEvaluation::KIND_HOST_WORK,
        ];

        if ($format === "json") {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        } else {
            echo "=== Pipeline Stage: " . $bundle->stageId . " (Attempt " . $bundle->attempt . ") ===\n";
            echo "Role: " . ($bundle->roleId ?? "deterministic") . "\n";
            echo "Mutation: " . ($bundle->mayMutate ? "allowed" : "read-only") . "\n";
            echo "Allowed scope: " . implode(", ", $bundle->allowedScope) . "\n";
            echo "Accepted outcomes: " . implode(", ", $acceptedOutcomes) . "\n";
            if ($bundle->priorHandoff !== null) {
                echo "\n--- Prior Handoff from " . $bundle->priorHandoff->fromStage . " ---\n";
                echo "Candidate: " . $bundle->priorHandoff->candidateRevision . "\n";
            }
            echo "\n--- Stage Prompt ---\n";
            echo $bundle->prompt . "\n";
            echo "\nNext: " . $nextAction . "\n";
        }

        return 0;
    }

    /**
     * @param list<string> $tokens
     */
    private function submit(string $taskIdString, array $tokens, string $format): int
    {
        $taskId = (new WorkflowTaskId($taskIdString))->value;
        $outcomeRaw = OptionTokens::value($tokens, "outcome");
        if ($outcomeRaw === null) {
            throw new InvalidArgumentException("pipeline submit requires --outcome <name>.");
        }

        $outcome = StageOutcome::tryFrom($outcomeRaw);
        if ($outcome === null) {
            $allowed = implode(", ", array_map(static fn (StageOutcome $o): string => $o->value, StageOutcome::cases()));
            throw new InvalidArgumentException("Invalid outcome: {$outcomeRaw}. Allowed: {$allowed}");
        }

        $summary = OptionTokens::value($tokens, "summary") ?? ("Stage result: " . $outcome->value);
        $candidate = OptionTokens::value($tokens, "candidate");
        $autoVerify = OptionTokens::hasFlag($tokens, "auto-verify") || OptionTokens::value($tokens, "auto-verify") === "true";

        $gateway = new ExecutionGateway($this->rootPath);
        $projection = $gateway->projection($taskId);
        if ($projection->complete()) {
            throw new RuntimeException("Pipeline is already complete.");
        }
        if ($projection->attention !== null) {
            throw new RuntimeException("Pipeline is waiting for attention: " . $projection->attention->message);
        }

        $currentStageId = $projection->currentStageId;
        if ($currentStageId === null) {
            throw new RuntimeException("No current stage to submit.");
        }

        $bundle = $gateway->prepareStage($taskId, $currentStageId);
        $effectiveCandidate = $candidate ?? $bundle->candidateRevision;

        $submissionId = sprintf(
            "submission:%s:%d:%s",
            $bundle->stageId,
            $bundle->attempt,
            bin2hex(random_bytes(4)),
        );

        $stageResult = new StageResult(
            $submissionId,
            $bundle->taskId,
            $bundle->runId,
            $bundle->contractRevision,
            $bundle->executionPlanDigest,
            $bundle->stageId,
            $bundle->attempt,
            $outcome,
            $effectiveCandidate,
            [],
            [],
            $summary,
        );

        $newProjection = $gateway->submitStageResult($stageResult);

        // Auto-advance through deterministic stage if enabled and next stage is deterministic
        if ($autoVerify && $newProjection->currentStageId !== null) {
            $plan = (new ExecutionPlanStore($this->rootPath))->load($taskId);
            $nextStage = $plan->stage($newProjection->currentStageId);
            if ($nextStage->kind === ExecutionStageKind::DETERMINISTIC) {
                $newProjection = $gateway->runDeterministicStage($taskId, $nextStage->id);
            }
        }

        return $this->renderTransition($taskId, $newProjection, $format);
    }

    /**
     * @param list<string> $tokens
     */
    private function pipelineRun(string $taskIdString, array $tokens, string $format): int
    {
        $taskId = (new WorkflowTaskId($taskIdString))->value;
        $requestedProfile = OptionTokens::value($tokens, "profile");
        $actor = OptionTokens::value($tokens, "by") ?? "pipeline-runner";
        $autoVerify = true; // Default auto-verify to true in run mode unless explicitly disabled
        if (OptionTokens::value($tokens, "auto-verify") === "false") {
            $autoVerify = false;
        }

        $contractStore = new TaskContractStore($this->rootPath);
        $contract = $contractStore->load($taskId);
        if ($contract->status !== TaskContract::APPROVED) {
            throw new RuntimeException("Task " . $taskId . " does not have an approved Contract.");
        }

        $runStore = new GovernedRunStore($this->rootPath);
        $run = $runStore->findForContract($contract);
        if (!$run instanceof GovernedRun) {
            throw new RuntimeException("Task " . $taskId . " does not have an active governed Run. Call `agent-loop enter " . $taskId . "` first.");
        }

        if ($requestedProfile !== null) {
            $profileName = ExecutionProfileName::tryFrom($requestedProfile);
            if ($profileName === null) {
                throw new InvalidArgumentException("Unknown execution profile: " . $requestedProfile);
            }
            $selectionStore = new ExecutionProfileSelectionStore($this->rootPath);
            if ($selectionStore->find($taskId) === null && $runStore->findForContract($contract) === null) {
                $selectionStore->select($taskId, $profileName, $actor);
            }
        }

        $gateway = new ExecutionGateway($this->rootPath);
        $projection = $gateway->projection($taskId);
        $planStore = new ExecutionPlanStore($this->rootPath);

        // Loop through deterministic stages automatically
        while (!$projection->complete() && $projection->attention === null) {
            $stageId = $projection->currentStageId;
            if ($stageId === null) {
                break;
            }

            $plan = $planStore->load($taskId);
            $stage = $plan->stage($stageId);

            if ($stage->kind === ExecutionStageKind::DETERMINISTIC) {
                if ($autoVerify) {
                    $projection = $gateway->runDeterministicStage($taskId, $stageId);
                } else {
                    break;
                }
            } else {
                // Agent stage reached; requires agent execution
                break;
            }
        }

        return $this->renderTransition($taskId, $projection, $format);
    }

    private function renderTransition(string $taskId, ExecutionProjection $projection, string $format): int
    {
        $isComplete = $projection->complete();
        $currentStage = $projection->currentStageId;

        $nextAction = "agent-loop pipeline stage " . $taskId;
        $nextActionKind = RunPolicyEvaluation::KIND_HOST_WORK;

        if ($isComplete) {
            $nextAction = "agent-loop finish " . $taskId;
            $nextActionKind = RunPolicyEvaluation::KIND_COMMAND;
        } elseif ($projection->attention !== null) {
            $nextAction = sprintf(
                "agent-loop workflow attention %s --resolve %s --by <actor>",
                $taskId,
                $projection->attention->id,
            );
            $nextActionKind = RunPolicyEvaluation::KIND_DECISION_REQUIRED;
        } elseif ($currentStage !== null) {
            $plan = (new ExecutionPlanStore($this->rootPath))->load($taskId);
            $stage = $plan->stage($currentStage);
            if ($stage->kind === ExecutionStageKind::DETERMINISTIC) {
                $nextAction = "agent-loop pipeline run " . $taskId;
                $nextActionKind = RunPolicyEvaluation::KIND_COMMAND;
            } else {
                $nextAction = "agent-loop pipeline stage " . $taskId;
                $nextActionKind = RunPolicyEvaluation::KIND_HOST_WORK;
            }
        }

        $payload = [
            "schema_version" => "1.0",
            "command" => "pipeline",
            "task_id" => $taskId,
            "profile" => $projection->profile->value,
            "complete" => $isComplete,
            "current_stage" => $currentStage,
            "attempt" => $projection->currentAttempt,
            "attention" => $projection->attention?->toArray(),
            "next_action" => $nextAction,
            "next_action_kind" => $nextActionKind,
        ];

        if ($format === "json") {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        } else {
            if ($isComplete) {
                echo "[OK] Pipeline execution complete for " . $taskId . ".\n";
                echo "Next: " . $nextAction . "\n";
            } elseif ($projection->attention !== null) {
                echo "[WAIT] Pipeline requires attention: " . $projection->attention->message . "\n";
                echo "Attention ID: " . $projection->attention->id . "\n";
                echo "Resolve: " . $nextAction . "\n";
            } else {
                echo "Pipeline active: " . $taskId . "\n";
                echo "Profile: " . $projection->profile->value . "\n";
                echo "Stage: " . ($currentStage ?? "none") . " (Attempt " . $projection->currentAttempt . ")\n";
                echo "Next: " . $nextAction . "\n";
            }
        }

        return $projection->attention !== null ? 1 : 0;
    }

    private function printHelp(): void
    {
        echo <<<TXT
Usage:
  agent-loop pipeline status <task-id> [--format text|json]
  agent-loop pipeline stage <task-id> [--format text|json]
  agent-loop pipeline run <task-id> [--profile surgical|standard|hardened] [--by <actor>] [--auto-verify] [--format text|json]
  agent-loop pipeline submit <task-id> --outcome <completed|pass|changes_required|blocked|needs_clarification|failed> [--summary "<text>"] [--candidate <rev>] [--auto-verify] [--format text|json]

Automated multi-stage execution runner for governed task profiles.
TXT;
        echo "\n";
    }
}
