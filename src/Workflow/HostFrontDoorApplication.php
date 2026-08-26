<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use Closure;
use JsonException;
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

    private ?Closure $recallRunner;

    /** @param null|callable(list<string>): int $recallRunner */
    public function __construct(private string $rootPath, ?callable $recallRunner = null)
    {
        $this->recallRunner = $recallRunner === null ? null : Closure::fromCallable($recallRunner);
        $this->command = new HostFrontDoorCommand($rootPath, $this->recallRunner);
    }

    /** @param list<string> $args */
    public function run(string $command, array $args): int
    {
        if (!$this->jsonRequested($args)) {
            return $this->command->run($command, $args);
        }

        $level = ob_get_level();
        ob_start();
        try {
            $exitCode = $this->command->run($command, $args);
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
            $blockers = $this->blockers($payload['blockers'] ?? null);
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
