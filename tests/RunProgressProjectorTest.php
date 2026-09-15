<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Run\RunProgressProjector;
use voku\AgentLoop\Run\RunProgressStep;

final class RunProgressProjectorTest extends TestCase
{
    public function testCandidateContractMakesApprovalTheCurrentBoundary(): void
    {
        $progress = (new RunProgressProjector())->project(
            'ABC-123',
            'planned',
            $this->references(
                contract: 'candidate',
                approval: 'missing',
                session: 'missing',
                recall: 'missing',
                executionContract: 'not_required',
                verification: 'pending_close',
                review: 'missing',
                learning: 'unavailable',
            ),
            [],
        );

        self::assertSame('decision_required', $progress->nextActionKind);
        self::assertStringContainsString('workflow approve ABC-123', $progress->nextAction);
        self::assertSame(
            [
                'contract' => RunProgressStep::STATUS_CURRENT,
                'preparation' => RunProgressStep::STATUS_PENDING,
                'execution_context' => RunProgressStep::STATUS_NOT_APPLICABLE,
                'implementation' => RunProgressStep::STATUS_PENDING,
                'validation' => RunProgressStep::STATUS_PENDING,
                'review' => RunProgressStep::STATUS_PENDING,
                'recall_outcomes' => RunProgressStep::STATUS_NOT_APPLICABLE,
                'learning' => RunProgressStep::STATUS_PENDING,
                'closeout' => RunProgressStep::STATUS_PENDING,
            ],
            $this->statuses($progress->steps),
        );
    }

    public function testPreparedGovernedRunMakesImplementationCurrent(): void
    {
        $progress = (new RunProgressProjector())->project(
            'ABC-123',
            'governed',
            $this->references(
                contract: 'approved',
                approval: 'current',
                session: 'active',
                recall: 'compiled',
                executionContract: 'not_required',
                verification: 'pending_close',
                review: 'missing',
                learning: 'missing',
            ),
            [],
        );

        self::assertSame('host_work', $progress->nextActionKind);
        self::assertSame(RunProgressStep::STATUS_DONE, $this->step($progress->steps, 'contract')->status);
        self::assertSame(RunProgressStep::STATUS_DONE, $this->step($progress->steps, 'preparation')->status);
        self::assertSame(RunProgressStep::STATUS_CURRENT, $this->step($progress->steps, 'implementation')->status);
        self::assertSame(RunProgressStep::STATUS_PENDING, $this->step($progress->steps, 'validation')->status);
    }

    public function testValidationFailureIsPreservedAsBlockedInsteadOfGenericProgress(): void
    {
        $references = $this->references(
            contract: 'approved',
            approval: 'current',
            session: 'active',
            recall: 'compiled',
            executionContract: 'not_required',
            verification: 'blocked',
            review: 'missing',
            learning: 'missing',
        );
        $references['verification']['gate'] = 'validation';
        $references['verification']['validation_failed'] = true;
        $references['verification']['reason'] = 'composer ci failed';

        $progress = (new RunProgressProjector())->project('ABC-123', 'governed', $references, []);
        $validation = $this->step($progress->steps, 'validation');

        self::assertSame('host_work', $progress->nextActionKind);
        self::assertSame(RunProgressStep::STATUS_BLOCKED, $validation->status);
        self::assertSame('composer ci failed', $validation->reason);
        self::assertStringContainsString('change the implementation', $progress->nextAction);
    }

    public function testReviewFailureStaysAtTheReviewBoundary(): void
    {
        $progress = (new RunProgressProjector())->project(
            'ABC-123',
            'governed',
            $this->references(
                contract: 'approved',
                approval: 'current',
                session: 'active',
                recall: 'compiled',
                executionContract: 'not_required',
                verification: 'pending_close',
                review: 'fail',
                learning: 'missing',
            ),
            [],
        );

        self::assertSame('blocked', $progress->state);
        self::assertSame(RunProgressStep::STATUS_DONE, $this->step($progress->steps, 'validation')->status);
        self::assertSame(RunProgressStep::STATUS_BLOCKED, $this->step($progress->steps, 'review')->status);
        self::assertSame(RunProgressStep::STATUS_PENDING, $this->step($progress->steps, 'learning')->status);
    }

    public function testCompletedRunHasNoFabricatedCurrentStepOrNextAction(): void
    {
        $progress = (new RunProgressProjector())->project(
            'ABC-123',
            'governed',
            $this->references(
                contract: 'approved',
                approval: 'current',
                session: 'done',
                recall: 'compiled',
                executionContract: 'not_required',
                verification: 'passed',
                review: 'ok',
                learning: 'decided',
            ),
            [],
        );

        self::assertSame('complete', $progress->state);
        self::assertSame('none', $progress->nextActionKind);
        self::assertSame('none', $progress->nextAction);
        self::assertSame(RunProgressStep::STATUS_NOT_APPLICABLE, $this->step($progress->steps, 'execution_context')->status);
        self::assertSame(RunProgressStep::STATUS_NOT_APPLICABLE, $this->step($progress->steps, 'recall_outcomes')->status);

        foreach ($progress->steps as $step) {
            self::assertNotSame(RunProgressStep::STATUS_CURRENT, $step->status);
            if ($step->status !== RunProgressStep::STATUS_NOT_APPLICABLE) {
                self::assertSame(RunProgressStep::STATUS_DONE, $step->status);
            }
        }
    }

    /**
     * @param list<RunProgressStep> $steps
     * @return array<string, string>
     */
    private function statuses(array $steps): array
    {
        $statuses = [];
        foreach ($steps as $step) {
            $statuses[$step->id] = $step->status;
        }

        return $statuses;
    }

    /** @param list<RunProgressStep> $steps */
    private function step(array $steps, string $id): RunProgressStep
    {
        foreach ($steps as $step) {
            if ($step->id === $id) {
                return $step;
            }
        }

        self::fail('Missing progress step ' . $id);
    }

    /** @return array<string, array<string, mixed>> */
    private function references(
        string $contract,
        string $approval,
        string $session,
        string $recall,
        string $executionContract,
        string $verification,
        string $review,
        string $learning,
    ): array {
        return [
            'session' => ['owner' => 'agent-session', 'state' => $session],
            'contract' => ['owner' => 'agent-loop', 'state' => $contract],
            'approval' => ['owner' => 'agent-loop', 'state' => $approval],
            'recall' => ['owner' => 'agent-recall-compiler', 'state' => $recall],
            'execution_contract' => ['owner' => 'agent-loop', 'state' => $executionContract],
            'verification' => ['owner' => 'agent-loop', 'state' => $verification],
            'review' => ['owner' => 'agent-recall-compiler', 'state' => $review],
            'learning' => ['owner' => 'agent-learning', 'state' => $learning],
        ];
    }
}
