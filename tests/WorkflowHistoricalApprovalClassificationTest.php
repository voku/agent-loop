<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\WorkflowContractApprovalPresentation;

final class WorkflowHistoricalApprovalClassificationTest extends TestCase
{
    public function testSupersededHumanApprovalIsClassifiedAsHuman(): void
    {
        $delta = $this->delta(TaskContract::SUPERSEDED, 'human-approver', '2026-11-08T00:00:00Z');

        self::assertTrue($delta['previous_was_human_approved']);
    }

    public function testLegacyStandingActorNeverCountsAsHumanApproval(): void
    {
        $delta = $this->delta(TaskContract::SUPERSEDED, ' standing:legacy-policy', '2026-11-08T00:00:00Z');

        self::assertSame(' standing:legacy-policy', $delta['previous_approved_by']);
        self::assertFalse($delta['previous_was_human_approved']);
    }

    public function testSupersededDraftNeverCountsAsHumanApproval(): void
    {
        $delta = $this->delta(TaskContract::SUPERSEDED, null, null);

        self::assertFalse($delta['previous_was_human_approved']);
    }

    /** @return array<string, mixed> */
    private function delta(string $status, ?string $actor, ?string $approvedAt): array
    {
        $previous = new TaskContract(
            taskId: 'APPROVAL-1',
            goal: 'Previous goal',
            scope: ['src/A.php'],
            nonGoals: [],
            validation: ['composer ci'],
            status: $status,
            revision: 1,
            createdAt: '2026-11-08T00:00:00Z',
            updatedAt: '2026-11-08T00:00:00Z',
            path: '/tmp/approval-1.json',
            plannedBy: 'planner',
            approvedBy: $actor,
            approvedAt: $approvedAt,
        );
        $candidate = new TaskContract(
            taskId: 'APPROVAL-1',
            goal: 'New goal',
            scope: ['src/A.php', 'src/B.php'],
            nonGoals: [],
            validation: ['composer ci'],
            status: TaskContract::CANDIDATE,
            revision: 2,
            createdAt: '2026-11-08T00:00:00Z',
            updatedAt: '2026-11-08T00:00:00Z',
            path: '/tmp/approval-2.json',
            plannedBy: 'planner',
        );

        return (new WorkflowContractApprovalPresentation($candidate, $previous, 'approve'))->revisionDelta()
            ?? self::fail('Expected a revision delta.');
    }
}
