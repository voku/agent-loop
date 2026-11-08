<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\WorkflowContractApprovalPresentation;

final class WorkflowContractApprovalBaselineTest extends TestCase
{
    public function testArchivedHumanApprovalIsVisibleWithoutGrantingCurrentAuthority(): void
    {
        $previous = $this->contract(TaskContract::SUPERSEDED, 'project-owner', '2026-11-07T12:00:00+00:00');
        $candidate = $this->contract(TaskContract::CANDIDATE, null, null);
        $presentation = new WorkflowContractApprovalPresentation($candidate, $previous, 'approve');
        $delta = $presentation->revisionDelta();

        self::assertTrue($previous->hasRecordedNonReservedApproval());
        self::assertFalse($previous->hasNonReservedApproval());
        self::assertTrue($delta['previous_was_approved'] ?? false);
        self::assertSame('project-owner', $delta['previous_approved_by'] ?? null);
        self::assertSame('2026-11-07T12:00:00+00:00', $delta['previous_approved_at'] ?? null);
        self::assertTrue($presentation->subject('contract.json')['revision_delta']['previous_was_approved']);
    }

    public function testLegacyReservedPseudoActorIsNotPresentedAsHumanApproval(): void
    {
        $previous = $this->contract(TaskContract::SUPERSEDED, ' standing:legacy-actor ', '2026-11-07T12:00:00+00:00');
        $presentation = new WorkflowContractApprovalPresentation(
            $this->contract(TaskContract::CANDIDATE, null, null),
            $previous,
            'approve',
        );
        $delta = $presentation->revisionDelta();

        self::assertFalse($previous->hasRecordedNonReservedApproval());
        self::assertFalse($delta['previous_was_approved'] ?? true);
        self::assertNull($delta['previous_approved_by'] ?? null);
        self::assertNull($delta['previous_approved_at'] ?? null);
    }

    public function testIncompleteApprovalMetadataIsNotPresentedAsHumanApproval(): void
    {
        foreach ([null, '', ' ', 'standing:actor'] as $actor) {
            $previous = $this->contract(TaskContract::SUPERSEDED, $actor, '2026-11-07T12:00:00+00:00');
            self::assertFalse($previous->hasRecordedNonReservedApproval());
        }

        $previous = $this->contract(TaskContract::SUPERSEDED, 'project-owner', null);
        self::assertFalse($previous->hasRecordedNonReservedApproval());

        $previous = $this->contract(TaskContract::SUPERSEDED, 'project-owner', '');
        self::assertFalse($previous->hasRecordedNonReservedApproval());

        $presentation = new WorkflowContractApprovalPresentation(
            $this->contract(TaskContract::CANDIDATE, null, null),
            $this->contract(TaskContract::SUPERSEDED, null, null),
            'approve',
        );
        $delta = $presentation->revisionDelta();
        self::assertFalse($delta['previous_was_approved'] ?? true);
        self::assertNull($delta['previous_approved_by'] ?? null);
    }

    private function contract(string $status, ?string $approvedBy, ?string $approvedAt): TaskContract
    {
        return new TaskContract(
            taskId: 'APPROVAL-BASELINE',
            goal: 'Review the approval baseline.',
            scope: ['src/Workflow'],
            nonGoals: [],
            validation: ['vendor/bin/phpunit'],
            status: $status,
            revision: $status === TaskContract::CANDIDATE ? 2 : 1,
            createdAt: '2026-11-07T11:00:00+00:00',
            updatedAt: '2026-11-07T11:00:00+00:00',
            path: '/tmp/approval-baseline.json',
            plannedBy: 'planner',
            approvedBy: $approvedBy,
            approvedAt: $approvedAt,
        );
    }
}
