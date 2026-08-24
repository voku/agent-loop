<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Execution\ExecutionEvidenceClaim;
use voku\AgentLoop\Execution\ExecutionEvidenceKind;
use voku\AgentLoop\Execution\ExecutionEvidenceStore;
use voku\AgentLoop\Execution\ExecutionPlan;
use voku\AgentLoop\Execution\ExecutionProfileName;
use voku\AgentLoop\Execution\ExecutionRole;
use voku\AgentLoop\Execution\ExecutionStage;
use voku\AgentLoop\Execution\ExecutionStageKind;
use voku\AgentLoop\Execution\ExecutionStageResultAuthority;
use voku\AgentLoop\Execution\ExecutionState;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoop\Execution\StageResult;

final class ExecutionEvidenceAuthorityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-evidence-authority-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    /** @return iterable<string, array{non-empty-string, int|non-empty-string}> */
    public static function staleBindings(): iterable
    {
        yield 'run' => ['runId', 'run:other'];
        yield 'contract revision' => ['contractRevision', 2];
        yield 'plan' => ['executionPlanDigest', 'sha256:' . str_repeat('f', 64)];
        yield 'stage' => ['stageId', 'other-stage'];
        yield 'attempt' => ['attempt', 2];
        yield 'candidate' => ['candidateRevision', 'candidate:other'];
    }

    #[DataProvider('staleBindings')]
    public function testArtifactEvidenceFromAnotherAuthorityBindingFailsClosed(string $field, int|string $value): void
    {
        $plan = $this->plan();
        $state = $this->state($plan);
        $runId = $field === 'runId' ? (string) $value : $plan->runId;
        $contractRevision = $field === 'contractRevision' ? (int) $value : $plan->contractRevision;
        $executionPlanDigest = $field === 'executionPlanDigest' ? (string) $value : $plan->digest();
        $stageId = $field === 'stageId' ? (string) $value : 'review';
        $attempt = $field === 'attempt' ? (int) $value : 1;
        $candidateRevision = $field === 'candidateRevision' ? (string) $value : $state->candidateRevision;
        $claim = new ExecutionEvidenceClaim(
            $plan->taskId,
            $runId,
            $contractRevision,
            $executionPlanDigest,
            $stageId,
            $attempt,
            $candidateRevision,
            ExecutionEvidenceKind::ARTIFACT,
            'artifact:report',
            'sha256:' . hash('sha256', 'artifact:report'),
        );
        $reference = (new ExecutionEvidenceStore($this->root))->record($claim);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('STALE_EVIDENCE');
        (new ExecutionStageResultAuthority($this->root))->assertAcceptable(
            $plan,
            $state,
            $plan->stage('review'),
            $this->stageResult($plan, $state, [$reference], []),
        );
    }

    public function testArbitraryModelArtifactReferenceFailsClosed(): void
    {
        $plan = $this->plan();
        $state = $this->state($plan);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MISSING_EVIDENCE');
        (new ExecutionStageResultAuthority($this->root))->assertAcceptable(
            $plan,
            $state,
            $plan->stage('review'),
            $this->stageResult($plan, $state, ['model:claimed-artifact'], []),
        );
    }

    public function testArbitraryModelValidationReferenceFailsClosed(): void
    {
        $plan = $this->plan();
        $state = $this->state($plan);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MISSING_EVIDENCE');
        (new ExecutionStageResultAuthority($this->root))->assertAcceptable(
            $plan,
            $state,
            $plan->stage('review'),
            $this->stageResult($plan, $state, [], ['model:tests-pass']),
        );
    }

    public function testCurrentOwnerArtifactEvidenceIsAccepted(): void
    {
        $plan = $this->plan();
        $state = $this->state($plan);
        $reference = (new ExecutionEvidenceStore($this->root))->record(new ExecutionEvidenceClaim(
            $plan->taskId,
            $plan->runId,
            $plan->contractRevision,
            $plan->digest(),
            'review',
            1,
            $state->candidateRevision,
            ExecutionEvidenceKind::ARTIFACT,
            'artifact:report',
            'sha256:' . hash('sha256', 'artifact:report'),
        ));

        (new ExecutionStageResultAuthority($this->root))->assertAcceptable(
            $plan,
            $state,
            $plan->stage('review'),
            $this->stageResult($plan, $state, [$reference], []),
        );
        self::addToAssertionCount(1);
    }

    private function plan(): ExecutionPlan
    {
        $role = new ExecutionRole('reviewer', false, []);
        $stage = new ExecutionStage(
            'review',
            ExecutionStageKind::AGENT,
            'reviewer',
            false,
            [],
            [StageOutcome::PASS->value => null],
        );

        return new ExecutionPlan(
            'ABC-123',
            'run:evidence-authority',
            1,
            ['path' => '.agent-loop/contracts/ABC-123.json', 'sha256' => 'sha256:' . str_repeat('1', 64)],
            str_repeat('2', 40),
            ExecutionProfileName::SURGICAL,
            [$role],
            [$stage],
            '2026-08-24T00:00:00+00:00',
        );
    }

    private function state(ExecutionPlan $plan): ExecutionState
    {
        return new ExecutionState(
            $plan->taskId,
            $plan->runId,
            $plan->contractRevision,
            $plan->digest(),
            'review',
            1,
            (string) $plan->baseCommit,
            null,
            [],
        );
    }

    /**
     * @param list<non-empty-string> $artifactReferences
     * @param list<non-empty-string> $validationReferences
     */
    private function stageResult(
        ExecutionPlan $plan,
        ExecutionState $state,
        array $artifactReferences,
        array $validationReferences,
    ): StageResult {
        return new StageResult(
            'submission:evidence',
            $plan->taskId,
            $plan->runId,
            $plan->contractRevision,
            $plan->digest(),
            'review',
            1,
            StageOutcome::PASS,
            $state->candidateRevision,
            $artifactReferences,
            $validationReferences,
            'Review complete.',
        );
    }

    private function rm(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
