<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Execution\AttentionKind;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Execution\ExecutionProfileName;
use voku\AgentLoop\Execution\ExecutionProjection;
use voku\AgentLoop\Execution\ExecutionStateStore;
use voku\AgentLoop\Execution\StageCandidateObservation;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoop\Tests\Support\VerifyStageRunFixture;
use voku\AgentLoop\Workflow\WorkflowHumanDecisionService;

/**
 * A repair that keeps failing the same deterministic validation must stop for a
 * human: without a bound an unattended host loops build and validation forever.
 */
final class ExecutionValidationRepairCapTest extends TestCase
{
    private const string VALID = "<?php\nfinal class Foo { public const int X = 1; }\n";
    private const string RESPONSE = 'The missing semicolon is in the constant.';

    private VerifyStageRunFixture $fixture;
    private ExecutionGateway $gateway;

    protected function setUp(): void
    {
        $this->fixture = new VerifyStageRunFixture();
        $this->gateway = new ExecutionGateway($this->fixture->root);
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
    }

    public function testThirdConsecutiveFailureStopsForAHumanAndResumesTheMutationStage(): void
    {
        $this->fixture->reachPreReviewWith("<?php\nbroken 1\n");
        self::assertSame(3, ExecutionStateStore::MAX_VALIDATION_FAILURES);
        foreach ([2, 3] as $next) {
            $failed = $this->validate();
            self::assertNull($failed->attention);
            self::assertSame('build', $failed->currentStageId);
            $this->build('build', "<?php\nbroken $next\n", 'ctx:broken-' . $next);
        }

        $capped = $this->validate();
        $attention = $capped->attention;
        self::assertNotNull($attention);
        self::assertSame(AttentionKind::VALIDATION_CONFLICT, $attention->kind);
        self::assertSame('pre-review-validation', $attention->stageId);
        self::assertSame('pre-review-validation', $capped->currentStageId);
        self::assertStringContainsString('pre-review-validation failed 3 times in a row', $attention->message);
        self::assertStringContainsString('[FAIL] php -l src/Foo.php', $attention->message);

        $resumed = $this->resolve($attention->id);
        self::assertSame('build', $resumed->currentStageId);
        self::assertNull($resumed->attention);
        $repair = $this->gateway->prepareStage('VER-1', 'build');
        self::assertSame(self::RESPONSE, $repair->attentionResolution?->response);
        self::assertStringContainsString(self::RESPONSE, $repair->prompt);
        self::assertStringContainsString('[FAIL] php -l src/Foo.php', $repair->prompt);

        $this->build('build', self::VALID, 'ctx:fixed');
        self::assertSame('review', $this->validate()->currentStageId);
        self::assertNull($this->gateway->prepareStage('VER-1', 'review')->attentionResolution);
    }

    public function testOnlyThreeFurtherFailuresStopAgainAfterAResolution(): void
    {
        $this->fixture->reachPreReviewWith("<?php\nbroken 1\n");
        $this->failUntilCap(2);
        for ($failure = 4; $failure <= 6; ++$failure) {
            $this->build('build', "<?php\nbroken $failure\n", 'ctx:broken-' . $failure);
            $projection = $this->validate();
            self::assertSame($failure === 6, $projection->attention !== null, 'failure ' . $failure);
        }
        self::assertStringContainsString('failed 6 times in a row', (string) $projection->attention?->message);
    }

    public function testAPassEndsTheCount(): void
    {
        $this->fixture->reachPreReviewWith("<?php\nbroken 1\n");
        self::assertSame('build', $this->validate()->currentStageId);
        $this->build('build', "<?php\nbroken 2\n", 'ctx:broken-2');
        self::assertSame('build', $this->validate()->currentStageId);
        $this->build('build', self::VALID, 'ctx:fixed');
        $passed = $this->validate();
        self::assertSame('review', $passed->currentStageId);

        $this->fixture->submit($this->gateway, $this->gateway->prepareStage('VER-1', 'review'), StageOutcome::CHANGES_REQUIRED, $passed->candidateRevision, 'ctx:review');
        foreach ([3, 4] as $round) {
            $this->build('build', "<?php\nbroken $round\n", 'ctx:broken-' . $round);
            $failed = $this->validate();
            self::assertSame('build', $failed->currentStageId);
            self::assertNull($failed->attention);
        }
    }

