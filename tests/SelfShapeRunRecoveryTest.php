<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Dogfood\SelfShapeRunRecovery;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStatus;

final class SelfShapeRunRecoveryTest extends TestCase
{
    public function testOwnActiveSessionCanBeDroppedBeforeARepeatRun(): void
    {
        self::assertSame(
            '2026-08-14-self-shape',
            (new SelfShapeRunRecovery())->sessionToDrop([
                $this->session('2026-08-14-self-shape', 'SELF-SHAPE', SessionStatus::ACTIVE, 'agent-loop-self-shape'),
            ], 'SELF-SHAPE', 'agent-loop-self-shape'),
        );
    }

    public function testClosedAndOtherTaskSessionsNeedNoRecovery(): void
    {
        self::assertNull((new SelfShapeRunRecovery())->sessionToDrop([
            $this->session('closed', 'SELF-SHAPE', SessionStatus::DONE, 'agent-loop-self-shape'),
            $this->session('other', 'OTHER', SessionStatus::ACTIVE, 'agent-loop-self-shape'),
        ], 'SELF-SHAPE', 'agent-loop-self-shape'));
    }

    public function testForeignActiveSessionIsNeverAutoDropped(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to drop active self-shape Session');

        (new SelfShapeRunRecovery())->sessionToDrop([
            $this->session('manual', 'SELF-SHAPE', SessionStatus::ACTIVE, 'human-maintainer'),
        ], 'SELF-SHAPE', 'agent-loop-self-shape');
    }

    private function session(string $id, string $taskId, SessionStatus $status, ?string $claimedBy): Session
    {
        return new Session(
            $id,
            $taskId,
            $status,
            $claimedBy,
            null,
            null,
            '2026-08-14T12:00:00+00:00',
            '2026-08-14T12:00:00+00:00',
            [],
            '/tmp/' . $id,
        );
    }
}
