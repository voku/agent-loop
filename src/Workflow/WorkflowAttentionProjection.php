<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use voku\AgentLoop\Execution\AttentionRequest;

/** The owner state a human reviewed before resolving an execution interruption. */
final readonly class WorkflowAttentionProjection
{
    public const string RESOLVE = 'resolve_attention';

    /**
     * @param list<string> $scope
     * @param list<self::RESOLVE> $actions
     */
    public function __construct(
        public AttentionRequest $attention,
        public int $contractRevision,
        public array $scope,
        public string $executionPlanDigest,
        public int $attempt,
        public string $candidateRevision,
        public ?string $headCommit,
        public array $actions = [self::RESOLVE],
    ) {
    }
}
