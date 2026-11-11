<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Workflow\TaskAutonomyGrant;
use voku\AgentLoop\Workflow\TaskContract;

final class TaskAutonomyGrantTest extends TestCase
{
    public function testApprovedTaskCanRequestBoundedImplementationReviewAndRepair(): void
    {
        $now = new DateTimeImmutable('2026-11-11T13:00:00+00:00');
        $contract = self::contract();
        $grant = TaskAutonomyGrant::request($contract, 'project-owner', $now, $now->modify('+2 hours'));

        foreach (TaskAutonomyGrant::OPERATIONS as $operation) {
            self::assertTrue($grant->isEligibleFor($contract, $operation, $now));
        }

        foreach (['acknowledge_review', 'approve_contract', 'resolve_attention', 'learn', 'merge', 'deploy', 'push', 'change_scope'] as $forbidden) {
            self::assertFalse($grant->isEligibleFor($contract, $forbidden, $now), $forbidden);
        }
    }

    public function testGrantIsTaskAndExactContractBound(): void
    {
        $now = new DateTimeImmutable('2026-11-11T13:00:00+00:00');
        $original = self::contract();
        $grant = TaskAutonomyGrant::request($original, 'owner', $now, $now->modify('+1 hour'));

        self::assertFalse($grant->isEligibleFor(self::contract('TASK-B'), 'review', $now));
        self::assertFalse($grant->isEligibleFor(self::contract('TASK-A', revision: 3), 'repair', $now));
        self::assertFalse($grant->isEligibleFor(self::contract('TASK-A', scope: ['src', 'config']), 'implement', $now));
        self::assertFalse($grant->isEligibleFor(self::contract('TASK-A', status: TaskContract::CANDIDATE), 'implement', $now));
        self::assertFalse($grant->isEligibleFor(self::contract('TASK-A', approvedBy: 'standing:forged'), 'review', $now));
        self::assertTrue($grant->isEligibleFor($original, 'review', $now));
    }

    public function testExpiryAndRevocationFailClosed(): void
    {
        $now = new DateTimeImmutable('2026-11-11T13:00:00+00:00');
        $contract = self::contract();
        $grant = TaskAutonomyGrant::request($contract, 'owner', $now, $now->modify('+1 hour'));

        self::assertFalse($grant->isEligibleFor($contract, 'repair', $now->modify('-1 second')));
        self::assertFalse($grant->isEligibleFor($contract, 'repair', $now->modify('+1 hour')));
        self::assertFalse($grant->isEligibleFor($contract, 'repair', $now->modify('+2 hours')));
        self::assertFalse($grant->revoke($now->modify('+10 minutes'))->isEligibleFor($contract, 'repair', $now->modify('+11 minutes')));
    }

    public function testMissingHumanApprovalIsNotAGrant(): void
    {
        $now = new DateTimeImmutable('2026-11-11T13:00:00+00:00');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('human-approved Contract');

        TaskAutonomyGrant::request(
            self::contract(status: TaskContract::CANDIDATE),
            'owner',
            $now,
            $now->modify('+1 hour'),
        );
    }

    public function testRequestCannotPredateRecordedHumanApproval(): void
    {
        $now = new DateTimeImmutable('2026-11-11T12:09:00+00:00');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot predate');

        TaskAutonomyGrant::request(self::contract(), 'owner', $now, $now->modify('+1 hour'));
    }

    public function testRejectsUnboundedGrantAndReservedActor(): void
    {
        $now = new DateTimeImmutable('2026-11-11T13:00:00+00:00');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 24 hours');

        TaskAutonomyGrant::request(self::contract(), 'owner', $now, $now->modify('+25 hours'));
    }

    public function testRejectsReservedActor(): void
    {
        $now = new DateTimeImmutable('2026-11-11T13:00:00+00:00');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-reserved requester');

        TaskAutonomyGrant::request(self::contract(), 'standing:forge', $now, $now->modify('+1 hour'));
    }

    /**
     * @param list<string> $scope
     */
    private static function contract(
        string $taskId = 'TASK-A',
        int $revision = 2,
        array $scope = ['src'],
        string $status = TaskContract::APPROVED,
        string $approvedBy = 'owner',
    ): TaskContract {
        return new TaskContract(
            taskId: $taskId,
            goal: 'Fix the Task safely.',
            scope: $scope,
            nonGoals: ['merge', 'deploy'],
            validation: ['vendor/bin/phpunit'],
            status: $status,
            revision: $revision,
            createdAt: '2026-11-11T12:00:00+00:00',
            updatedAt: '2026-11-11T12:10:00+00:00',
            path: '/tmp/test.json',
            plannedBy: 'planner',
            approvedBy: $approvedBy,
            approvedAt: '2026-11-11T12:10:00+00:00',
        );
    }
}
