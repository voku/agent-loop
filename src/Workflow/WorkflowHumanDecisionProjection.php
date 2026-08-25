<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

final readonly class WorkflowHumanDecisionProjection
{
    public const string APPROVE_CONTRACT = 'approve_contract';
    public const string ACKNOWLEDGE_REVIEW = 'acknowledge_review';
    public const string RECORD_LEARNING = 'record_learning';

    /** @param list<self::APPROVE_CONTRACT|self::ACKNOWLEDGE_REVIEW|self::RECORD_LEARNING> $actions */
    public function __construct(
        public string $taskId,
        public array $actions,
        public ?string $reviewReportSha256,
    ) {
    }

    public function allows(string $action): bool
    {
        return in_array($action, $this->actions, true);
    }
}
