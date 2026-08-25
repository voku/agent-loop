<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLearning\RunLearningDecisionStatus;
use voku\AgentLearning\RunLearningDecisionStore;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\ImplementationSnapshot;
use voku\AgentLoop\Workflow\ReviewAcknowledgementStore;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\Transparency\ImplementationIdentityStatus;
use voku\AgentLoop\Workflow\Transparency\RepositoryObservationStatus;
use voku\AgentLoop\Workflow\Transparency\ReviewCurrency;
use voku\AgentLoop\Workflow\Transparency\TransparencyCategory;
use voku\AgentLoop\Workflow\Transparency\TransparencyItem;
use voku\AgentLoop\Workflow\Transparency\TransparencyProvenance;
use voku\AgentLoop\Workflow\Transparency\WorkflowTransparencyService;
use voku\AgentSession\SessionStore;

final class TransparencyProjectionTest extends TestCase
{
    private const string TASK_ID = 'TRANSPARENCY-1';

    /** @var list<string> */
    private array $tempDirs = [];

    #[After]
    public function cleanupTempDirs(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }
        $this->tempDirs = [];
    }

    public function testProjectsContractBoundaryObservationSnapshotAndReviewFindings(): void
    {
        $this->requireGit();
        $root = $this->governedProject();
        $contract = $this->approvedContract($root, $this->head($root));
        $snapshot = ImplementationSnapshot::capture($root, $contract);
        $this->writeReview($root, $contract->revision, $snapshot->digest, [
            [
                'id' => 'missing_regression_test',
                'severity' => 'WARN',
                'message' => 'The change has no regression test.',
                'evidence' => ['src/Foo.php'],
            ],
        ]);

        // One change inside approved scope, one outside it.
        file_put_contents($root . '/src/Foo.php', "<?php\nreturn 'changed';\n");
        file_put_contents($root . '/docs.md', "outside approved scope\n");

        $projection = (new WorkflowTransparencyService($root))->task(self::TASK_ID);

        self::assertTrue($projection->contract->exists);
        self::assertTrue($projection->contract->isApproved());
        self::assertSame(['src'], $projection->contract->scope);
        self::assertSame(['Do not touch documentation.'], $projection->contract->nonGoals);
        self::assertSame(['Foo returns the changed value.'], $projection->contract->acceptanceCriteria);

        self::assertSame(RepositoryObservationStatus::OBSERVED, $projection->observation->status);
        self::assertSame(['src/Foo.php'], $projection->scopeCoverage->changedInScope);
        self::assertSame(['docs.md'], $projection->scopeCoverage->changedOutsideScope);
        self::assertTrue($projection->scopeCoverage->observed);

        self::assertSame(ImplementationIdentityStatus::CAPTURED, $projection->implementation->status);
        self::assertNotNull($projection->implementation->digest);

        self::assertTrue($projection->review->exists);
        self::assertCount(1, $projection->review->findings);
        self::assertSame('missing_regression_test', $projection->review->findings[0]->id);
        self::assertSame('WARN', $projection->review->findings[0]->severity);
        self::assertSame(['src/Foo.php'], $projection->review->findings[0]->evidence);

        $categories = $this->categoriesOf($projection->items());
        self::assertContains(TransparencyCategory::CHANGED_IN_SCOPE, $categories);
        self::assertContains(TransparencyCategory::CHANGED_OUTSIDE_SCOPE, $categories);
        self::assertContains(TransparencyCategory::CONTRACT_NON_GOAL, $categories);
        self::assertContains(TransparencyCategory::REVIEW_FINDING, $categories);
        self::assertNotContains(TransparencyCategory::UNKNOWN, $categories);
    }

    /**
     * A finding is evidence about the change, and a non-goal is workflow
     * authority. A host that renders both as one generic "issue" list has lost
     * the only thing that says which of the two a human must act on.
     */
    public function testEveryItemKeepsItsAuthorityClass(): void
    {
        $this->requireGit();
        $root = $this->governedProject();
        $contract = $this->approvedContract($root, $this->head($root));
        $this->writeReview($root, $contract->revision, ImplementationSnapshot::capture($root, $contract)->digest, [
            ['id' => 'f1', 'severity' => 'FAIL', 'message' => 'Broken.', 'evidence' => []],
        ]);
        file_put_contents($root . '/docs.md', "outside\n");

        $items = (new WorkflowTransparencyService($root))->task(self::TASK_ID)->items();

        foreach ($items as $item) {
            self::assertSame($item->category->provenance(), $item->provenance());
        }
        self::assertSame(
            TransparencyProvenance::WORKFLOW_AUTHORITY,
            $this->firstOf($items, TransparencyCategory::CONTRACT_NON_GOAL)->provenance(),
        );
        self::assertSame(
            TransparencyProvenance::REVIEW_EVIDENCE,
            $this->firstOf($items, TransparencyCategory::REVIEW_FINDING)->provenance(),
        );
        self::assertSame(
            TransparencyProvenance::REPOSITORY_OBSERVATION,
            $this->firstOf($items, TransparencyCategory::CHANGED_OUTSIDE_SCOPE)->provenance(),
        );
    }

    /**
     * An empty change set is not the same claim as an unobservable repository,
     * and neither one satisfies an acceptance criterion.
     */
    public function testUnobservableRepositoryIsUnknownRatherThanAnEmptyCleanChangeSet(): void
    {
        $root = $this->governedProject(git: false);
        $this->approvedContract($root, null);

        $projection = (new WorkflowTransparencyService($root))->task(self::TASK_ID);

        self::assertFalse($projection->observation->isObserved());
        self::assertFalse($projection->scopeCoverage->observed);
        self::assertSame([], $projection->scopeCoverage->changedInScope);
        self::assertContains(TransparencyCategory::UNKNOWN, $this->categoriesOf($projection->items()));

        // The Contract's required outcomes are visible, and nothing in the
        // projection claims any of them was met.
        self::assertSame(['Foo returns the changed value.'], $projection->contract->acceptanceCriteria);
        $encoded = json_encode($projection->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('acceptance_satisfied', $encoded);
        self::assertStringNotContainsString('"complete"', $encoded);
    }

    public function testNoObservedChangesStillLeavesAcceptanceUnproven(): void
    {
        $this->requireGit();
        $root = $this->governedProject();
        $this->approvedContract($root, $this->head($root));

        $projection = (new WorkflowTransparencyService($root))->task(self::TASK_ID);

        self::assertTrue($projection->observation->isObserved());
        self::assertSame([], $projection->observation->changedFiles);
        self::assertSame([], $projection->scopeCoverage->changedInScope);
        self::assertSame(['Foo returns the changed value.'], $projection->contract->acceptanceCriteria);
    }

    public function testStaleReviewFindingsRemainReadableButNeverLookCurrent(): void
    {
        $this->requireGit();
        $root = $this->governedProject();
        $contract = $this->approvedContract($root, $this->head($root));
        $snapshot = ImplementationSnapshot::capture($root, $contract);
        $session = (new SessionStore())->create($root . '/.agent-loop/sessions', self::TASK_ID, by: 'fixture-agent');
        (new GovernedRunStore($root))->prepare($contract, $session, $root . '/.agent-loop/learning');
        $this->writeReview($root, $contract->revision, $snapshot->digest, [
            ['id' => 'stale_finding', 'severity' => 'WARN', 'message' => 'Recorded against the old implementation.', 'evidence' => []],
        ]);

        $current = (new WorkflowTransparencyService($root))->task(self::TASK_ID)->review;
        self::assertSame(ReviewCurrency::CURRENT, $current->currency);
        self::assertSame('unacknowledged', $current->lifecycleStatus);

        // The implementation moves on; the persisted report does not.
        file_put_contents($root . '/src/Foo.php', "<?php\nreturn 'moved on';\n");

        $stale = (new WorkflowTransparencyService($root))->task(self::TASK_ID)->review;
        self::assertSame(ReviewCurrency::STALE, $stale->currency);
        self::assertSame('stale', $stale->lifecycleStatus);
        self::assertCount(1, $stale->findings, 'stale findings stay readable as evidence');
        self::assertNull($stale->acknowledgedBy);
    }

    public function testAcknowledgementNamesTheExactReportDigest(): void
    {
        $this->requireGit();
        $root = $this->governedProject();
        $contract = $this->approvedContract($root, $this->head($root));
        $snapshot = ImplementationSnapshot::capture($root, $contract);
        $session = (new SessionStore())->create($root . '/.agent-loop/sessions', self::TASK_ID, by: 'fixture-agent');
        $run = (new GovernedRunStore($root))->prepare($contract, $session, $root . '/.agent-loop/learning');
        $this->writeReview($root, $contract->revision, $snapshot->digest, []);

        $service = new WorkflowTransparencyService($root);
        $before = $service->task(self::TASK_ID)->review;
        self::assertSame('unacknowledged', $before->lifecycleStatus);
        self::assertNotNull($before->sha256);

        (new ReviewAcknowledgementStore($root))->record($run, $contract, $snapshot, $before->sha256, 'fixture-reviewer');

        $after = $service->task(self::TASK_ID)->review;
        self::assertSame(ReviewCurrency::CURRENT, $after->currency);
        self::assertSame('ok', $after->lifecycleStatus);
        self::assertSame('fixture-reviewer', $after->acknowledgedBy);
        self::assertNotNull($after->acknowledgedAt);
        self::assertSame($before->sha256, $after->sha256);
    }

    public function testDeferredFollowUpAppearsOnlyWhenADurableDecisionRecordsOne(): void
    {
        $this->requireGit();
        $root = $this->governedProject();
        $contract = $this->approvedContract($root, $this->head($root));
        $session = (new SessionStore())->create($root . '/.agent-loop/sessions', self::TASK_ID, by: 'fixture-agent');
        $run = (new GovernedRunStore($root))->prepare($contract, $session, $root . '/.agent-loop/learning');

        $service = new WorkflowTransparencyService($root);
        self::assertNull($service->task(self::TASK_ID)->deferredFollowUp);
        self::assertNotContains(
            TransparencyCategory::FUTURE_WORK_DEFERRED,
            $this->categoriesOf($service->task(self::TASK_ID)->items()),
        );

        (new RunLearningDecisionStore($root . '/.agent-loop/learning'))->record(
            $run->runId,
            RunLearningDecisionStatus::FOLLOW_UP_REQUIRED,
            'fixture-human',
            'The adjacent refactor needs its own Contract.',
            followUpRef: 'TRANSPARENCY-2',
        );

        $deferred = $service->task(self::TASK_ID)->deferredFollowUp;
        self::assertNotNull($deferred);
        self::assertSame('TRANSPARENCY-2', $deferred->followUpRef);
        self::assertSame('fixture-human', $deferred->decidedBy);
        self::assertContains(
            TransparencyCategory::FUTURE_WORK_DEFERRED,
            $this->categoriesOf($service->task(self::TASK_ID)->items()),
        );
    }

    public function testMissingContractProjectsMissingRatherThanBlanketApproval(): void
    {
        $root = $this->governedProject(git: false);

        $projection = (new WorkflowTransparencyService($root))->task('NO-CONTRACT');

        self::assertFalse($projection->contract->exists);
        self::assertFalse($projection->contract->isApproved());
        self::assertSame(RepositoryObservationStatus::NO_CONTRACT, $projection->observation->status);
        self::assertSame(ImplementationIdentityStatus::NO_CONTRACT, $projection->implementation->status);
        self::assertSame(ReviewCurrency::MISSING, $projection->review->currency);
    }

    public function testContextSkippedFactsAreTypedAndDoNotRequireParsingRenderedLines(): void
    {
        $this->requireGit();
        $root = $this->governedProject();
        $this->approvedContract($root, $this->head($root));

        $coverage = (new WorkflowTransparencyService($root))->task(self::TASK_ID)->context;

        // No compiled Recall bundle exists in this fixture, so context
        // construction reports the missing input rather than inventing one.
        self::assertNotSame([], $coverage->skipped);
        foreach ($coverage->skipped as $skipped) {
            self::assertNotSame('', $skipped);
        }
        self::assertSame('human_required', $coverage->interaction->toArray()['authority_bearing_decisions']);
        self::assertSame('forbidden', $coverage->futureWork->toArray()['current_contract_scope_expansion']);
    }

    /**
     * @param list<TransparencyItem> $items
     * @return list<TransparencyCategory>
     */
    private function categoriesOf(array $items): array
    {
        return array_values(array_unique(
            array_map(static fn (TransparencyItem $item): TransparencyCategory => $item->category, $items),
            SORT_REGULAR,
        ));
    }

    /** @param list<TransparencyItem> $items */
    private function firstOf(array $items, TransparencyCategory $category): TransparencyItem
    {
        foreach ($items as $item) {
            if ($item->category === $category) {
                return $item;
            }
        }

        throw new RuntimeException('No transparency item for category ' . $category->value);
    }

    private function governedProject(bool $git = true): string
    {
        $root = $this->tempDir();
        mkdir($root . '/src', 0o775, true);
        mkdir($root . '/.agent-loop/learning', 0o775, true);
        file_put_contents($root . '/src/Foo.php', "<?php\nreturn 'original';\n");
        if (!$git) {
            return $root;
        }

        $this->git($root, ['init']);
        $this->git($root, ['config', 'user.email', 'transparency@example.test']);
        $this->git($root, ['config', 'user.name', 'Transparency Test']);
        $this->git($root, ['config', 'commit.gpgsign', 'false']);
        file_put_contents($root . '/.gitignore', ".agent-loop/\n");
        $this->git($root, ['add', 'src/Foo.php', '.gitignore']);
        $this->git($root, ['commit', '-m', 'base']);

        return $root;
    }

    private function approvedContract(string $root, ?string $baseCommit): TaskContract
    {
        $contracts = new TaskContractStore($root);
        $contracts->create(
            self::TASK_ID,
            'Change the value Foo returns.',
            ['src'],
            ['Do not touch documentation.'],
            ['php -r "exit(0);"'],
            'fixture-planner',
            baseCommit: $baseCommit,
            acceptanceCriteria: ['Foo returns the changed value.'],
        );

        return $contracts->approve(self::TASK_ID, 'fixture-approver');
    }

    /** @param list<array{id:string,severity:string,message:string,evidence:list<string>}> $findings */
    private function writeReview(string $root, int $contractRevision, string $implementationSnapshot, array $findings): void
    {
        $directory = $root . '/.agent-loop/recall/' . self::TASK_ID . '/reviews';
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create review fixture directory.');
        }
        $status = 'ok';
        foreach ($findings as $finding) {
            if ($finding['severity'] === 'FAIL') {
                $status = 'fail';
                break;
            }
            if ($finding['severity'] === 'WARN') {
                $status = 'warn';
            }
        }
        file_put_contents($directory . '/' . self::TASK_ID . '.blindspots.json', json_encode([
            'version' => 2,
            'task_id' => self::TASK_ID,
            'status' => $status,
            'contract_revision' => $contractRevision,
            'implementation_snapshot' => $implementationSnapshot,
            'findings' => $findings,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }

    private function head(string $root): string
    {
        return trim($this->git($root, ['rev-parse', 'HEAD']));
    }

    private function requireGit(): void
    {
        if ($this->process(getcwd() ?: '.', ['git', '--version'])['exit'] !== 0) {
            self::markTestSkipped('Git is required for transparency projection coverage.');
        }
    }

    /** @param list<string> $args */
    private function git(string $root, array $args): string
    {
        $result = $this->process($root, ['git', ...$args]);
        self::assertSame(0, $result['exit'], $result['stderr']);

        return $result['stdout'];
    }

    /**
     * @param non-empty-list<string> $command
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function process(string $root, array $command): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        if (!is_resource($process)) {
            return ['exit' => 127, 'stdout' => '', 'stderr' => 'Unable to start process.'];
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/agent-loop-transparency-projection-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir, 0o777, true));
        $this->tempDirs[] = $dir;

        return $dir;
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
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
