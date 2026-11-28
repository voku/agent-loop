<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Run\RunPolicyEvaluation;
use voku\AgentLoop\Run\RunPolicyEvaluator;

final class CanonicalActionKindTest extends TestCase
{
    public function testUnplannedTaskUsesModelOwnedCommandTemplateInsteadOfHumanDecision(): void
    {
        $policy = (new RunPolicyEvaluator())->evaluate(
            'E5-001',
            'legacy_inferred',
            $this->references(
                contract: 'missing',
                approval: 'unavailable',
                session: 'missing',
                recall: 'missing',
                executionContract: 'not_required',
                verification: 'pending_close',
                review: 'missing',
                learning: 'unavailable',
            ),
            [],
        );

        self::assertSame(RunPolicyEvaluation::KIND_COMMAND_TEMPLATE, $policy->nextActionKind);
        self::assertStringContainsString('--by <actor>', $policy->nextAction);
        self::assertStringContainsString('--file <path>', $policy->nextAction);
        self::assertStringContainsString('--goal <goal>', $policy->nextAction);
        self::assertStringContainsString('--validation <validation>', $policy->nextAction);
        self::assertStringNotContainsString('"..."', $policy->nextAction);
        self::assertNotNull($policy->nextActionInvocation);
        self::assertSame([
            'workflow',
            'plan',
            'E5-001',
            '--by',
            '<actor>',
            '--file',
            '<path>',
            '--goal',
            '<goal>',
            '--validation',
            '<validation>',
        ], $policy->nextActionInvocation->arguments);
        self::assertTrue($policy->nextActionInvocation->template);
    }

    public function testExecutionContractConstructionUsesModelOwnedCommandTemplate(): void
    {
        $policy = (new RunPolicyEvaluator())->evaluate(
            'E5-001',
            'governed',
            $this->references(
                contract: 'approved',
                approval: 'current',
                session: 'active',
                recall: 'compiled',
                executionContract: 'missing',
                verification: 'pending_close',
                review: 'missing',
                learning: 'unavailable',
            ),
            [],
        );

        self::assertSame(RunPolicyEvaluation::KIND_COMMAND_TEMPLATE, $policy->nextActionKind);
        self::assertStringContainsString('workflow contract E5-001 --status ready --from <l1.md>', $policy->nextAction);
        self::assertNotNull($policy->nextActionInvocation);
        self::assertSame(['workflow', 'contract', 'E5-001', '--status', 'ready', '--from', '<l1.md>', '--by', '<actor>'], $policy->nextActionInvocation->arguments);
        self::assertTrue($policy->nextActionInvocation->template);
    }

    public function testContractApprovalRemainsHumanDecision(): void
    {
        $policy = (new RunPolicyEvaluator())->evaluate(
            'E5-001',
            'planned',
            $this->references(
                contract: 'candidate',
                approval: 'unavailable',
                session: 'missing',
                recall: 'missing',
                executionContract: 'not_required',
                verification: 'pending_close',
                review: 'missing',
                learning: 'unavailable',
            ),
            [],
        );

        self::assertSame(RunPolicyEvaluation::KIND_DECISION_REQUIRED, $policy->nextActionKind);
        self::assertSame('agent-loop workflow approve E5-001 --revision 1 --by <named-actor>', $policy->nextAction);
        self::assertNotNull($policy->nextActionInvocation);
        self::assertSame(['workflow', 'approve', 'E5-001', '--revision', '1', '--by', '<named-actor>'], $policy->nextActionInvocation->arguments);
    }

    public function testContractApprovalUsesPlaceholderForInvalidRevision(): void
    {
        $references = $this->references(
            contract: 'candidate',
            approval: 'unavailable',
            session: 'missing',
            recall: 'missing',
            executionContract: 'not_required',
            verification: 'pending_close',
            review: 'missing',
            learning: 'unavailable',
        );
        $references['contract']['revision'] = '1 --by attacker';

        $policy = (new RunPolicyEvaluator())->evaluate('E5-001', 'planned', $references, []);

        self::assertSame(
            ['workflow', 'approve', 'E5-001', '--revision', '<current-revision>', '--by', '<named-actor>'],
            $policy->nextActionInvocation?->arguments,
        );
    }

