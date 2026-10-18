<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Execution\ExecutionEvidenceClaim;
use voku\AgentLoop\Execution\ExecutionEvidenceKind;
use voku\AgentLoop\Execution\ExecutionEvidenceStore;
use voku\AgentLoop\Execution\ExecutionPlan;
use voku\AgentLoop\Execution\ExecutionProfileName;
use voku\AgentLoop\Execution\ExecutionStage;
use voku\AgentLoop\Execution\ExecutionStageKind;
use voku\AgentLoop\Execution\ExecutionStageResultAuthority;
use voku\AgentLoop\Execution\ExecutionState;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoop\Execution\StageResult;

final class ExecutionConsistencyEvidenceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-consistency-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testDeterministicPassWithoutConsistencyReceiptFailsClosed(): void
    {
        [$plan, $state] = $this->context();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MISSING_EVIDENCE');
        (new ExecutionStageResultAuthority($this->root))->assertAcceptable(
            $plan,
            $state,
            $plan->stage('verify'),
            $this->stageResult($plan, $state),
        );
    }

    public function testCurrentConsistencyReceiptAllowsPassWithoutClaimingValidation(): void
    {
        [$plan, $state] = $this->context();
        $reference = $this->record($plan, $state, ExecutionEvidenceKind::CONSISTENCY);
        $result = $this->stageResult($plan, $state, [], [$reference]);

        (new ExecutionStageResultAuthority($this->root))->assertAcceptable(
            $plan,
            $state,
            $plan->stage('verify'),
            $result,
        );
        self::assertSame([], $result->validationReferences);
        self::assertSame([$reference], $result->toArray()['consistency_references']);
    }

    public function testOldValidationReceiptCannotStandInForConsistencyProof(): void
    {
        [$plan, $state] = $this->context();
        $reference = $this->record($plan, $state, ExecutionEvidenceKind::VALIDATION);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EVIDENCE_MISMATCH');
        (new ExecutionStageResultAuthority($this->root))->assertAcceptable(
            $plan,
            $state,
            $plan->stage('verify'),
            $this->stageResult($plan, $state, [$reference]),
        );
    }

    public function testDeterministicPassCannotMixConsistencyWithCandidateValidationEvidence(): void
    {
        [$plan, $state] = $this->context();
        $consistency = $this->record($plan, $state, ExecutionEvidenceKind::CONSISTENCY);
        $validation = $this->record($plan, $state, ExecutionEvidenceKind::VALIDATION);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EVIDENCE_MISMATCH: deterministic consistency checks cannot claim candidate validation');
        (new ExecutionStageResultAuthority($this->root))->assertAcceptable(
            $plan,
            $state,
            $plan->stage('verify'),
            $this->stageResult($plan, $state, [$validation], [$consistency]),
        );
    }

    public function testDeterministicPassRejectsDuplicateConsistencyReferences(): void
    {
        [$plan, $state] = $this->context();
        $reference = $this->record($plan, $state, ExecutionEvidenceKind::CONSISTENCY);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MISSING_EVIDENCE: deterministic verification requires one current owner consistency receipt');
        (new ExecutionStageResultAuthority($this->root))->assertAcceptable(
            $plan,
            $state,
            $plan->stage('verify'),
            $this->stageResult($plan, $state, [], [$reference, $reference]),
        );
    }

    public function testConsistencyReceiptFromAnotherAttemptFailsClosed(): void
    {
        [$plan, $state] = $this->context();
        $claim = new ExecutionEvidenceClaim(
            $plan->taskId,
            $plan->runId,
            $plan->contractRevision,
            $plan->digest(),
            'verify',
            2,
            $state->candidateRevision,
            ExecutionEvidenceKind::CONSISTENCY,
            'agent-loop verify --task-id=' . $plan->taskId,
            'sha256:' . hash('sha256', 'stale'),
        );
        $reference = (new ExecutionEvidenceStore($this->root))->record($claim);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('STALE_EVIDENCE');
        (new ExecutionStageResultAuthority($this->root))->assertAcceptable(
            $plan,
            $state,
            $plan->stage('verify'),
            $this->stageResult($plan, $state, [], [$reference]),
        );
    }

    /** @return array{ExecutionPlan, ExecutionState} */
    private function context(): array
    {
        $plan = new ExecutionPlan(
            'TEST-799',
            'run:TEST-799',
            1,
            ['path' => '.agent-loop/contracts/TEST-799.json', 'sha256' => 'sha256:' . str_repeat('1', 64)],
            str_repeat('2', 40),
            ExecutionProfileName::SURGICAL,
            [],
            [new ExecutionStage('verify', ExecutionStageKind::DETERMINISTIC, null, false, [], [StageOutcome::PASS->value => null])],
            '2026-10-18T00:00:00+00:00',
        );
        $state = new ExecutionState(
            $plan->taskId,
            $plan->runId,
            $plan->contractRevision,
            $plan->digest(),
            'verify',
            1,
            (string) $plan->baseCommit,
            null,
            [],
        );

        return [$plan, $state];
    }

    /** @return non-empty-string */
    private function record(ExecutionPlan $plan, ExecutionState $state, ExecutionEvidenceKind $kind): string
    {
        return (new ExecutionEvidenceStore($this->root))->record(new ExecutionEvidenceClaim(
            $plan->taskId,
            $plan->runId,
            $plan->contractRevision,
            $plan->digest(),
            'verify',
            1,
            $state->candidateRevision,
            $kind,
            'agent-loop verify --task-id=' . $plan->taskId,
            'sha256:' . hash('sha256', 'observed'),
        ));
    }

    /**
     * @param list<non-empty-string> $validations
     * @param list<non-empty-string> $consistency
     */
    private function stageResult(ExecutionPlan $plan, ExecutionState $state, array $validations = [], array $consistency = []): StageResult
    {
        return new StageResult(
            'submission:verify',
            $plan->taskId,
            $plan->runId,
            $plan->contractRevision,
            $plan->digest(),
            'verify',
            1,
            StageOutcome::PASS,
            $state->candidateRevision,
            [],
            $validations,
            'Owner consistency check passed.',
            null,
            $consistency,
        );
    }
}
