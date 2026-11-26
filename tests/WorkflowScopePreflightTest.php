<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowCli;
use voku\AgentLoop\Workflow\WorkflowScopePreflightCommand;

/**
 * The pre-approval scope preflight observes a host-validated draft; it never
 * runs validation itself, never changes scope, and never reads stale or
 * unknown evidence as a clean result.
 */
final class WorkflowScopePreflightTest extends TestCase
{
    private string $root;
    private string $draft;
    private string $base;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(5));
        $this->root = sys_get_temp_dir() . '/agent-loop-preflight-' . $suffix;
        $this->draft = sys_get_temp_dir() . '/agent-loop-preflight-draft-' . $suffix;
        mkdir($this->root . '/src/Workflow', 0o775, true);
        mkdir($this->root . '/tests', 0o775, true);
        mkdir($this->root . '/.agent-loop', 0o775, true);
        file_put_contents($this->root . '/src/Workflow/TaskContractStore.php', "<?php\n// store\n");
        file_put_contents($this->root . '/tests/WorkflowPlanCommandTest.php', "<?php\n// pins the old behaviour\n");
        file_put_contents($this->root . '/tests/UnrelatedTest.php', "<?php\n// unrelated\n");
        file_put_contents($this->root . '/.gitignore', "/.agent-loop/\n");
        $this->git($this->root, 'init', '-q');
        $this->git($this->root, 'config', 'user.email', 'x@y.test');
        $this->git($this->root, 'config', 'user.name', 'X');
        $this->git($this->root, 'add', '.');
        $this->git($this->root, 'commit', '-qm', 'base');
        $this->base = $this->git($this->root, 'rev-parse', 'HEAD');
        $this->git($this->root, 'worktree', 'add', '-q', '--detach', $this->draft, $this->base);
    }

    protected function tearDown(): void
    {
        exec('env -u GIT_DIR -u GIT_WORK_TREE -u GIT_COMMON_DIR -u GIT_INDEX_FILE -u GIT_OBJECT_DIRECTORY -u GIT_ALTERNATE_OBJECT_DIRECTORIES -u GIT_PREFIX -u GIT_NAMESPACE -u GIT_CEILING_DIRECTORIES git -C ' . escapeshellarg($this->root) . ' worktree remove --force ' . escapeshellarg($this->draft) . ' 2>/dev/null');
        exec('rm -rf ' . escapeshellarg($this->root) . ' ' . escapeshellarg($this->draft));
    }

    public function testIncident1178ReportsTheOutOfScopeTestAsObservedDriftWithAProposalOnly(): void
    {
        $this->plan('touch ' . $this->root . '/validation-ran');
        file_put_contents($this->draft . '/src/Workflow/TaskContractStore.php', "<?php\n// resolve base\n");
        file_put_contents($this->draft . '/tests/WorkflowPlanCommandTest.php', "<?php\n// expects refusal\n");
        $contractBefore = (string) file_get_contents((new TaskContractStore($this->root))->load('T-1')->path);

        self::assertSame(0, $this->cli(['preflight', 'T-1', 'start', '--workspace', $this->draft])[0]);
        [$exit, $output] = $this->cli(['preflight', 'T-1', 'record', '--workspace', $this->draft, '--validation', 'passed', '--by', 'host']);
        self::assertSame(0, $exit, $output);

        $result = $this->projection();
        self::assertSame("validation_passed", $result["state"], (string) json_encode($result));
        self::assertSame('host_reported', $result['provenance']);
        self::assertSame(['tests/WorkflowPlanCommandTest.php'], array_column($result['observed_out_of_scope_change'], 'path'));
        self::assertSame('M', $result['observed_out_of_scope_change'][0]['status']);
        self::assertStringContainsString("--supersede --add-scope 'tests/WorkflowPlanCommandTest.php'", (string) $result['proposal']);
        self::assertSame('advisory_only', $result['authority']);
        // The preflight never executes validation and never changes the Contract.
        self::assertFileDoesNotExist($this->root . '/validation-ran');
        self::assertSame($contractBefore, (string) file_get_contents((new TaskContractStore($this->root))->load('T-1')->path));
        self::assertSame(['src/Workflow/TaskContractStore.php'], (new TaskContractStore($this->root))->load('T-1')->scope);
    }

    public function testUnrelatedChangeInsideScopeReportsNoDriftAndFailedValidationStaysAdvisory(): void
    {
        $this->plan('php -l src/Workflow/TaskContractStore.php');
        file_put_contents($this->draft . '/src/Workflow/TaskContractStore.php', "<?php\n// inside scope\n");
        $this->cli(['preflight', 'T-1', 'start', '--workspace', $this->draft]);
        $this->cli(['preflight', 'T-1', 'record', '--workspace', $this->draft, '--validation', 'passed', '--by', 'host']);
        $result = $this->projection();
        self::assertSame("validation_passed", $result["state"], (string) json_encode($result));
        self::assertSame([], $result['observed_out_of_scope_change']);
        self::assertNull($result['proposal']);

        $this->cli(['preflight', 'T-1', 'start', '--workspace', $this->draft]);
        $this->cli(['preflight', 'T-1', 'record', '--workspace', $this->draft, '--validation', 'failed', '--by', 'host']);
        self::assertSame('validation_failed', $this->projection()['state']);
        self::assertSame([], $this->projection()['observed_out_of_scope_change']);
    }

    public function testNoRecordIsNotRunAndMissingWorkspaceOrForeignBaseIsUnknown(): void
    {
        $this->plan('true');
        self::assertSame('not_run', $this->projection()['state']);

        [$exit] = $this->cli(['preflight', 'T-1', 'start', '--workspace', $this->draft . '-missing']);
        self::assertSame(1, $exit);
        self::assertSame('not_run', $this->projection()['state']);

        file_put_contents($this->draft . '/x.txt', "x\n");
        $this->git($this->draft, 'add', 'x.txt');
        $this->git($this->draft, 'commit', '-qm', 'moved');
        [$exit, $output] = $this->cli(['preflight', 'T-1', 'start', '--workspace', $this->draft]);
        self::assertSame(1, $exit);
        self::assertStringContainsString('base', $output);
    }

    public function testDraftChangedBetweenStartAndRecordIsUnknown(): void
    {
        $this->plan('true');
        $this->cli(['preflight', 'T-1', 'start', '--workspace', $this->draft]);
        // A validation command that writes into the draft.
        file_put_contents($this->draft . '/generated.txt', "written by validation\n");
        $this->cli(['preflight', 'T-1', 'record', '--workspace', $this->draft, '--validation', 'passed', '--by', 'host']);
        $result = $this->projection();
        self::assertSame('unknown', $result['state']);
        self::assertSame('draft_changed_during_validation', $result['reason']);
    }

    public function testAnyBoundIdentityChangeMakesTheResultStale(): void
    {
        $this->plan('true');
        $this->cli(['preflight', 'T-1', 'start', '--workspace', $this->draft]);
        $this->cli(['preflight', 'T-1', 'record', '--workspace', $this->draft, '--validation', 'passed', '--by', 'host']);
        self::assertSame('validation_passed', $this->projection()['state']);

        // Untracked draft file after record.
        file_put_contents($this->draft . '/later.txt', "later\n");
        self::assertSame('stale', $this->projection()['state']);
        unlink($this->draft . '/later.txt');
        self::assertSame('validation_passed', $this->projection()['state']);

        // New Contract revision with other validation.
        $this->plan('php -l src/Workflow/TaskContractStore.php');
        $stale = $this->projection();
        self::assertSame('stale', $stale['state']);
        self::assertContains('contract', $stale['stale_identities']);
        self::assertContains('validation', $stale['stale_identities']);
    }

    public function testABaseChangeAfterRecordIsStaleNotAnUnavailableDraft(): void
    {
        $this->plan('true');
        $this->cli(['preflight', 'T-1', 'start', '--workspace', $this->draft]);
        $this->cli(['preflight', 'T-1', 'record', '--workspace', $this->draft, '--validation', 'passed', '--by', 'host']);
        file_put_contents($this->root . '/README.md', "moved base\n");
        $this->git($this->root, 'add', 'README.md');
        $this->git($this->root, 'commit', '-qm', 'second');
        $this->base = $this->git($this->root, 'rev-parse', 'HEAD');
        $this->plan('true');

        $result = $this->projection();
        self::assertSame('stale', $result['state'], (string) json_encode($result));
        self::assertContains('base', $result['stale_identities']);
    }

    public function testPreflightLeavesTheRootIndexAndRefsByteIdentical(): void
    {
        $this->plan('true');
        file_put_contents($this->draft . '/tests/WorkflowPlanCommandTest.php', "<?php\n// changed\n");
        $index = (string) file_get_contents($this->root . '/.git/index');
        $refs = $this->git($this->root, 'for-each-ref');
        $this->cli(['preflight', 'T-1', 'start', '--workspace', $this->draft]);
        $this->cli(['preflight', 'T-1', 'record', '--workspace', $this->draft, '--validation', 'passed', '--by', 'host']);
        $this->projection();
        self::assertSame($index, (string) file_get_contents($this->root . '/.git/index'));
        self::assertSame($refs, $this->git($this->root, 'for-each-ref'));
    }

    public function testProposalQuotesPathsAndNonAsciiPathsMatchLiterally(): void
    {
        $this->plan('true');
        mkdir($this->draft . '/x;id', 0o775, true);
        file_put_contents($this->draft . '/x;id/a.php', "<?php\n");
        file_put_contents($this->draft . '/src/Workflow/Über.php', "<?php\n");
        $this->cli(['preflight', 'T-1', 'start', '--workspace', $this->draft]);
        $this->cli(['preflight', 'T-1', 'record', '--workspace', $this->draft, '--validation', 'passed', '--by', 'host']);
        $result = $this->projection();
        // The non-ASCII path arrives literally (git diff -z), not Git-quoted.
        self::assertSame(['src/Workflow/Über.php', 'x;id/a.php'], array_column($result['observed_out_of_scope_change'], 'path'), (string) json_encode($result));
        self::assertStringContainsString("--add-scope 'x;id/a.php'", (string) $result['proposal']);
    }

    public function testWholeRepositoryScopeDoesNotReportFalseDrift(): void
    {
        $this->plan('true');
        $path = (new TaskContractStore($this->root))->load('T-1')->path;
        file_put_contents($this->draft . '/tests/UnrelatedTest.php', "<?php\n// changed\n");

        // A non-empty Contract scope containing '.' covers the entire repository.
        foreach ([['.'], ['src/Workflow/TaskContractStore.php', '.']] as $scope) {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $data['scope'] = $scope;
            file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));

            [$started, $startOutput] = $this->cli(['preflight', 'T-1', 'start', '--workspace', $this->draft]);
            self::assertSame(0, $started, $startOutput);
            [$recorded, $recordOutput] = $this->cli(['preflight', 'T-1', 'record', '--workspace', $this->draft, '--validation', 'passed', '--by', 'host']);
            self::assertSame(0, $recorded, $recordOutput);
            self::assertSame('validation_passed', $this->projection()['state']);
            self::assertSame([], $this->projection()['observed_out_of_scope_change'], (string) json_encode($scope));
            self::assertNull($this->projection()['proposal']);
        }
    }

    public function testForeignRepositoryAtTheSameCommitIsNotAnAuthorizedDraft(): void
    {
        $this->plan('true');
        $foreign = $this->draft . '-foreign';
        try {
            $this->git($this->root, 'clone', '-q', '--no-local', $this->root, $foreign);
            self::assertSame($this->base, $this->git($foreign, 'rev-parse', 'HEAD'));
            [$exit, $output] = $this->cli(['preflight', 'T-1', 'start', '--workspace', $foreign]);
            self::assertSame(1, $exit, $output);
            self::assertStringContainsString('linked worktree of this repository', $output);
        } finally {
            exec('rm -rf ' . escapeshellarg($foreign));
        }
    }

    public function testTheMainCheckoutIsNotAcceptedAsDraft(): void
    {
        $this->plan('true');
        [$exit, $output] = $this->cli(['preflight', 'T-1', 'start', '--workspace', $this->root]);
        self::assertSame(1, $exit);
        self::assertStringContainsString('linked worktree of this repository', $output);
    }

    public function testASubdirectoryOfTheDraftIsRefused(): void
    {
        $this->plan('true');
        [$exit, $output] = $this->cli(['preflight', 'T-1', 'start', '--workspace', $this->draft . '/src']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('linked worktree of this repository', $output);
    }

    public function testUnregisteredCopyOfALinkedWorktreeIsRejected(): void
    {
        $this->plan('true');
        $unregistered = $this->draft . '-unregistered';
        mkdir($unregistered, 0o775, true);
        try {
            // Git discovers the same common directory through a copied .git pointer,
            // but this directory is not listed by git worktree list --porcelain.
            self::assertTrue(copy($this->draft . '/.git', $unregistered . '/.git'));
            self::assertSame($this->base, $this->git($unregistered, 'rev-parse', 'HEAD'));
            self::assertSame(
                $this->git($this->root, 'rev-parse', '--path-format=absolute', '--git-common-dir'),
                $this->git($unregistered, 'rev-parse', '--path-format=absolute', '--git-common-dir'),
            );

            [$exit, $output] = $this->cli(['preflight', 'T-1', 'start', '--workspace', $unregistered]);
            self::assertSame(1, $exit, $output);
            self::assertStringContainsString('linked worktree of this repository', $output);
        } finally {
            exec('rm -rf ' . escapeshellarg($unregistered));
        }
    }

    public function testUnreadableRecordIsUnknownNeverClean(): void
    {
        $this->plan('true');
        $contract = (new TaskContractStore($this->root))->load('T-1');
        file_put_contents(dirname($contract->path) . '/preflight.json', '{broken');
        self::assertSame('unknown', $this->projection()['state']);
    }

    public function testApprovalCardShowsPreflightIdentityProvenanceAndAdvisoryStatus(): void
    {
        $this->plan('true');
        file_put_contents($this->draft . '/tests/WorkflowPlanCommandTest.php', "<?php\n// changed\n");
        $this->cli(['preflight', 'T-1', 'start', '--workspace', $this->draft]);
        $this->cli(['preflight', 'T-1', 'record', '--workspace', $this->draft, '--validation', 'passed', '--by', 'host']);

        [$exit, $output] = $this->cli(['plan', 'T-1', '--by', 'planner', '--file', 'src/Workflow/TaskContractStore.php',
            '--goal', 'Resolve the base commit.', '--validation', 'true', '--base-commit', $this->base, '--format=json']);
        self::assertSame(0, $exit, $output);
        $card = json_decode($output, true, 512, JSON_THROW_ON_ERROR)['human_decision']['card'];
        // Re-planning creates a new revision, so the earlier record must read as stale, not as clean.
        self::assertSame('stale', $card['proof']['preflight']['state']);
        self::assertContains('contract', $card['proof']['preflight']['stale_identities']);
        self::assertSame('host_reported', $card['proof']['preflight']['provenance']);
        self::assertSame('advisory_only', $card['proof']['preflight']['authority']);

        $this->cli(['preflight', 'T-1', 'start', '--workspace', $this->draft]);
        $this->cli(['preflight', 'T-1', 'record', '--workspace', $this->draft, '--validation', 'passed', '--by', 'host']);
        $presentation = (new \voku\AgentLoop\Workflow\WorkflowHumanDecisionProjector($this->root))->projectContractApprovalPresentation(
            'T-1',
            'agent-loop workflow approve T-1 --revision 2 --by <named-actor>',
            \voku\AgentLoop\Run\RunPolicyEvaluation::KIND_DECISION_REQUIRED,
        );
        self::assertNotNull($presentation);
        $text = (new \voku\AgentLoop\Workflow\WorkflowHumanDecisionTextRenderer($this->root))->renderContractApproval($presentation);
        self::assertStringContainsString('Scope preflight: validation_passed, host_reported, advisory only', $text);
        self::assertStringContainsString('observed out-of-scope change: M tests/WorkflowPlanCommandTest.php', $text);
        self::assertStringContainsString('proposal (not applied):', $text);
    }

    private function plan(string $validation): void
    {
        [$exit, $output] = $this->cli([
            'plan', 'T-1', '--by', 'planner', '--file', 'src/Workflow/TaskContractStore.php',
            '--goal', 'Resolve the base commit.', '--validation', $validation, '--base-commit', $this->base,
        ]);
        self::assertSame(0, $exit, $output);
    }

    /** @return array<string, mixed> */
    private function projection(): array
    {
        return WorkflowScopePreflightCommand::projection($this->root, (new TaskContractStore($this->root))->load('T-1'));
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string}
     */
    private function cli(array $arguments): array
    {
        $level = ob_get_level();
        ob_start();
        try {
            $exit = (new WorkflowCli($this->root))->run($arguments);
            $output = (string) ob_get_clean();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return [$exit, $output];
    }

    private function git(string $directory, string ...$arguments): string
    {
        exec('env -u GIT_DIR -u GIT_WORK_TREE -u GIT_COMMON_DIR -u GIT_INDEX_FILE -u GIT_OBJECT_DIRECTORY -u GIT_ALTERNATE_OBJECT_DIRECTORIES -u GIT_PREFIX -u GIT_NAMESPACE -u GIT_CEILING_DIRECTORIES git -C ' . escapeshellarg($directory) . ' ' . implode(' ', array_map('escapeshellarg', array_values($arguments))) . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));

        return trim(implode("\n", $output));
    }
}
