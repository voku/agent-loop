<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use Closure;
use JsonException;
use Throwable;
use voku\AgentLoop\PathResolver;
use voku\AgentLoop\Run\RunPolicyEvaluation;

/**
 * CLI-facing presentation layer around HostFrontDoorCommand.
 *
 * Lifecycle authority stays in HostFrontDoorCommand and its owners. This layer
 * enriches JSON with exact human decision subjects and keeps the disposable
 * review workbench visible even when acknowledgement is delegated after task
 * approval.
 */
final readonly class HostFrontDoorApplication
{
    private HostFrontDoorCommand $command;

    private HostFinishFindingIdAdapter $findingIdAdapter;

    private HostLearningNoteFollowUpProjector $learningNoteFollowUps;

    private ?Closure $recallRunner;

    /** @param null|callable(list<string>): int $recallRunner */
    public function __construct(private string $rootPath, ?callable $recallRunner = null)
    {
        $this->recallRunner = $recallRunner === null ? null : Closure::fromCallable($recallRunner);
        $this->command = new HostFrontDoorCommand($rootPath, $this->recallRunner);
        $this->findingIdAdapter = new HostFinishFindingIdAdapter($rootPath);
        $this->learningNoteFollowUps = new HostLearningNoteFollowUpProjector($rootPath);
    }

    /** @param list<string> $args */
    public function run(string $command, array $args): int
    {
        if (!$this->jsonRequested($args)) {
            return $this->runFrontDoor($command, $args);
        }

        $level = ob_get_level();
        ob_start();
        try {
            $exitCode = $this->runFrontDoor($command, $args);
            $stdout = (string) ob_get_contents();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        try {
            $payload = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            echo $stdout;

            return $exitCode;
        }
        if (!is_array($payload)) {
            echo $stdout;

            return $exitCode;
        }

        $taskId = $payload['task_id'] ?? null;
        $nextAction = $payload['next_action'] ?? null;
        $nextActionKind = $payload['next_action_kind'] ?? null;
        $blockers = $this->blockers($payload['blockers'] ?? null);
        if ($command === 'finish') {
            $finishFailure = $this->finishFailure($blockers);
            if ($finishFailure !== null) {
                $payload['mutation_status'] = 'refused';
                $payload['error'] = $finishFailure;
            } elseif (!isset($payload['mutation_status'])) {
                $payload['mutation_status'] = 'accepted';
            }

            if (($payload['complete'] ?? false) === true && is_string($taskId)) {
                try {
                    $payload['optional_follow_ups'] = $this->learningNoteFollowUps->project($taskId);
                } catch (Throwable $exception) {
                    $payload['optional_follow_ups'] = [];
                    $payload['follow_up_warnings'] = [[
                        'code' => 'learning_note.follow_up_projection_failed',
                        'owner' => 'agent-learning',
                        'message' => $exception->getMessage(),
                    ]];
                }
            }
        }

        if (is_string($taskId) && $this->materializeReviewPresentationWhenRequired($taskId)) {
            $review = (new WorkflowReviewReportReader($this->rootPath))->detail($taskId);
            $html = new WorkflowHumanReviewCommand($this->rootPath);
            $payload['review_presentation'] = [
                'schema_version' => '1.0',
                'kind' => 'html',
                'path' => PathResolver::relativeTo($this->rootPath, $html->path($taskId)),
                'exists' => is_file($html->path($taskId)),
                'review_sha256' => $review->sha256,
                'report_status' => $review->reportStatus,
                'contract_revision' => $review->contractRevision,
                'implementation_snapshot' => $review->implementationSnapshot,
                'findings' => array_map(static fn ($finding): array => $finding->toArray(), $review->findings),
            ];
        }
        if (
            is_string($taskId)
            && is_string($nextAction)
            && $nextActionKind === RunPolicyEvaluation::KIND_DECISION_REQUIRED
        ) {
            $decision = (new WorkflowHumanDecisionProjector($this->rootPath))->project(
                $taskId,
                $nextAction,
                $nextActionKind,
                $blockers,
            );
            if ($decision !== null) {
                $payload['human_decision'] = $decision;
            }
        }

        echo json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";

        return $exitCode;
    }

    /** @param list<string> $args */
    private function runFrontDoor(string $command, array $args): int
    {
        if ($command === 'finish' && $this->findingIdAdapter->supports($args)) {
            return $this->findingIdAdapter->run($args);
        }

        return $this->command->run($command, $args);
    }

    private function materializeReviewPresentationWhenRequired(string $taskId): bool
    {
        $available = (new WorkflowHumanDecisionService($this->rootPath))->availableActions($taskId);
        if (!$available->allows(WorkflowHumanDecisionProjection::ACKNOWLEDGE_REVIEW)) {
            return false;
        }

        $level = ob_get_level();
        ob_start(static fn (string $buffer): string => '');
        try {
            (new WorkflowHumanReviewCommand($this->rootPath))->run([$taskId]);
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return true;
    }

    /** @param list<string> $args */
    private function jsonRequested(array $args): bool
    {
        foreach ($args as $index => $argument) {
            if ($argument === '--format=json') {
                return true;
            }
            if ($argument === '--format' && ($args[$index + 1] ?? null) === 'json') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{code: string, owner: string, message: string}> $blockers
     * @return array{code: string, owner: string, message: string}|null
     */
    private function finishFailure(array $blockers): ?array
    {
        foreach ($blockers as $blocker) {
            if (str_starts_with($blocker['code'], 'finish.')) {
                return $blocker;
            }
        }

        return null;
    }

    /**
     * @return list<array{code: string, owner: string, message: string}>
     */
    private function blockers(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $blockers = [];
        foreach ($value as $blocker) {
            if (
                !is_array($blocker)
                || !is_string($blocker['code'] ?? null)
                || !is_string($blocker['owner'] ?? null)
                || !is_string($blocker['message'] ?? null)
            ) {
                continue;
            }
            $blockers[] = [
                'code' => $blocker['code'],
                'owner' => $blocker['owner'],
                'message' => $blocker['message'],
            ];
        }

        return $blockers;
    }
}