    public function testHardenedValidationStageHasItsOwnBoundAndResumesHardening(): void
    {
        $validation = 'php -r "exit(str_contains(file_get_contents(\'src/Foo.php\'), \'X = 1\') ? 0 : 5);"';
        $this->fixture->reachPreReviewWith(self::VALID, [$validation], profile: ExecutionProfileName::HARDENED);
        $this->validate();
        $candidate = $this->gateway->projection('VER-1')->candidateRevision;
        foreach (['correctness-review', 'architecture-review'] as $review) {
            $this->fixture->submit($this->gateway, $this->gateway->prepareStage('VER-1', $review), StageOutcome::PASS, $candidate, 'ctx:' . $review);
        }
        for ($failure = 1; $failure <= 3; ++$failure) {
            $this->build('hardening', "<?php\nfinal class Foo { public const int X = " . ($failure + 1) . "; }\n", 'ctx:hardening-' . $failure);
            $projection = $this->validate('pre-independent-validation');
        }
        self::assertNotNull($projection->attention);
        self::assertSame(AttentionKind::VALIDATION_CONFLICT, $projection->attention->kind);
        self::assertSame('hardening', $this->resolve($projection->attention->id)->currentStageId);
        self::assertSame(self::RESPONSE, $this->gateway->prepareStage('VER-1', 'hardening')->attentionResolution?->response);
    }

    public function testACappedReviewResponseStaysPendingAcrossACappedValidation(): void
    {
        $this->fixture->reachPreReviewWith(self::VALID, profile: ExecutionProfileName::STANDARD);
        $this->validate();
        for ($round = 1; $round <= ExecutionStateStore::MAX_REVIEW_ROUNDS; ++$round) {
            $candidate = $this->gateway->projection('VER-1')->candidateRevision;
            $this->fixture->submit($this->gateway, $this->gateway->prepareStage('VER-1', 'correctness-review'), StageOutcome::PASS, $candidate, 'ctx:correctness-' . $round);
            $this->fixture->submit($this->gateway, $this->gateway->prepareStage('VER-1', 'blindspot-review'), StageOutcome::CHANGES_REQUIRED, $candidate, 'ctx:blindspot-' . $round);
            if ($round < ExecutionStateStore::MAX_REVIEW_ROUNDS) {
                $this->build('build', "<?php\nfinal class Foo { public int \$round = $round; }\n", 'ctx:build-' . $round);
                $this->validate();
            }
        }
        $reviewAttention = $this->gateway->projection('VER-1')->attention;
        self::assertNotNull($reviewAttention);
        $this->resolve($reviewAttention->id, 'Keep the reviewed behavior.');

        for ($failure = 1; $failure <= 3; ++$failure) {
            $this->build('build', "<?php\nbroken $failure\n", 'ctx:broken-' . $failure);
            $projection = $this->validate();
        }
        self::assertNotNull($projection->attention);
        $this->resolve($projection->attention->id);
        $repair = $this->gateway->prepareStage('VER-1', 'build');
        self::assertStringContainsString(self::RESPONSE, $repair->prompt);
        self::assertStringContainsString('Keep the reviewed behavior.', $repair->prompt);

        $this->build('build', "<?php\nfinal class Foo { public int \$round = 9; }\n", 'ctx:fixed');
        $candidate = $this->validate()->candidateRevision;
        self::assertNull($this->gateway->prepareStage('VER-1', 'correctness-review')->attentionResolution);
        $this->fixture->submit($this->gateway, $this->gateway->prepareStage('VER-1', 'correctness-review'), StageOutcome::PASS, $candidate, 'ctx:correctness-last');
        self::assertSame('Keep the reviewed behavior.', $this->gateway->prepareStage('VER-1', 'blindspot-review')->attentionResolution?->response);
    }