    public function testReviewAcknowledgementIsDelegatedAfterContractApproval(): void
    {
        $references = $this->references(
            contract: 'approved',
            approval: 'current',
            session: 'active',
            recall: 'compiled',
            executionContract: 'not_required',
            verification: 'pending_close',
            review: 'unacknowledged',
            learning: 'missing',
        );
        $references['review']['source'] = ['sha256' => 'sha256:' . str_repeat('a', 64)];

        $policy = (new RunPolicyEvaluator())->evaluate('E5-001', 'governed', $references, []);

        self::assertSame(RunPolicyEvaluation::KIND_COMMAND_TEMPLATE, $policy->nextActionKind);
        self::assertStringContainsString('--reviewed-report-sha256 sha256:' . str_repeat('a', 64), $policy->nextAction);
        self::assertStringContainsString('--by <actor>', $policy->nextAction);
    }

    public function testIncompleteExecutionReviewPrecedesReportAcknowledgement(): void
    {
        $references = $this->executionReviewReferences(
            'unacknowledged',
            'independent review pipeline is incomplete at stage investigate',
        );

        $policy = (new RunPolicyEvaluator())->evaluate('E5-001', 'governed', $references, []);

        self::assertSame('agent-loop pipeline status E5-001 --format=json', $policy->nextAction);
        self::assertSame(RunPolicyEvaluation::KIND_COMMAND, $policy->nextActionKind);
        self::assertFalse($policy->ordinaryCloseAllowed);
    }

    public function testTerminalReviewBlockDoesNotRequestReportAcknowledgement(): void
    {
        $reasons = [
            'semantic review stage correctness-review has no accepted PASS result',
            'semantic review stage correctness-review is stale for the final candidate',
            'semantic review stage correctness-review has no fresh observed context identity',
            'semantic review stage correctness-review cannot prove isolation from predecessor build',
            'semantic review stage correctness-review has stale review evidence: evidence is stale',
            'selected non-manual profile has no semantic review stage after its final mutating stage',
        ];
        foreach ($reasons as $reason) {
            $policy = (new RunPolicyEvaluator())->evaluate(
                'E5-001',
                'governed',
                $this->executionReviewReferences('unacknowledged', $reason),
                [],
            );
            $this->assertBlockedExecutionReview($policy);
        }

        foreach (['unacknowledged', 'missing', 'invalid', 'stale', 'ok', 'warn'] as $state) {
            $policy = (new RunPolicyEvaluator())->evaluate(
                'E5-001',
                'governed',
                $this->executionReviewReferences(
                    $state,
                    'semantic review stage correctness-review has no accepted PASS result',
                ),
                [],
            );
            $this->assertBlockedExecutionReview($policy);
        }
    }

    public function testUnavailableReportStillRoutesAnIncompletePipeline(): void
    {
        foreach (['unacknowledged', 'missing', 'invalid', 'stale', 'ok', 'warn'] as $state) {
            $policy = (new RunPolicyEvaluator())->evaluate(
                'E5-001',
                'governed',
                $this->executionReviewReferences($state, 'independent review pipeline is incomplete at stage investigate'),
                [],
            );
            self::assertSame('agent-loop pipeline status E5-001 --format=json', $policy->nextAction);
            self::assertSame(RunPolicyEvaluation::KIND_COMMAND, $policy->nextActionKind);
            self::assertStringNotContainsString('--reviewed-report-sha256', $policy->nextAction);
            self::assertFalse($policy->ordinaryCloseAllowed);
        }

        foreach (['independent review pipeline has not started', 'independent review pipeline is waiting for Attention'] as $reason) {
            $policy = (new RunPolicyEvaluator())->evaluate(
                'E5-001',
                'governed',
                $this->executionReviewReferences('unacknowledged', $reason),
                [],
            );
            self::assertSame('agent-loop pipeline status E5-001 --format=json', $policy->nextAction);
        }
    }

