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
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Execution\ExecutionPlan;
use voku\AgentLoop\Execution\ExecutionProfileName;
use voku\AgentLoop\Execution\ExecutionRole;
use voku\AgentLoop\Execution\ExecutionStage;
use voku\AgentLoop\Execution\ExecutionStageKind;
use voku\AgentLoop\Execution\ExecutionStageResultAuthority;
use voku\AgentLoop\Execution\ExecutionState;
use voku\AgentLoop\Execution\StageExecutionBundle;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoop\Execution\StageResult;

/**
 * Tests for artifact coverage bookkeeping during review stages.
 *
 * Invariant: "Automate the bookkeeping, not the claim of correctness."
 * - Automatically populates the expected changed candidate artifacts.
 * - Records which artifacts the review actually inspected.
 * - Supports explicit, justified exclusions (e.g. non-functional documentation).
 * - Compares actual review evidence against the expected set.
 * - Fails closed if any changed candidate file is neither reviewed nor explicitly excluded.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
final class ExecutionArtifactCoverageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/artifact-coverage-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testChangedCandidateFilesDetectsChangedPaths(): void
    {
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/docs', 0o775, true);
        file_put_contents($this->root . '/src/A.php', "<?php\n");
        file_put_contents($this->root . '/docs/README.md', "# Docs\n");
        $this->git('init', '-q');
        $this->git('config', 'user.email', 'tests@example.test');
        $this->git('config', 'user.name', 'Tests');
        $this->git('add', 'src/A.php', 'docs/README.md');
        $this->git('commit', '-qm', 'base');
        $baseCommit = $this->git('rev-parse', 'HEAD');

        // Modify src/A.php and add src/B.php in candidate
        file_put_contents($this->root . '/src/A.php', "<?php\n// modified\n");
        file_put_contents($this->root . '/src/B.php', "<?php\n// new\n");
        $this->git('add', 'src/A.php', 'src/B.php');
        $candidateTree = $this->git('write-tree');
        $candidateRevision = 'git-tree-v1:' . $baseCommit . ':' . $candidateTree;

        $gateway = new ExecutionGateway($this->root);
        $changed = $gateway->changedCandidateFiles($baseCommit, $candidateRevision);

        sort($changed);
        self::assertSame(['src/A.php', 'src/B.php'], $changed);
    }

    public function testStrictChangedCandidateListingDistinguishesEmptyFromFailure(): void
    {
        file_put_contents($this->root . '/a.txt', "base\n");
        $this->git('init', '-q');
        $this->git('config', 'user.email', 'tests@example.test');
        $this->git('config', 'user.name', 'Tests');
        $this->git('add', 'a.txt');
        $this->git('commit', '-qm', 'base');
        $base = $this->git('rev-parse', 'HEAD');
        $gateway = new ExecutionGateway($this->root);
        self::assertSame([], $gateway->changedCandidateFilesOrThrow($base, $base));
        self::assertSame([], $gateway->changedCandidateFiles($base, 'invalid'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to inspect changed candidate files');
        $gateway->changedCandidateFilesOrThrow($base, 'git-tree-v1:' . $base . ':' . str_repeat('f', 40));
    }

    public function testExclusionRecordingRequiresNonEmptyReason(): void
    {
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/A.php', "<?php\n");
        $this->git('init', '-q');
        $this->git('config', 'user.email', 'tests@example.test');
        $this->git('config', 'user.name', 'Tests');
        $this->git('add', 'src/A.php');
        $this->git('commit', '-qm', 'base');
        $baseCommit = $this->git('rev-parse', 'HEAD');
        $candidateRevision = $baseCommit;

        $bundle = new \voku\AgentLoop\Execution\StageExecutionBundle(
            'ABC-123',
            'run:test',
            1,
            str_repeat('a', 64),
            'review',
            1,
            ExecutionStageKind::AGENT,
            'reviewer',
            false,
            $this->root,
            $baseCommit,
            $candidateRevision,
            ['path' => 'contract.json', 'sha256' => 'sha256:' . str_repeat('b', 64)],
            null,
            ['src'],
            ['test'],
            null,
            [StageOutcome::PASS],
            'COMPLETE',
            'prompt',
        );

        $gateway = new ExecutionGateway($this->root);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('artifact exclusion requires an explicit justification reason');

        $gateway->recordStageArtifactExclusion($bundle, 'src/A.php', '   ');
    }

    public function testCompleteCoverageVerificationWithInspectedAndExcludedFiles(): void
    {
        $fixture = $this->createReviewFixture();
        $plan = $fixture['plan'];
        $candidateRevision = $fixture['candidateRevision'];

        $bundle = new StageExecutionBundle(
            $plan->taskId,
            $plan->runId,
            $plan->contractRevision,
            $plan->digest(),
            'review',
            1,
            ExecutionStageKind::AGENT,
            'reviewer',
            false,
            $this->root,
            $fixture['baseCommit'],
            $candidateRevision,
            ['path' => 'contract.json', 'sha256' => 'sha256:' . str_repeat('b', 64)],
            null,
            ['src'],
            ['test'],
            null,
            [StageOutcome::PASS],
            'COMPLETE',
            'prompt',
            expectedArtifacts: ['src/Core.php', 'docs/CHANGELOG.md'],
        );
        self::assertSame(['src/Core.php', 'docs/CHANGELOG.md'], $bundle->expectedArtifacts);

        $evidenceStore = new ExecutionEvidenceStore($this->root);
        $inspectedRef = $this->recordArtifactClaim($evidenceStore, $plan, $candidateRevision, 'workspace-file:src/Core.php', $this->root . '/src/Core.php');
        $excludedRef = $this->recordArtifactClaim($evidenceStore, $plan, $candidateRevision, 'excluded-file:docs/CHANGELOG.md', $this->root . '/docs/CHANGELOG.md');

        (new ExecutionStageResultAuthority($this->root))->assertAcceptable(
            $plan,
            $fixture['state'],
            $fixture['stage'],
            new StageResult('submission:pass-with-exclusion', $plan->taskId, $plan->runId, $plan->contractRevision, $plan->digest(), 'review', 1, StageOutcome::PASS, $candidateRevision, [$inspectedRef, $excludedRef], [], 'Review passed with justified changelog exclusion.'),
        );

        $this->addToAssertionCount(1);
    }

    public function testForgedExcludedFileClaimWithWrongDigestIsRejected(): void
    {
        $fixture = $this->createReviewFixture();
        $plan = $fixture['plan'];
        $candidateRevision = $fixture['candidateRevision'];

        $evidenceStore = new ExecutionEvidenceStore($this->root);
        $forgedRef = $evidenceStore->record(new ExecutionEvidenceClaim(
            $plan->taskId,
            $plan->runId,
            $plan->contractRevision,
            $plan->digest(),
            'review',
            1,
            $candidateRevision,
            ExecutionEvidenceKind::ARTIFACT,
            'excluded-file:src/Core.php',
            'sha256:' . str_repeat('0', 64),
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EVIDENCE_MISMATCH: artifact digest does not match the governed candidate content.');

        (new ExecutionStageResultAuthority($this->root))->assertAcceptable(
            $plan,
            $fixture['state'],
            $fixture['stage'],
            new StageResult('submission:forged-exclusion', $plan->taskId, $plan->runId, $plan->contractRevision, $plan->digest(), 'review', 1, StageOutcome::PASS, $candidateRevision, [$forgedRef], [], 'Review passed with forged exclusion claim.'),
        );
    }

    /**
     * @return non-empty-string
     */
    private function recordArtifactClaim(ExecutionEvidenceStore $store, ExecutionPlan $plan, string $candidateRevision, string $sourceRef, string $filePath): string
    {
        return $store->record(new ExecutionEvidenceClaim(
            $plan->taskId,
            $plan->runId,
            $plan->contractRevision,
            $plan->digest(),
            'review',
            1,
            $candidateRevision,
            ExecutionEvidenceKind::ARTIFACT,
            $sourceRef,
            'sha256:' . hash('sha256', (string) file_get_contents($filePath)),
        ));
    }

    /**
     * @return array{plan: ExecutionPlan, state: ExecutionState, stage: ExecutionStage, baseCommit: string, candidateRevision: string}
     */
    private function createReviewFixture(): array
    {
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/docs', 0o775, true);
        file_put_contents($this->root . '/src/Core.php', "<?php\n// v1\n");
        file_put_contents($this->root . '/docs/CHANGELOG.md', "# Changelog\n");
        $this->git('init', '-q');
        $this->git('config', 'user.email', 'tests@example.test');
        $this->git('config', 'user.name', 'Tests');
        $this->git('add', 'src/Core.php', 'docs/CHANGELOG.md');
        $this->git('commit', '-qm', 'base');
        $baseCommit = $this->git('rev-parse', 'HEAD');

        // Modify both files
        file_put_contents($this->root . '/src/Core.php', "<?php\n// v2\n");
        file_put_contents($this->root . '/docs/CHANGELOG.md', "# Changelog\n- Item 1\n");
        $this->git('add', 'src/Core.php', 'docs/CHANGELOG.md');
        $candidateRevision = 'git-tree-v1:' . $baseCommit . ':' . $this->git('write-tree');

        $stage = new ExecutionStage('review', ExecutionStageKind::AGENT, 'reviewer', false, [], [StageOutcome::PASS->value => null]);
        $plan = new ExecutionPlan(
            'ABC-123',
            'run:test-coverage',
            1,
            ['path' => '.agent-loop/contracts/ABC-123.json', 'sha256' => 'sha256:' . str_repeat('1', 64)],
            $baseCommit,
            ExecutionProfileName::SURGICAL,
            [new ExecutionRole('reviewer', false, ['review'])],
            [$stage],
            '2026-08-24T00:00:00+00:00',
        );
        $state = new ExecutionState($plan->taskId, $plan->runId, $plan->contractRevision, $plan->digest(), 'review', 1, $candidateRevision, null, []);

        return ['plan' => $plan, 'state' => $state, 'stage' => $stage, 'baseCommit' => $baseCommit, 'candidateRevision' => $candidateRevision];
    }

    private function git(string ...$arguments): string
    {
        exec(
            'git -C ' . escapeshellarg($this->root) . ' ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1',
            $output,
            $exit,
        );
        if ($exit !== 0) {
            throw new RuntimeException('git ' . implode(' ', $arguments) . ' failed: ' . implode("\n", $output));
        }

        return trim(implode("\n", $output));
    }

    private function removeDirectory(string $path): void
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