    public function testValidationDecisionSurvivesNestedBuildClarificationUntilValidationPasses(): void
    {
        $this->fixture->reachPreReviewWith("<?php\nbroken 1\n");
        $this->failUntilCap(2);

        $firstRepair = $this->gateway->prepareStage('VER-1', 'build');
        self::assertSame(self::RESPONSE, $firstRepair->attentionResolution?->response);

        // Reviewer-observed regression #1167: the resumed build itself needs
        // clarification. Resolving it must not discard the earlier answer.
        $this->fixture->submit(
            $this->gateway,
            $firstRepair,
            StageOutcome::NEEDS_CLARIFICATION,
            $firstRepair->candidateRevision,
            'ctx:needs-clarification',
        );
        $attention = $this->gateway->projection('VER-1')->attention;
        self::assertNotNull($attention);
        self::assertSame(AttentionKind::CLARIFICATION_REQUIRED, $attention->kind);
        $this->resolve($attention->id, 'Proceed with the isolated repair.');

        $resumed = $this->gateway->prepareStage('VER-1', 'build');
        self::assertSame('Proceed with the isolated repair.', $resumed->attentionResolution?->response);
        self::assertCount(1, $resumed->routedValidationAttentionResolutions);
        self::assertSame(self::RESPONSE, $resumed->routedValidationAttentionResolutions[0]->response);
        self::assertStringContainsString(self::RESPONSE, $resumed->prompt);

        $state = (new ExecutionStateStore($this->fixture->root))->find('VER-1');
        self::assertNotNull($state);
        self::assertCount(1, $state->routedValidationAttentionIds);

        $this->build('build', self::VALID, 'ctx:repair-complete');
        self::assertSame('review', $this->validate()->currentStageId);
        $state = (new ExecutionStateStore($this->fixture->root))->find('VER-1');
        self::assertNotNull($state);
        self::assertSame([], $state->routedValidationAttentionIds);
    }

    public function testPreviousValidationResponseSurvivesAnotherCappedFailure(): void
    {
        $this->fixture->reachPreReviewWith("<?php\nbroken 1\n");
        $this->failUntilCap(2);
        $firstState = (new ExecutionStateStore($this->fixture->root))->find('VER-1');
        self::assertNotNull($firstState);
        self::assertCount(1, $firstState->routedValidationAttentionIds);

        foreach ([4, 5, 6] as $failure) {
            $this->build('build', "<?php\nbroken $failure\n", 'ctx:broken-' . $failure);
            $projection = $this->validate();
        }
        self::assertNotNull($projection->attention);
        self::assertSame(AttentionKind::VALIDATION_CONFLICT, $projection->attention->kind);
        $this->resolve($projection->attention->id, 'Try a different repair, preserving the original decision.');

        $repair = $this->gateway->prepareStage('VER-1', 'build');
        self::assertCount(1, $repair->routedValidationAttentionResolutions);
        self::assertSame(self::RESPONSE, $repair->routedValidationAttentionResolutions[0]->response);
        self::assertSame('Try a different repair, preserving the original decision.', $repair->attentionResolution?->response);
        self::assertStringContainsString(self::RESPONSE, $repair->prompt);

        $this->build('build', self::VALID, 'ctx:validated');
        self::assertSame('review', $this->validate()->currentStageId);
        $finalState = (new ExecutionStateStore($this->fixture->root))->find('VER-1');
        self::assertNotNull($finalState);
        self::assertSame([], $finalState->routedValidationAttentionIds);
    }

    private function failUntilCap(int $firstNext): void
    {
        for ($next = $firstNext; $next <= 3; ++$next) {
            $this->validate();
            $this->build('build', "<?php\nbroken $next\n", 'ctx:broken-' . $next);
        }
        $attention = $this->validate()->attention;
        self::assertNotNull($attention);
        $this->resolve($attention->id);
    }

    private function validate(string $stage = 'pre-review-validation'): ExecutionProjection
    {
        return $this->gateway->runDeterministicStage('VER-1', $stage, $this->fixture->workspace);
    }

    private function resolve(string $attentionId, string $response = self::RESPONSE): ExecutionProjection
    {
        return (new WorkflowHumanDecisionService($this->fixture->root))->resolveAttention('VER-1', $attentionId, 'lars', $response);
    }

    private function build(string $stageId, string $source, string $context): void
    {
        file_put_contents($this->fixture->workspace . '/src/Foo.php', $source);
        $stage = $this->gateway->prepareStage('VER-1', $stageId);
        $candidate = $this->fixture->candidate();
        $this->gateway->recordStageCandidate(new StageCandidateObservation(
            $stage->taskId,
            $stage->runId,
            $stage->contractRevision,
            $stage->executionPlanDigest,
            $stage->stageId,
            $stage->attempt,
            $stage->candidateRevision,
            $candidate,
        ));
        $this->fixture->submit($this->gateway, $stage, StageOutcome::COMPLETED, $candidate, $context);
    }
}
