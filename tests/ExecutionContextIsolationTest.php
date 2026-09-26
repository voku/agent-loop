<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Execution\AcceptedStageResult;
use voku\AgentLoop\Execution\ExecutionContextPolicy;
use voku\AgentLoop\Execution\ExecutionPlan;
use voku\AgentLoop\Execution\ExecutionProfile;
use voku\AgentLoop\Execution\ExecutionProfileName;
use voku\AgentLoop\Execution\ExecutionRole;
use voku\AgentLoop\Execution\ExecutionStage;
use voku\AgentLoop\Execution\ExecutionStageKind;
use voku\AgentLoop\Execution\ExecutionStageResultAuthority;
use voku\AgentLoop\Execution\ExecutionState;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoop\Execution\StageResult;

final class ExecutionContextIsolationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-context-isolation-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testFirstPartyIndependentReviewStagesRequireFreshContext(): void
    {
        $surgical = ExecutionProfile::firstParty(ExecutionProfileName::SURGICAL);
        self::assertSame(
            ExecutionContextPolicy::FRESH_REQUIRED,
            $this->profileStage($surgical->stages, 'review')->contextPolicy,
        );

        $standard = ExecutionProfile::firstParty(ExecutionProfileName::STANDARD);
        foreach (['correctness-review', 'blindspot-review'] as $stageId) {
            self::assertSame(
                ExecutionContextPolicy::FRESH_REQUIRED,
                $this->profileStage($standard->stages, $stageId)->contextPolicy,
                $stageId,
            );
        }

        $hardened = ExecutionProfile::firstParty(ExecutionProfileName::HARDENED);
        foreach (['correctness-review', 'architecture-review', 'independent-verification', 'blindspot-review'] as $stageId) {
            self::assertSame(
                ExecutionContextPolicy::FRESH_REQUIRED,
                $this->profileStage($hardened->stages, $stageId)->contextPolicy,
                $stageId,
            );
        }
    }

    public function testFreshReviewRejectsTheBuilderContext(): void
    {
        [$plan, $state] = $this->reviewState('ctx:builder');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CONTEXT_REUSE_FORBIDDEN');

        (new ExecutionStageResultAuthority($this->root))->assertAcceptable(
            $plan,
            $state,
            $plan->stage('review'),
            $this->reviewResult($plan, $state, 'ctx:builder'),
        );
    }

    public function testFreshReviewAcceptsDistinctContext(): void
    {
        [$plan, $state] = $this->reviewState('ctx:builder');

        (new ExecutionStageResultAuthority($this->root))->assertAcceptable(
            $plan,
            $state,
            $plan->stage('review'),
            $this->reviewResult($plan, $state, 'ctx:reviewer'),
        );

        self::addToAssertionCount(1);
    }

    public function testPredecessorMustReportContextBeforeFreshReviewCanBeProven(): void
    {
        $plan = $this->plan();
        $state = new ExecutionState(
            $plan->taskId,
            $plan->runId,
            $plan->contractRevision,
            $plan->digest(),
            'build',
            1,
            (string) $plan->baseCommit,
            null,
            [],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MISSING_CONTEXT_EVIDENCE');

        (new ExecutionStageResultAuthority($this->root))->assertAcceptable(
            $plan,
            $state,
            $plan->stage('build'),
            new StageResult(
                'submission:build',
                $plan->taskId,
                $plan->runId,
                $plan->contractRevision,
                $plan->digest(),
                'build',
                1,
                StageOutcome::COMPLETED,
                $state->candidateRevision,
                [],
                [],
                'Build complete.',
            ),
        );
    }

    public function testFreshReviewRequiresItsOwnContextIdentity(): void
    {
        [$plan, $state] = $this->reviewState('ctx:builder');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MISSING_CONTEXT_EVIDENCE');

        (new ExecutionStageResultAuthority($this->root))->assertAcceptable(
            $plan,
            $state,
            $plan->stage('review'),
            new StageResult(
                'submission:review',
                $plan->taskId,
                $plan->runId,
                $plan->contractRevision,
                $plan->digest(),
                'review',
                1,
                StageOutcome::PASS,
                $state->candidateRevision,
                [],
                [],
                'Review complete.',
            ),
        );
    }

    public function testStandaloneReuseAllowedStageDoesNotRequireContextLineage(): void
    {
        $role = new ExecutionRole('investigator', false, []);
        $stage = new ExecutionStage(
            'investigate',
            ExecutionStageKind::AGENT,
            'investigator',
            false,
            [],
            [StageOutcome::COMPLETED->value => null],
        );
        $plan = new ExecutionPlan(
            'ABC-123',
            'run:standalone',
            1,
            ['path' => '.agent-loop/contracts/ABC-123.json', 'sha256' => 'sha256:' . str_repeat('1', 64)],
            str_repeat('2', 40),
            ExecutionProfileName::SURGICAL,
            [$role],
            [$stage],
            '2026-09-26T00:00:00+00:00',
        );
        $state = new ExecutionState(
            $plan->taskId,
            $plan->runId,
            $plan->contractRevision,
            $plan->digest(),
            'investigate',
            1,
            (string) $plan->baseCommit,
            null,
            [],
        );

        (new ExecutionStageResultAuthority($this->root))->assertAcceptable(
            $plan,
            $state,
            $stage,
            new StageResult(
                'submission:investigate',
                $plan->taskId,
                $plan->runId,
                $plan->contractRevision,
                $plan->digest(),
                'investigate',
                1,
                StageOutcome::COMPLETED,
                $state->candidateRevision,
                [],
                [],
                'Investigation complete.',
            ),
        );

        self::addToAssertionCount(1);
    }

    /**
     * @return array{ExecutionPlan, ExecutionState}
     */
    private function reviewState(?string $builderContextId): array
    {
        $plan = $this->plan();
        $builder = new StageResult(
            'submission:build',
            $plan->taskId,
            $plan->runId,
            $plan->contractRevision,
            $plan->digest(),
            'build',
            1,
            StageOutcome::COMPLETED,
            (string) $plan->baseCommit,
            [],
            [],
            'Build complete.',
            $builderContextId,
        );
        $state = new ExecutionState(
            $plan->taskId,
            $plan->runId,
            $plan->contractRevision,
            $plan->digest(),
            'review',
            1,
            (string) $plan->baseCommit,
            null,
            [new AcceptedStageResult($builder, null, '2026-09-26T00:01:00+00:00')],
        );

        return [$plan, $state];
    }

    private function plan(): ExecutionPlan
    {
        $builder = new ExecutionRole('builder', true, []);
        $reviewer = new ExecutionRole('reviewer', false, []);
        $build = new ExecutionStage(
            'build',
            ExecutionStageKind::AGENT,
            'builder',
            true,
            [],
            [StageOutcome::COMPLETED->value => 'review'],
        );
        $review = new ExecutionStage(
            'review',
            ExecutionStageKind::AGENT,
            'reviewer',
            false,
            ['build'],
            [StageOutcome::PASS->value => null],
            ExecutionContextPolicy::FRESH_REQUIRED,
        );

        return new ExecutionPlan(
            'ABC-123',
            'run:context-isolation',
            1,
            ['path' => '.agent-loop/contracts/ABC-123.json', 'sha256' => 'sha256:' . str_repeat('1', 64)],
            str_repeat('2', 40),
            ExecutionProfileName::SURGICAL,
            [$builder, $reviewer],
            [$build, $review],
            '2026-09-26T00:00:00+00:00',
        );
    }

    private function reviewResult(ExecutionPlan $plan, ExecutionState $state, string $contextId): StageResult
    {
        return new StageResult(
            'submission:review',
            $plan->taskId,
            $plan->runId,
            $plan->contractRevision,
            $plan->digest(),
            'review',
            1,
            StageOutcome::PASS,
            $state->candidateRevision,
            [],
            [],
            'Review complete.',
            $contextId,
        );
    }

    /**
     * @param list<ExecutionStage> $stages
     */
    private function profileStage(array $stages, string $stageId): ExecutionStage
    {
        foreach ($stages as $stage) {
            if ($stage->id === $stageId) {
                return $stage;
            }
        }

        self::fail('Missing stage ' . $stageId . '.');
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
