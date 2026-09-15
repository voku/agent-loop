<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Run\RunManifest;
use voku\AgentLoop\Run\RunPolicyEvaluation;
use voku\AgentLoop\Workflow\WorkflowProgressProjector;
use voku\AgentLoop\Workflow\WorkflowProgressStep;

final class WorkflowProgressProjectorTest extends TestCase
{
    public function testMissingContractIsTheCurrentOwnerBoundary(): void
    {
        $progress = (new WorkflowProgressProjector())->project($this->manifest([
            'contract' => ['owner' => 'agent-loop', 'state' => 'missing'],
        ]));

        self::assertSame('contract', $progress->currentStepId);
        self::assertSame('contract', $progress->steps[0]->id);
        self::assertSame(WorkflowProgressStep::STATE_CURRENT, $progress->steps[0]->state);
        self::assertSame(WorkflowProgressStep::STATE_PENDING, $progress->steps[1]->state);
        self::assertSame(RunPolicyEvaluation::KIND_COMMAND_TEMPLATE, $progress->nextActionKind);
        self::assertStringContainsString('workflow plan TASK-1', $progress->nextAction);
    }

    public function testPreparedRunAdvancesToExecutionContractWithoutConsumerOrdering(): void
    {
        $progress = (new WorkflowProgressProjector())->project($this->manifest([
            'contract' => ['owner' => 'agent-loop', 'state' => 'approved', 'revision' => 1, 'run_revision' => 1],
            'approval' => ['owner' => 'agent-loop', 'state' => 'current'],
            'session' => ['owner' => 'agent-session', 'state' => 'active'],
            'recall' => ['owner' => 'agent-recall-compiler', 'state' => 'compiled'],
            'execution_contract' => ['owner' => 'agent-loop', 'state' => 'missing'],
            'review' => ['owner' => 'agent-recall-compiler', 'state' => 'missing'],
            'learning' => ['owner' => 'agent-learning', 'state' => 'missing'],
            'verification' => ['owner' => 'agent-loop', 'state' => 'pending_close'],
        ]));

        self::assertSame('execution_contract', $progress->currentStepId);
        self::assertSame(WorkflowProgressStep::STATE_DONE, $progress->steps[0]->state);
        self::assertSame(WorkflowProgressStep::STATE_DONE, $progress->steps[1]->state);
        self::assertSame(WorkflowProgressStep::STATE_CURRENT, $progress->steps[2]->state);
        self::assertSame(WorkflowProgressStep::STATE_PENDING, $progress->steps[3]->state);
        self::assertSame(RunPolicyEvaluation::KIND_COMMAND_TEMPLATE, $progress->nextActionKind);
    }

    public function testValidationFailureStaysBlockedBeforeReviewAndLearning(): void
    {
        $progress = (new WorkflowProgressProjector())->project($this->manifest([
            'contract' => ['owner' => 'agent-loop', 'state' => 'approved', 'revision' => 1, 'run_revision' => 1],
            'approval' => ['owner' => 'agent-loop', 'state' => 'current'],
            'session' => ['owner' => 'agent-session', 'state' => 'active'],
            'recall' => ['owner' => 'agent-recall-compiler', 'state' => 'compiled'],
            'execution_contract' => ['owner' => 'agent-loop', 'state' => 'ready'],
            'review' => ['owner' => 'agent-recall-compiler', 'state' => 'missing'],
            'learning' => ['owner' => 'agent-learning', 'state' => 'missing'],
            'verification' => [
                'owner' => 'agent-loop',
                'state' => 'blocked',
                'gate' => 'validation',
                'validation_failed' => true,
                'reason' => 'validation evidence is not current',
            ],
        ]));

        self::assertSame('validation', $progress->currentStepId);
        self::assertSame(WorkflowProgressStep::STATE_DONE, $progress->steps[3]->state);
        self::assertSame(WorkflowProgressStep::STATE_BLOCKED, $progress->steps[4]->state);
        self::assertSame('validation evidence is not current', $progress->steps[4]->reason);
        self::assertSame(WorkflowProgressStep::STATE_PENDING, $progress->steps[5]->state);
        self::assertSame(WorkflowProgressStep::STATE_PENDING, $progress->steps[7]->state);
        self::assertSame(RunPolicyEvaluation::KIND_HOST_WORK, $progress->nextActionKind);
    }

