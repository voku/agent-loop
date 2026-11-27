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
        $references = $this->references(
            contract: 'approved',
            approval: 'current',
            session: 'active',
            recall: 'compiled',
            executionContract: 'not_required',
            verification: 'blocked',
            review: 'unacknowledged',
            learning: 'missing',
        );
        $references['verification']['gate'] = 'execution_review';
        $references['verification']['reason'] = 'independent review pipeline is incomplete at stage investigate';
        $references['review']['source'] = ['sha256' => 'sha256:' . str_repeat('a', 64)];

        $evaluator = new RunPolicyEvaluator();
        $ordinary = $evaluator->evaluate('E5-001', 'governed', $references, []);
        self::assertSame('agent-loop pipeline status E5-001 --format=json', $ordinary->nextAction);
        self::assertSame(RunPolicyEvaluation::KIND_COMMAND, $ordinary->nextActionKind);
        self::assertFalse($ordinary->ordinaryCloseAllowed);

        $references['verification']['reason'] = 'semantic review stage correctness-review has no accepted PASS result';
        $terminalSemanticReviewFailure = $evaluator->evaluate('E5-001', 'governed', $references, []);
        self::assertSame(RunPolicyEvaluation::KIND_HOST_WORK, $terminalSemanticReviewFailure->nextActionKind);
        self::assertStringContainsString('resolve the blocked independent execution review', $terminalSemanticReviewFailure->nextAction);
        self::assertStringNotContainsString('--reviewed-report-sha256', $terminalSemanticReviewFailure->nextAction);
        self::assertStringNotContainsString('agent-loop pipeline status', $terminalSemanticReviewFailure->nextAction);
        self::assertFalse($terminalSemanticReviewFailure->ordinaryCloseAllowed);

        foreach ([
            'semantic review stage correctness-review is stale for the final candidate',
            'semantic review stage correctness-review has no fresh observed context identity',
            'semantic review stage correctness-review cannot prove isolation from predecessor build',
            'semantic review stage correctness-review has stale review evidence: evidence is stale',
            'selected non-manual profile has no semantic review stage after its final mutating stage',
        ] as $terminalReviewReason) {
            $references['verification']['reason'] = $terminalReviewReason;
            $terminalReviewFailure = $evaluator->evaluate('E5-001', 'governed', $references, []);
            self::assertSame(RunPolicyEvaluation::KIND_HOST_WORK, $terminalReviewFailure->nextActionKind);
            self::assertStringNotContainsString('--reviewed-report-sha256', $terminalReviewFailure->nextAction);
            self::assertStringNotContainsString('agent-loop pipeline status', $terminalReviewFailure->nextAction);
            self::assertFalse($terminalReviewFailure->ordinaryCloseAllowed);
        }

        foreach (['ok', 'warn'] as $acknowledgedReportState) {
            $references['review']['state'] = $acknowledgedReportState;
            $references['verification']['reason'] = 'semantic review stage correctness-review has no accepted PASS result';
            $terminalReviewFailure = $evaluator->evaluate('E5-001', 'governed', $references, []);
            self::assertSame(RunPolicyEvaluation::KIND_HOST_WORK, $terminalReviewFailure->nextActionKind);
            self::assertStringContainsString('resolve the blocked independent execution review', $terminalReviewFailure->nextAction);
            self::assertStringNotContainsString('--reviewed-report-sha256', $terminalReviewFailure->nextAction);
            self::assertStringNotContainsString('agent-loop pipeline status', $terminalReviewFailure->nextAction);
            self::assertFalse($terminalReviewFailure->ordinaryCloseAllowed);
        }

        $references['review']['state'] = 'unacknowledged';
        $references['verification']['reason'] = 'semantic review stage correctness-review has no accepted PASS result';
        foreach (['missing', 'invalid', 'stale'] as $reportState) {
            $references['review']['state'] = $reportState;
            $terminalReviewFailure = $evaluator->evaluate('E5-001', 'governed', $references, []);
            self::assertSame(RunPolicyEvaluation::KIND_HOST_WORK, $terminalReviewFailure->nextActionKind);
            self::assertStringContainsString('resolve the blocked independent execution review', $terminalReviewFailure->nextAction);
            self::assertStringNotContainsString('--reviewed-report-sha256', $terminalReviewFailure->nextAction);
            self::assertFalse($terminalReviewFailure->ordinaryCloseAllowed);
        }
        $references['review']['state'] = 'unacknowledged';

        $references['verification']['reason'] = 'independent review pipeline has not started';
        $notStarted = $evaluator->evaluate('E5-001', 'governed', $references, []);
        self::assertSame('agent-loop pipeline status E5-001 --format=json', $notStarted->nextAction);

        foreach (['ok', 'warn'] as $acknowledgedReportState) {
            $references['review']['state'] = $acknowledgedReportState;
            $ordinaryPending = $evaluator->evaluate('E5-001', 'governed', $references, []);
            self::assertSame('agent-loop pipeline status E5-001 --format=json', $ordinaryPending->nextAction);
            self::assertFalse($ordinaryPending->ordinaryCloseAllowed);
        }
        $references['review']['state'] = 'unacknowledged';

        $references['recall']['state'] = 'missing';
        $recallRecovery = $evaluator->evaluate('E5-001', 'governed', $references, []);
        self::assertSame('agent-loop enter E5-001', $recallRecovery->nextAction);
        $references['recall']['state'] = 'compiled';

        $references['review']['state'] = 'stale';
        $unavailableReport = $evaluator->evaluate('E5-001', 'governed', $references, []);
        self::assertSame('agent-loop pipeline status E5-001 --format=json', $unavailableReport->nextAction);
        self::assertSame(RunPolicyEvaluation::KIND_COMMAND, $unavailableReport->nextActionKind);

        $references['review']['state'] = 'unacknowledged';
        $references['verification']['reason'] = 'independent review pipeline is incomplete at stage investigate';

        foreach (['missing', 'invalid', 'stale'] as $reportState) {
            $references['review']['state'] = $reportState;
            $unavailableReport = $evaluator->evaluate('E5-001', 'governed', $references, []);
            self::assertSame('agent-loop pipeline status E5-001 --format=json', $unavailableReport->nextAction);
            self::assertSame(RunPolicyEvaluation::KIND_COMMAND, $unavailableReport->nextActionKind);
            self::assertStringNotContainsString('--reviewed-report-sha256', $unavailableReport->nextAction);
            self::assertFalse($unavailableReport->ordinaryCloseAllowed);
        }

        $references['review']['state'] = 'unacknowledged';
        $references['verification']['reason'] = 'recovered execution pipeline: review is incomplete';
        $recovered = $evaluator->evaluate('E5-001', 'governed', $references, []);
        self::assertSame($ordinary->nextAction, $recovered->nextAction);
        self::assertFalse($recovered->ordinaryCloseAllowed);

        $references['verification']['state'] = 'ready';
        $complete = $evaluator->evaluate('E5-001', 'governed', $references, []);
        self::assertSame(RunPolicyEvaluation::KIND_COMMAND_TEMPLATE, $complete->nextActionKind);
        self::assertStringContainsString('--reviewed-report-sha256 sha256:' . str_repeat('a', 64), $complete->nextAction);
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
        $references['verification']['reason'] = 'independent review pipeline is incomplete';

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
