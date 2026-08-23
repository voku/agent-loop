<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

final readonly class StageExecutionBundle
{
    /**
     * @param array{path: non-empty-string, sha256: non-empty-string} $contractSource
     * @param array{path: non-empty-string, sha256: non-empty-string}|null $recallSource
     * @param list<non-empty-string> $allowedScope
     * @param list<non-empty-string> $requiredValidation
     * @param list<StageOutcome> $acceptedOutcomes
     */
    public function __construct(
        public string $taskId,
        public string $runId,
        public int $contractRevision,
        public string $executionPlanDigest,
        public string $stageId,
        public int $attempt,
        public ExecutionStageKind $kind,
        public ?string $roleId,
        public bool $mayMutate,
        public string $repositoryRoot,
        public ?string $baseCommit,
        public string $candidateRevision,
        public array $contractSource,
        public ?array $recallSource,
        public array $allowedScope,
        public array $requiredValidation,
        public ?HandoffEnvelope $priorHandoff,
        public array $acceptedOutcomes,
        public string $prompt,
    ) {
    }
}