    public function testReviewFailureIsBlockedWithoutCollapsingLaterSteps(): void
    {
        $progress = (new WorkflowProgressProjector())->project($this->manifest([
            'contract' => ['owner' => 'agent-loop', 'state' => 'approved', 'revision' => 1, 'run_revision' => 1],
            'approval' => ['owner' => 'agent-loop', 'state' => 'current'],
            'session' => ['owner' => 'agent-session', 'state' => 'active'],
            'recall' => ['owner' => 'agent-recall-compiler', 'state' => 'compiled'],
            'execution_contract' => ['owner' => 'agent-loop', 'state' => 'ready'],
            'review' => ['owner' => 'agent-recall-compiler', 'state' => 'fail'],
            'learning' => ['owner' => 'agent-learning', 'state' => 'missing'],
            'verification' => ['owner' => 'agent-loop', 'state' => 'pending_close'],
        ]));

        self::assertSame('review', $progress->currentStepId);
        self::assertSame(WorkflowProgressStep::STATE_DONE, $progress->steps[4]->state);
        self::assertSame(WorkflowProgressStep::STATE_BLOCKED, $progress->steps[5]->state);
        self::assertSame('agent-recall-compiler', $progress->steps[5]->owner);
        self::assertSame(WorkflowProgressStep::STATE_PENDING, $progress->steps[6]->state);
        self::assertNotNull($progress->steps[5]->reason);
    }

    public function testCompletedRunMarksOptionalExecutionContractNotApplicable(): void
    {
        $progress = (new WorkflowProgressProjector())->project($this->manifest([
            'contract' => ['owner' => 'agent-loop', 'state' => 'approved', 'revision' => 1, 'run_revision' => 1],
            'approval' => ['owner' => 'agent-loop', 'state' => 'current'],
            'session' => ['owner' => 'agent-session', 'state' => 'done'],
            'recall' => ['owner' => 'agent-recall-compiler', 'state' => 'compiled'],
            'execution_contract' => ['owner' => 'agent-loop', 'state' => 'not_required'],
            'review' => ['owner' => 'agent-recall-compiler', 'state' => 'ok'],
            'learning' => ['owner' => 'agent-learning', 'state' => 'decided'],
            'verification' => ['owner' => 'agent-loop', 'state' => 'passed'],
        ]));

        self::assertSame('complete', $progress->state);
        self::assertNull($progress->currentStepId);
        self::assertSame(WorkflowProgressStep::STATE_NOT_APPLICABLE, $progress->steps[2]->state);
        self::assertSame(RunPolicyEvaluation::KIND_NONE, $progress->nextActionKind);
        self::assertSame('none', $progress->nextAction);
        foreach ($progress->steps as $index => $step) {
            if ($index !== 2) {
                self::assertSame(WorkflowProgressStep::STATE_DONE, $step->state);
            }
        }
    }

    public function testEphemeralRunDoesNotPretendToHaveGovernedProgress(): void
    {
        $manifest = new RunManifest(
            'TASK-1',
            'run-1',
            'ephemeral',
            'experiment',
            ['session' => ['owner' => 'agent-session', 'state' => 'active', 'session_id' => 'session-1']],
            [],
            'none',
        );
        $progress = (new WorkflowProgressProjector())->project($manifest);

        self::assertSame('experiment', $progress->state);
        self::assertNull($progress->currentStepId);
        foreach ($progress->steps as $step) {
            self::assertSame(WorkflowProgressStep::STATE_NOT_APPLICABLE, $step->state);
        }
    }

    /** @param array<string, array<string, mixed>> $references */
    private function manifest(array $references): RunManifest
    {
        return new RunManifest(
            'TASK-1',
            'run-1',
            'governed',
            'incomplete',
            $references,
            [],
            'none',
        );
    }
}
