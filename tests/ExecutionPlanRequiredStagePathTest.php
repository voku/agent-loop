<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Execution\ExecutionPlan;
use voku\AgentLoop\Execution\ExecutionProfile;
use voku\AgentLoop\Execution\ExecutionProfileName;
use voku\AgentLoop\Execution\ExecutionRole;
use voku\AgentLoop\Execution\ExecutionStage;
use voku\AgentLoop\Execution\ExecutionStageKind;
use voku\AgentLoop\Execution\StageOutcome;

/**
 * A dependency must have executed on every route to its consumer, including review-feedback routes.
 *
 * @internal
 */
final class ExecutionPlanRequiredStagePathTest extends TestCase
{
    public function testRejectsAReachableStageThatBypassesRequiredWork(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Execution stage review can be reached without required stage build.');

        $this->plan([
            $this->stage('investigate', [], [StageOutcome::COMPLETED->value => 'review']),
            $this->stage('build', ['investigate'], [StageOutcome::COMPLETED->value => 'review']),
            $this->stage('review', ['build'], [StageOutcome::PASS->value => null]),
        ]);
    }

    public function testRejectsAStageRequiringItself(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Execution stage build cannot require itself.');

        $this->plan([
            $this->stage('build', ['build'], [StageOutcome::COMPLETED->value => null]),
        ]);
    }

    public function testRejectsARequiredStageThatOnlyRunsAfterItsConsumer(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Execution stage investigate can be reached without required stage build.');

        $this->plan([
            $this->stage('investigate', ['build'], [StageOutcome::COMPLETED->value => 'build']),
            $this->stage('build', [], [StageOutcome::COMPLETED->value => null]),
        ]);
    }

    public function testAcceptsAReviewFeedbackLoopAfterItsRequiredWork(): void
    {
        $plan = $this->plan([
            $this->stage('investigate', [], [StageOutcome::COMPLETED->value => 'build']),
            $this->stage('build', ['investigate'], [StageOutcome::COMPLETED->value => 'review']),
            $this->stage('review', ['build'], [
                StageOutcome::PASS->value => 'verify',
                StageOutcome::CHANGES_REQUIRED->value => 'build',
            ]),
            $this->stage('verify', ['review'], [StageOutcome::PASS->value => null]),
        ]);

        self::assertSame('investigate', $plan->firstStageId());
        self::assertCount(4, $plan->stages);
    }

    public function testAllFirstPartyExecutionProfilesRetainValidDependencyPaths(): void
    {
        foreach (ExecutionProfileName::cases() as $name) {
            $plan = ExecutionPlan::resolve(
                ExecutionProfile::firstParty($name),
                'TEST-PATH',
                'run:TEST-PATH',
                1,
                ['path' => 'contract.json', 'sha256' => 'sha256:' . str_repeat('a', 64)],
                null,
                '2026-11-07T00:00:00+00:00',
            );

            self::assertSame($name, $plan->profile);
        }
    }

    /**
     * @param list<ExecutionStage> $stages
     */
    private function plan(array $stages): ExecutionPlan
    {
        return new ExecutionPlan(
            'TEST-PATH',
            'run:TEST-PATH',
            1,
            ['path' => 'contract.json', 'sha256' => 'sha256:' . str_repeat('a', 64)],
            null,
            ExecutionProfileName::SURGICAL,
            [new ExecutionRole('builder', true, ['inspect', 'mutate', 'validate'])],
            $stages,
            '2026-11-07T00:00:00+00:00',
        );
    }

    /**
     * @param list<non-empty-string> $requires
     * @param array<string, non-empty-string|null> $transitions
     */
    private function stage(string $id, array $requires, array $transitions): ExecutionStage
    {
        return new ExecutionStage($id, ExecutionStageKind::AGENT, 'builder', true, $requires, $transitions);
    }
}
