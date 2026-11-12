<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\TaskAutonomyGrant;
use voku\AgentLoop\Workflow\TaskAutonomyGrantCertificate;
use voku\AgentLoop\Workflow\TaskAutonomyGrantSealer;
use voku\AgentLoop\Workflow\TaskContract;

final class TaskAutonomyGrantCertificateTest extends TestCase
{
    public function testSealedGrantRoundTripsButNeverAuthorizesHumanOrLearningOperations(): void
    {
        $now = new DateTimeImmutable('2026-11-11T13:00:00+00:00');
        $contract = self::contract();
        $grant = TaskAutonomyGrant::request($contract, 'project-owner', $now, $now->modify('+1 hour'));
        $sealer = new TaskAutonomyGrantSealer(str_repeat('S', 32));

        $issued = $sealer->seal($grant);
        $restored = TaskAutonomyGrantCertificate::fromArray($issued->toArray());

        self::assertTrue($sealer->hasValidSeal($restored));
        self::assertSame($issued->toArray(), $restored->toArray());
        self::assertTrue($restored->grant->isEligibleFor($contract, 'repair', $now, ['src/Fix.php']));
        self::assertTrue($restored->grant->isEligibleFor($contract, 'review', $now));
        self::assertFalse($restored->grant->isEligibleFor($contract, 'learn', $now));
        self::assertFalse($restored->grant->isEligibleFor($contract, 'acknowledge_review', $now));
        self::assertFalse($restored->grant->isEligibleFor($contract, 'merge', $now));
    }

    public function testDifferentIssuerKeyOrTamperingWithAnySignedClaimFails(): void
    {
        $now = new DateTimeImmutable('2026-11-11T13:00:00+00:00');
        $contract = self::contract();
        $sealer = new TaskAutonomyGrantSealer(str_repeat('S', 32));
        $record = $sealer->seal(TaskAutonomyGrant::request($contract, 'owner', $now, $now->modify('+1 hour')));
        self::assertFalse((new TaskAutonomyGrantSealer(str_repeat('T', 32)))->hasValidSeal($record));

        foreach ([
            ['task_id' => 'OTHER-1'],
            ['contract_revision' => 3],
            ['contract_digest' => 'sha256:' . str_repeat('b', 64)],
            ['requested_by' => 'other-actor'],
            ['expires_at' => '2026-11-11T15:00:00.000000Z'],
            ['nonce' => str_repeat('b', 32)],
        ] as $change) {
            $altered = TaskAutonomyGrantCertificate::fromArray(array_replace($record->toArray(), $change));
            self::assertFalse($sealer->hasValidSeal($altered));
        }
    }

    public function testMalformedSchemaAndAmbiguousTimestampsFailClosed(): void
    {
        $now = new DateTimeImmutable('2026-11-11T13:00:00+00:00');
        $record = (new TaskAutonomyGrantSealer(str_repeat('S', 32)))->seal(
            TaskAutonomyGrant::request(self::contract(), 'owner', $now, $now->modify('+1 hour')),
        )->toArray();

        foreach ([
            ['expires_at' => 'tomorrow'],
            ['issued_at' => '2026-11-11T13:00:00Z'],
            ['contract_revision' => '2'],
            ['injected' => 'ignored'],
        ] as $invalid) {
            try {
                TaskAutonomyGrantCertificate::fromArray(array_replace($record, $invalid));
                self::fail('Untrusted certificate must reject malformed or extra claims.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRevokedRequestCannotBeSealed(): void
    {
        $now = new DateTimeImmutable('2026-11-11T13:00:00+00:00');
        $grant = TaskAutonomyGrant::request(self::contract(), 'owner', $now, $now->modify('+1 hour'));

        $this->expectException(\RuntimeException::class);
        (new TaskAutonomyGrantSealer(str_repeat('S', 32)))->seal($grant->revoke($now->modify('+1 minute')));
    }

    private static function contract(): TaskContract
    {
        return new TaskContract(
            taskId: 'TASK-1',
            goal: 'Implement and validate a scoped change.',
            scope: ['src'],
            nonGoals: ['merge', 'deploy', 'learning'],
            validation: ['vendor/bin/phpunit'],
            status: TaskContract::APPROVED,
            revision: 2,
            createdAt: '2026-11-11T12:00:00+00:00',
            updatedAt: '2026-11-11T12:10:00+00:00',
            path: '/tmp/contract.json',
            plannedBy: 'planner',
            approvedBy: 'human-operator',
            approvedAt: '2026-11-11T12:10:00+00:00',
        );
    }
}