    public function testRecoveredPipelineRoutesBeforeAcknowledgementAndThenAllowsIt(): void
    {
        $references = $this->executionReviewReferences(
            'unacknowledged',
            'recovered execution pipeline: review is incomplete',
        );
        $policy = (new RunPolicyEvaluator())->evaluate('E5-001', 'governed', $references, []);
        self::assertSame('agent-loop pipeline status E5-001 --format=json', $policy->nextAction);
        self::assertFalse($policy->ordinaryCloseAllowed);

        $references['verification']['state'] = 'ready';
        $complete = (new RunPolicyEvaluator())->evaluate('E5-001', 'governed', $references, []);
        self::assertSame(RunPolicyEvaluation::KIND_COMMAND_TEMPLATE, $complete->nextActionKind);
        self::assertStringContainsString('--reviewed-report-sha256 sha256:' . str_repeat('a', 64), $complete->nextAction);
    }

    public function testMissingRecallArtifactStillRoutesToRecovery(): void
    {
        $references = $this->executionReviewReferences(
            'unacknowledged',
            'independent review pipeline is incomplete at stage investigate',
        );
        $references['recall']['state'] = 'missing';

        $policy = (new RunPolicyEvaluator())->evaluate('E5-001', 'governed', $references, []);

        self::assertSame('agent-loop enter E5-001', $policy->nextAction);
    }

    private function assertBlockedExecutionReview(RunPolicyEvaluation $policy): void
    {
        self::assertSame(RunPolicyEvaluation::KIND_HOST_WORK, $policy->nextActionKind);
        self::assertStringContainsString('resolve the blocked independent execution review', $policy->nextAction);
        self::assertStringNotContainsString('before acknowledging the Recall report', $policy->nextAction);
        self::assertStringNotContainsString('--reviewed-report-sha256', $policy->nextAction);
        self::assertStringNotContainsString('agent-loop pipeline status', $policy->nextAction);
        self::assertFalse($policy->ordinaryCloseAllowed);
    }

    /** @return array<string, array<string, mixed>> */
    private function executionReviewReferences(string $reportState, string $reason): array
    {
        $references = $this->references(
            contract: 'approved',
            approval: 'current',
            session: 'active',
            recall: 'compiled',
            executionContract: 'not_required',
            verification: 'blocked',
            review: $reportState,
            learning: 'missing',
        );
        $references['verification']['gate'] = 'execution_review';
        $references['verification']['reason'] = $reason;
        $references['review']['source'] = ['sha256' => 'sha256:' . str_repeat('a', 64)];

        return $references;
    }

    public function testFailedReviewRetainsPriorityOverOrdinaryIncompletePipeline(): void
    {
        $references = $this->references(
            contract: 'approved',
            approval: 'current',
            session: 'active',
            recall: 'compiled',
            executionContract: 'not_required',
            verification: 'blocked',
            review: 'fail',
            learning: 'missing',
        );
        $references['verification']['gate'] = 'execution_review';
        $references['verification']['reason'] = 'independent review pipeline is incomplete at stage investigate';

        $policy = (new RunPolicyEvaluator())->evaluate('E5-001', 'governed', $references, []);
        self::assertSame('agent-loop review blindspots E5-001', $policy->nextAction);
        self::assertFalse($policy->ordinaryCloseAllowed);
    }

    public function testRecallOutcomeFinishTemplatePreservesArgumentTokens(): void
    {
        $references = $this->references(
            contract: 'approved',
            approval: 'current',
            session: 'active',
            recall: 'compiled',
            executionContract: 'not_required',
            verification: 'blocked',
            review: 'ok',
            learning: 'decided',
        );
        $references['verification']['gate'] = 'recall_outcomes';
        $references['verification']['action'] = 'agent-loop finish E5-001 --recall-outcome-draft .agent-loop/recall/E5-001/recall-log.draft.json --by <actor> --commit <commit>';
        $references['verification']['recall_outcome_draft'] = '.agent-loop/recall/E5-001/recall-log.draft.json';

        $policy = (new RunPolicyEvaluator())->evaluate('E5-001', 'governed', $references, []);

        self::assertSame(RunPolicyEvaluation::KIND_COMMAND_TEMPLATE, $policy->nextActionKind);
        self::assertSame($references['verification']['action'], $policy->nextAction);
        self::assertNotNull($policy->nextActionInvocation);
        self::assertSame([
            'finish',
            'E5-001',
            '--recall-outcome-draft',
            '.agent-loop/recall/E5-001/recall-log.draft.json',
            '--by',
            '<actor>',
            '--commit',
            '<commit>',
        ], $policy->nextActionInvocation->arguments);
        self::assertTrue($policy->nextActionInvocation->template);
    }

