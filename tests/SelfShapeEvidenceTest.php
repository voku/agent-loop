<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Dogfood\RunProjectionAssertion;
use voku\AgentLoop\Dogfood\SelfShapeEvidence;

/**
 * The self-shape gate's decisions, which were previously untestable Bash.
 *
 * Both defects this gate shipped this month are pinned here: a finding
 * detection that watched one directory, and a projection check that trusted an
 * exit code. Neither could have had a test while the logic lived in a shell
 * script, which is the whole argument for the move.
 *
 * @internal
 */
final class SelfShapeEvidenceTest extends TestCase
{
    public function testAConsolidatedFindingStillCountsAsRecorded(): void
    {
        // The defect: `learn finding-transition` moves a finding out of
        // validated/, so watching that directory alone reported none.
        $evidence = new SelfShapeEvidence(
            ['.agent-loop/learning/findings/consolidated/finding.2026-08-14.005.json'],
            true,
        );

        self::assertSame(['finding.2026-08-14.005'], $evidence->recordedFindingIds());
        self::assertSame('findings_recorded', $evidence->learningStatus());
    }

    public function testAFindingIsCountedOnceAcrossARename(): void
    {
        $evidence = new SelfShapeEvidence([
            '.agent-loop/learning/findings/validated/finding.2026-08-14.005.json',
            '.agent-loop/learning/findings/consolidated/finding.2026-08-14.005.json',
            '.agent-loop/learning/findings/consolidated/finding.2026-08-14.006.json',
        ], false);

        self::assertSame(['finding.2026-08-14.005', 'finding.2026-08-14.006'], $evidence->recordedFindingIds());
    }

    public function testUnrelatedPathsAreNotMistakenForFindings(): void
    {
        $evidence = new SelfShapeEvidence([
            'src/GitWorkTree.php',
            '.agent-loop/learning/proposals/candidate/proposal.2026-08-14.001.json',
            '.agent-loop/learning/findings/consolidated/notes.json',
        ], false);

        self::assertSame([], $evidence->recordedFindingIds());
        self::assertSame('no_durable_learning', $evidence->learningStatus());
    }

    public function testWindowsPathSeparatorsAreUnderstood(): void
    {
        $evidence = new SelfShapeEvidence(
            ['.agent-loop\\learning\\findings\\consolidated\\finding.2026-08-14.005.json'],
            false,
        );

        self::assertSame(['finding.2026-08-14.005'], $evidence->recordedFindingIds());
    }

    public function testChangingMemoryWithoutEvidenceFailsRatherThanPickingAStatus(): void
    {
        $evidence = new SelfShapeEvidence(['MEMORY.md'], true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('a durable rule needs evidence');
        $evidence->learningStatus();
    }

    public function testACompleteProjectionHasNoProblems(): void
    {
        $problems = (new RunProjectionAssertion())->beforePrune($this->projection('done'), 'run:X:1', 'findings_recorded');

        self::assertSame([], $problems);
    }

    public function testAProjectionThatDisagreesReportsEveryDisagreement(): void
    {
        $status = $this->projection('done');
        $status['manifest']['state'] = 'incomplete';
        $status['manifest']['references']['learning']['decision'] = 'no_durable_learning';

        $problems = (new RunProjectionAssertion())->beforePrune($status, 'run:X:1', 'findings_recorded');

        self::assertCount(2, $problems, 'A reviewer wants every disagreement, not the first.');
        self::assertStringContainsString("manifest.state is 'incomplete'", $problems[0]);
    }

    public function testPruningMustNotTakeTheDurableFactsWithIt(): void
    {
        $assertion = new RunProjectionAssertion();

        self::assertSame([], $assertion->afterPrune($this->projection('missing'), 'run:X:1'));

        $damaged = $this->projection('missing');
        $damaged['manifest']['references']['verification']['state'] = 'missing';
        self::assertNotSame([], $assertion->afterPrune($damaged, 'run:X:1'));
    }

    /** @return array<string, mixed> */
    private function projection(string $sessionState): array
    {
        return [
            'manifest' => [
                'run_id' => 'run:X:1',
                'state' => 'complete',
                'next_action' => 'none',
                'references' => [
                    'session' => ['state' => $sessionState],
                    'verification' => ['state' => 'passed'],
                    'learning' => ['state' => 'decided', 'decision' => 'findings_recorded'],
                ],
            ],
        ];
    }
}