    public function testLearningDispositionIsDelegatedAfterContractApproval(): void
    {
        $policy = (new RunPolicyEvaluator())->evaluate(
            'E5-001',
            'governed',
            $this->references(
                contract: 'approved',
                approval: 'current',
                session: 'active',
                recall: 'compiled',
                executionContract: 'not_required',
                verification: 'pending_close',
                review: 'ok',
                learning: 'missing',
            ),
            [],
        );

        self::assertSame(RunPolicyEvaluation::KIND_COMMAND_TEMPLATE, $policy->nextActionKind);
        self::assertStringContainsString('--learning <no_durable_learning|findings_recorded|follow_up_required>', $policy->nextAction);
        // A reason is optional context; the canonical step must not ask for prose.
        self::assertStringNotContainsString('--learning-reason', $policy->nextAction);
        self::assertStringContainsString('--by <actor>', $policy->nextAction);
    }

    public function testExecutableAndTerminalStepsKeepDistinctKinds(): void
    {
        $enter = (new RunPolicyEvaluator())->evaluate(
            'E5-001',
            'planned',
            $this->references(
                contract: 'approved',
                approval: 'current',
                session: 'missing',
                recall: 'missing',
                executionContract: 'not_required',
                verification: 'pending_close',
                review: 'missing',
                learning: 'unavailable',
            ),
            [],
        );
        self::assertSame(RunPolicyEvaluation::KIND_COMMAND, $enter->nextActionKind);
        self::assertSame('agent-loop enter E5-001', $enter->nextAction);

        $complete = (new RunPolicyEvaluator())->evaluate(
            'E5-001',
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
        self::assertSame(RunPolicyEvaluation::KIND_NONE, $complete->nextActionKind);
        self::assertSame('none', $complete->nextAction);
        self::assertNull($complete->nextActionInvocation);
    }

    public function testEphemeralSessionCloseKeepsArgumentsAsTokens(): void
    {
        $references = $this->references(
            contract: 'missing',
            approval: 'unavailable',
            session: 'active',
            recall: 'missing',
            executionContract: 'not_required',
            verification: 'pending_close',
            review: 'missing',
            learning: 'unavailable',
        );
        $references['session']['session_id'] = 'session-1';

        $policy = (new RunPolicyEvaluator())->evaluate('E5-001', 'ephemeral', $references, []);

        self::assertSame('agent-loop session close session-1 --status dropped', $policy->nextAction);
        self::assertNotNull($policy->nextActionInvocation);
        self::assertSame(['session', 'close', 'session-1', '--status', 'dropped'], $policy->nextActionInvocation->arguments);
        self::assertFalse($policy->nextActionInvocation->template);
    }

    public function testReviewBlindspotsUsesTypedLoopCommand(): void
    {
        $policy = (new RunPolicyEvaluator())->evaluate(
            'E5-001',
            'governed',
            $this->references(
                contract: 'approved',
                approval: 'current',
                session: 'active',
                recall: 'compiled',
                executionContract: 'not_required',
                verification: 'pending_close',
                review: 'fail',
                learning: 'decided',
            ),
            [],
        );

        self::assertSame('agent-loop review blindspots E5-001', $policy->nextAction);
        self::assertSame(['review', 'blindspots', 'E5-001'], $policy->nextActionInvocation?->arguments);
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
            'contract' => ['owner' => 'agent-loop', 'state' => $contract, 'revision' => 1],
            'approval' => ['owner' => 'agent-loop', 'state' => $approval],
            'recall' => ['owner' => 'agent-recall-compiler', 'state' => $recall],
            'execution_contract' => ['owner' => 'agent-loop', 'state' => $executionContract],
            'verification' => ['owner' => 'agent-loop', 'state' => $verification],
            'review' => ['owner' => 'agent-recall-compiler', 'state' => $review],
            'learning' => ['owner' => 'agent-learning', 'state' => $learning],
        ];
    }
}
