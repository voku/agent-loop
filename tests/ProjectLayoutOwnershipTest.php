<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Init\InitPathsCommand;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunManifestStore;
use voku\AgentLoop\Run\RunVerificationReceiptStore;
use voku\AgentLoop\Workflow\AcceptedRiskWriter;
use voku\AgentLoop\Workflow\TaskContractStore;

/**
 * ProjectLayout is the only thing allowed to spell a state location.
 *
 * A project that configures its own state root must move every owned path with
 * it. Anything that keeps a private literal stays behind in the default tree,
 * which is how a read-only projection once kept reading a pre-consolidation
 * session root while the rest of the system had moved on.
 */
final class ProjectLayoutOwnershipTest extends TestCase
{
    private string $root;

    private string|false $originalTrustedExternalStatePaths = false;

    protected function setUp(): void
    {
        $this->originalTrustedExternalStatePaths = getenv('AGENT_LOOP_TRUSTED_EXTERNAL_STATE_PATHS');
        putenv('AGENT_LOOP_TRUSTED_EXTERNAL_STATE_PATHS');
        $this->root = sys_get_temp_dir() . '/agent-loop-layout-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/.agent-loop', 0o775, true);
    }

    protected function tearDown(): void
    {
        putenv($this->originalTrustedExternalStatePaths === false
            ? 'AGENT_LOOP_TRUSTED_EXTERNAL_STATE_PATHS'
            : 'AGENT_LOOP_TRUSTED_EXTERNAL_STATE_PATHS=' . $this->originalTrustedExternalStatePaths);
        $this->rm($this->root);
    }

    public function testConfiguredStateRootMovesEveryOwnedPath(): void
    {
        $this->writeConfig(['state_root' => 'var/agent-state']);
        $layout = new ProjectLayout($this->root);

        foreach ($layout->describe() as $name => $path) {
            if ($name === 'project_root' || $name === 'config') {
                continue;
            }

            self::assertStringStartsWith(
                'var/agent-state',
                $path,
                $name . ' ignored the configured state root and stayed in the default tree.',
            );
        }
    }

    public function testOwnedStoresWriteBelowTheConfiguredStateRoot(): void
    {
        $this->writeConfig(['state_root' => 'var/agent-state']);

        $contracts = new TaskContractStore($this->root);
        $contracts->create('ABC-123', 'Prove the layout owns every path.', ['src/Foo.php'], [], ['vendor/bin/phpunit'], 'lars');

        self::assertFileExists($this->root . '/var/agent-state/contracts/ABC-123/contract.json');
        self::assertDirectoryDoesNotExist($this->root . '/.agent-loop/contracts');

        self::assertStringStartsWith(
            $this->root . '/var/agent-state/runs/ABC-123',
            (new GovernedRunStore($this->root))->path('ABC-123'),
        );
        self::assertStringStartsWith(
            $this->root . '/var/agent-state/runs/ABC-123',
            (new RunManifestStore($this->root))->path('ABC-123'),
        );
        self::assertStringStartsWith(
            $this->root . '/var/agent-state/runs/ABC-123',
            (new RunVerificationReceiptStore($this->root))->path('ABC-123'),
        );
        self::assertSame(
            'var/agent-state/risks/ABC-123.accepted-risk.md',
            (new AcceptedRiskWriter($this->root))->relativePath('ABC-123'),
        );
    }

    public function testIndividualBranchesCanBeOverriddenWithoutMovingTheRest(): void
    {
        $this->writeConfig(['learning_root' => 'infra/doc/agent-learning']);
        $layout = new ProjectLayout($this->root);

        self::assertSame($this->root . '/infra/doc/agent-learning', $layout->learningRoot());
        self::assertSame('infra/doc/agent-learning', $layout->display($layout->learningRoot()));
        self::assertSame('.agent-loop/runs', $layout->display($layout->runsRoot()));
    }

    /**
     * A project that already builds an agent-map index somewhere else kept a
     * second, weaker copy under the state root, and the workflow read that one.
     */
    public function testMapRootCanPointAtAnIndexTheProjectAlreadyMaintains(): void
    {
        $this->writeConfig(['map_root' => '.agent-map']);
        $layout = new ProjectLayout($this->root);

        self::assertSame($this->root . '/.agent-map', $layout->mapRoot());
        self::assertSame('.agent-map/php-symbols.json', $layout->display($layout->mapIndex()));
        self::assertSame('.agent-map/search.sqlite', $layout->display($layout->mapSearchIndex()));
    }

    public function testMapRootDefaultsToTheStateRoot(): void
    {
        self::assertSame('.agent-loop/map', (new ProjectLayout($this->root))->display((new ProjectLayout($this->root))->mapRoot()));
    }

    public function testMapRootRejectsSymlinkedArtifacts(): void
    {
        $outside = $this->root . '-outside-map.sqlite';
        file_put_contents($outside, 'database');
        $mapRoot = $this->root . '/.agent-loop/map';
        mkdir($mapRoot, 0o775, true);
        if (!@symlink($outside, $mapRoot . '/search.sqlite')) {
            unlink($outside);
            self::markTestSkipped('symlink() is not available.');
        }

        try {
            try {
                $resolvedRoot = (new ProjectLayout($this->root))->mapRoot();
                self::assertSame($mapRoot, $resolvedRoot);
                self::fail('Map readers and writers must not follow linked artifact files.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('Map artifact tree', $exception->getMessage());
            }
        } finally {
            unlink($mapRoot . '/search.sqlite');
            unlink($outside);
        }
    }

    public function testProjectRecallDocumentManifestsArePortableAndDeterministic(): void
    {
        mkdir($this->root . '/docs', 0o775, true);
        mkdir($this->root . '/modules/payments', 0o775, true);
        touch($this->root . '/docs/recall-documents.json');
        touch($this->root . '/modules/payments/recall-documents.json');
        file_put_contents(
            $this->root . '/.agent-loop/init.json',
            json_encode([
                'version' => 1,
                'recall' => [
                    'document_manifests' => [
                        'modules/payments/recall-documents.json',
                        'docs/recall-documents.json',
                        'docs/recall-documents.json',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame([
            $this->root . '/docs/recall-documents.json',
            $this->root . '/modules/payments/recall-documents.json',
        ], (new ProjectLayout($this->root))->recallDocumentManifests());
    }

    public function testProjectRecallDocumentManifestCannotEscapeTheProject(): void
    {
        file_put_contents(
            $this->root . '/.agent-loop/init.json',
            json_encode([
                'version' => 1,
                'recall' => ['document_manifests' => ['../shared/policy.json']],
            ], JSON_THROW_ON_ERROR),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('recall document manifest must stay inside the project');

        (new ProjectLayout($this->root))->recallDocumentManifests();
    }

    public function testProjectRecallDocumentManifestCannotEscapeThroughSymlink(): void
    {
        $outside = sys_get_temp_dir() . '/agent-loop-policy-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($outside, '{}');
        symlink($outside, $this->root . '/linked-policy.json');
        file_put_contents(
            $this->root . '/.agent-loop/init.json',
            json_encode([
                'version' => 1,
                'recall' => ['document_manifests' => ['linked-policy.json']],
            ], JSON_THROW_ON_ERROR),
        );

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('recall document manifest escapes the project');

            (new ProjectLayout($this->root))->recallDocumentManifests();
        } finally {
            unlink($outside);
        }
    }

    public function testAnAbsoluteOverrideOutsideTheProjectIsKeptAbsolute(): void
    {
        $elsewhere = sys_get_temp_dir() . '/agent-loop-elsewhere-' . bin2hex(random_bytes(4));
        $this->writeConfig(['learning_root' => $elsewhere]);
        $this->trustExternalStatePaths([$elsewhere]);

        self::assertSame($elsewhere, (new ProjectLayout($this->root))->learningRoot());
    }

    public function testEveryExternalWorkflowStateOverrideNeedsAnExactOperatorAllowlistEntry(): void
    {
        $outside = $this->root . '-external-state';
        $rootMethods = [
            'state_root' => 'stateRoot',
            'board_root' => 'boardRoot',
            'sessions_root' => 'sessionsRoot',
            'learning_root' => 'learningRoot',
            'recall_root' => 'recallRoot',
            'map_root' => 'mapRoot',
        ];

        foreach ($rootMethods as $configKey => $method) {
            putenv('AGENT_LOOP_TRUSTED_EXTERNAL_STATE_PATHS');
            $this->writeConfig([$configKey => $outside]);
            $layout = new ProjectLayout($this->root);

            try {
                self::assertSame('', $layout->{$method}());
                self::fail($configKey . ' must not redirect workflow state outside the project without explicit trust.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('AGENT_LOOP_TRUSTED_EXTERNAL_STATE_PATHS', $exception->getMessage());
            }

            $this->trustExternalStatePaths([$outside]);
            self::assertSame($outside, $layout->{$method}());
        }
    }

    public function testExternalWorkflowPathAllowlistUsesExactPathMatches(): void
    {
        $outside = $this->root . '-external-state';
        $this->writeConfig(['state_root' => $outside]);
        $this->trustExternalStatePaths([$this->root]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside the project');

        self::assertSame('', (new ProjectLayout($this->root))->stateRoot());
    }

    public function testTraversalAndSymlinkWorkflowRootsNeedCanonicalPathTrust(): void
    {
        $outside = $this->root . '-external-state';
        mkdir($outside, 0o775, true);
        $link = $this->root . '/linked-state';
        if (!@symlink($outside, $link)) {
            $this->rm($outside);
            self::markTestSkipped('symlink() is not available.');
        }

        try {
            $this->writeConfig(['state_root' => '../' . basename($outside)]);
            try {
                self::assertSame('', (new ProjectLayout($this->root))->stateRoot());
                self::fail('A parent traversal in state_root must require explicit trust.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('outside the project', $exception->getMessage());
            }

            putenv('AGENT_LOOP_TRUSTED_EXTERNAL_STATE_PATHS');
            $this->writeConfig(['state_root' => 'linked-state']);
            try {
                self::assertSame('', (new ProjectLayout($this->root))->stateRoot());
                self::fail('A symlinked state_root that resolves outside the project must require explicit trust.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('outside the project', $exception->getMessage());
            }

            $this->trustExternalStatePaths([$outside]);
            self::assertSame($link, (new ProjectLayout($this->root))->stateRoot());

            $outsideSubdirectory = $outside . '/subdirectory';
            mkdir($outsideSubdirectory, 0o775, true);
            $traversalLink = $this->root . '/jump';
            if (!@symlink($outsideSubdirectory, $traversalLink)) {
                self::markTestSkipped('symlink() is not available.');
            }
            putenv('AGENT_LOOP_TRUSTED_EXTERNAL_STATE_PATHS');
            $this->writeConfig(['state_root' => 'jump/../loop-state']);
            try {
                self::assertSame('', (new ProjectLayout($this->root))->stateRoot());
                self::fail('A symlink followed by parent traversal must resolve before containment is checked.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('outside the project', $exception->getMessage());
            } finally {
                unlink($traversalLink);
            }
        } finally {
            putenv('AGENT_LOOP_TRUSTED_EXTERNAL_STATE_PATHS');
            unlink($link);
            $this->rm($outside);
        }
    }

    public function testDefaultAgentLoopDirectoryCannotBeAWriteThroughSymlink(): void
    {
        $outside = $this->root . '-external-state';
        mkdir($outside, 0o775, true);
        rmdir($this->root . '/.agent-loop');
        $link = $this->root . '/.agent-loop';
        if (!@symlink($outside, $link)) {
            $this->rm($outside);
            self::markTestSkipped('symlink() is not available.');
        }

        try {
            self::assertSame('', (new ProjectLayout($this->root))->stateRoot());
            self::fail('The default state root must not follow a tracked symlink outside the project.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('outside the project', $exception->getMessage());
        } finally {
            unlink($link);
            $this->rm($outside);
        }
    }

    public function testDefaultBoardCannotResolveItsTodoDirectoryThroughAnExternalSymlink(): void
    {
        $outside = $this->root . '-external-board';
        mkdir($outside, 0o775, true);
        $todoLink = $this->root . '/todo';
        if (!@symlink($outside, $todoLink)) {
            $this->rm($outside);
            self::markTestSkipped('symlink() is not available.');
        }

        try {
            self::assertSame('', (new ProjectLayout($this->root))->boardRoot());
            self::fail('The default board must not resolve an out-of-project todo symlink.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Board path escapes its configured root', $exception->getMessage());
        } finally {
            unlink($todoLink);
            $this->rm($outside);
        }
    }

    public function testTrustedBoardRootCannotRedirectTodoOutsideThatRoot(): void
    {
        $outside = $this->root . '-external-board';
        $escape = $this->root . '-board-escape';
        mkdir($outside, 0o775, true);
        mkdir($escape, 0o775, true);
        $todoLink = $outside . '/todo';
        if (!@symlink($escape, $todoLink)) {
            $this->rm($outside);
            $this->rm($escape);
            self::markTestSkipped('symlink() is not available.');
        }
        $this->writeConfig(['board_root' => $outside]);
        $this->trustExternalStatePaths([$outside]);

        try {
            self::assertSame('', (new ProjectLayout($this->root))->boardRoot());
            self::fail('An approved board root must not approve an external todo symlink.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Board path escapes its configured root', $exception->getMessage());
        } finally {
            unlink($todoLink);
            $this->rm($outside);
            $this->rm($escape);
        }
    }

    public function testBoardRootRejectsNestedSymlinks(): void
    {
        $outside = $this->root . '-external-board-task.md';
        mkdir($this->root . '/todo', 0o775, true);
        file_put_contents($outside, 'task');
        $taskLink = $this->root . '/todo/external.md';
        if (!@symlink($outside, $taskLink)) {
            unlink($outside);
            self::markTestSkipped('symlink() is not available.');
        }

        try {
            try {
                $resolvedRoot = (new ProjectLayout($this->root))->boardRoot();
                self::assertSame($this->root, $resolvedRoot);
                self::fail('Board readers and writers must not follow linked task files.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('Board tree', $exception->getMessage());
            }
        } finally {
            unlink($taskLink);
            unlink($outside);
        }
    }

    public function testStateDirectoriesCannotFollowSymlinksOutsideTheStateRoot(): void
    {
        $outside = $this->root . '-external-state';
        mkdir($outside, 0o775, true);
        $sessionsLink = $this->root . '/.agent-loop/sessions';
        $dreamLink = $this->root . '/.agent-loop/dream';
        if (!@symlink($outside, $sessionsLink) || !@symlink($outside, $dreamLink)) {
            if (is_link($sessionsLink)) {
                unlink($sessionsLink);
            }
            $this->rm($outside);
            self::markTestSkipped('symlink() is not available.');
        }

        try {
            try {
                self::assertSame('', (new ProjectLayout($this->root))->sessionsRoot());
                self::fail('A state directory symlink must not redirect SessionStore outside the state root.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('symbolic link', $exception->getMessage());
            }

            try {
                (new ProjectLayout($this->root))->dreamAutoRunPath();
                self::fail('A dream state symlink must not redirect SessionStart writes outside the state root.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('symbolic link', $exception->getMessage());
            }
        } finally {
            unlink($sessionsLink);
            unlink($dreamLink);
            $this->rm($outside);
        }
    }

    public function testTrustedExternalStateRootStillContainsItsDerivedDirectories(): void
    {
        $outside = $this->root . '-external-state';
        $escape = $this->root . '-escape';
        mkdir($outside, 0o775, true);
        mkdir($escape, 0o775, true);
        $sessionsLink = $outside . '/sessions';
        if (!@symlink($escape, $sessionsLink)) {
            $this->rm($outside);
            $this->rm($escape);
            self::markTestSkipped('symlink() is not available.');
        }
        $this->writeConfig(['state_root' => $outside]);
        $this->trustExternalStatePaths([$outside]);

        try {
            self::assertSame('', (new ProjectLayout($this->root))->sessionsRoot());
            self::fail('An approved external root must not approve a child symlink outside that root.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('symbolic link', $exception->getMessage());
        } finally {
            unlink($sessionsLink);
            $this->rm($outside);
            $this->rm($escape);
        }
    }

    public function testSessionTreeRejectsLinkedSessionDirectoriesAndMetadata(): void
    {
        $outside = $this->root . '-session-target';
        $sessionsRoot = (new ProjectLayout($this->root))->sessionsRoot();
        mkdir($outside, 0o775, true);
        mkdir($sessionsRoot, 0o775, true);
        file_put_contents($outside . '/session.json', '{}');
        $sessionLink = $sessionsRoot . '/linked-session';
        if (!@symlink($outside, $sessionLink)) {
            $this->rm($outside);
            self::markTestSkipped('symlink() is not available.');
        }

        try {
            try {
                $resolvedSessionsRoot = (new ProjectLayout($this->root))->sessionsRoot();
                self::assertDirectoryExists($resolvedSessionsRoot);
                self::fail('Session enumeration must reject linked session directories.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('symbolic links', $exception->getMessage());
            }
        } finally {
            unlink($sessionLink);
            $this->rm($outside);
        }

        mkdir($outside, 0o775, true);
        file_put_contents($outside . '/session.json', '{}');
        mkdir($sessionsRoot . '/real-session', 0o775, true);
        if (!@symlink($outside . '/session.json', $sessionsRoot . '/real-session/session.json')) {
            $this->rm($sessionsRoot . '/real-session');
            self::markTestSkipped('symlink() is not available.');
        }
        try {
            $resolvedSessionsRoot = (new ProjectLayout($this->root))->sessionsRoot();
            self::assertDirectoryExists($resolvedSessionsRoot);
            self::fail('Session loading and writes must reject linked metadata files.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('symbolic link', $exception->getMessage());
        } finally {
            unlink($sessionsRoot . '/real-session/session.json');
            rmdir($sessionsRoot . '/real-session');
            $this->rm($outside);
        }

        mkdir($outside, 0o775, true);
        file_put_contents($outside . '/checkpoint.json', '{}');
        $nestedDirectory = $sessionsRoot . '/real-session/checkpoints';
        mkdir($nestedDirectory, 0o775, true);
        $nestedLink = $nestedDirectory . '/checkpoint.md';
        if (!@symlink($outside . '/checkpoint.json', $nestedLink)) {
            $this->rm($sessionsRoot . '/real-session');
            $this->rm($outside);
            self::markTestSkipped('symlink() is not available.');
        }
        try {
            $resolvedRoot = (new ProjectLayout($this->root))->sessionsRoot();
            self::assertSame($sessionsRoot, $resolvedRoot);
            self::fail('Session operations must reject nested links used for checkpoint and evidence paths.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('symbolic link', $exception->getMessage());
        } finally {
            unlink($nestedLink);
            rmdir($nestedDirectory);
            rmdir($sessionsRoot . '/real-session');
            $this->rm($outside);
        }
    }

    public function testRunManifestCannotResolveThroughAnExternalRunDirectorySymlink(): void
    {
        $outside = $this->root . '-external-run';
        mkdir($outside, 0o775, true);
        $runsRoot = (new ProjectLayout($this->root))->runsRoot();
        mkdir($runsRoot, 0o775, true);
        $runLink = $runsRoot . '/ABC-123';
        if (!@symlink($outside, $runLink)) {
            $this->rm($outside);
            self::markTestSkipped('symlink() is not available.');
        }

        try {
            self::assertFalse((new ProjectLayout($this->root))->isRunPathWithinRunsRoot($runLink . '/manifest.json'));
        } finally {
            unlink($runLink);
            $this->rm($outside);
        }
    }

    public function testRunFilesCannotFollowSymlinksOutsideTheirStateOrTaskRoot(): void
    {
        $outside = $this->root . '-external-run-file.json';
        file_put_contents($outside, '{"kind":"external"}');
        $runDirectory = $this->root . '/.agent-loop/runs/ABC-123';
        $otherRunDirectory = $this->root . '/.agent-loop/runs/OTHER-456';
        mkdir($runDirectory, 0o775, true);
        mkdir($otherRunDirectory, 0o775, true);
        $externalLink = $runDirectory . '/run.json';
        $otherRunFile = $otherRunDirectory . '/manifest.json';
        $taskLink = $runDirectory . '/manifest.json';
        file_put_contents($otherRunFile, '{"kind":"other-run"}');
        if (!@symlink($outside, $externalLink) || !@symlink($otherRunFile, $taskLink)) {
            foreach ([$externalLink, $taskLink] as $link) {
                if (is_link($link)) {
                    unlink($link);
                }
            }
            unlink($outside);
            self::markTestSkipped('symlink() is not available.');
        }

        try {
            try {
                (new ProjectLayout($this->root))->runFile('ABC-123', 'run.json');
                self::fail('A run file symlink must not read outside the configured state root.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('symbolic link', $exception->getMessage());
            }

            try {
                (new ProjectLayout($this->root))->runFile('ABC-123', 'manifest.json');
                self::fail('A run file symlink must not read another task directory.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('symbolic link', $exception->getMessage());
            }
        } finally {
            unlink($externalLink);
            unlink($taskLink);
            unlink($otherRunFile);
            unlink($outside);
        }
    }

    public function testTaskDirectorySymlinksCannotAliasAnotherRun(): void
    {
        $runsRoot = (new ProjectLayout($this->root))->runsRoot();
        mkdir($runsRoot . '/OTHER-456', 0o775, true);
        file_put_contents($runsRoot . '/OTHER-456/run.json', '{"task_id":"OTHER-456"}');
        if (!@symlink($runsRoot . '/OTHER-456', $runsRoot . '/ABC-123')) {
            self::markTestSkipped('symlink() is not available.');
        }

        try {
            $layout = new ProjectLayout($this->root);
            foreach ([
                fn (): string => $layout->runFile('ABC-123', 'run.json'),
                fn (): string => $layout->executionPlanPath('ABC-123'),
                fn (): string => $layout->executionStatePath('ABC-123'),
                fn (): string => $layout->executionStateLockPath('ABC-123'),
                fn (): string => $layout->executionEvidenceRoot('ABC-123'),
                fn (): string => $layout->executionAttentionResolutionsRoot('ABC-123'),
            ] as $resolve) {
                try {
                    $resolve();
                    self::fail('A task directory symlink must not alias another task run.');
                } catch (RuntimeException $exception) {
                    self::assertStringContainsString('symbolic link', $exception->getMessage());
                }
            }
        } finally {
            unlink($runsRoot . '/ABC-123');
            $this->rm($runsRoot . '/OTHER-456');
        }
    }

    public function testLearningAndRecallTaskDirectoriesRejectSymlinks(): void
    {
        $outside = $this->root . '-outside-learning-recall';
        mkdir($outside, 0o775, true);
        $learningLink = $this->root . '/.agent-loop/learning/findings';
        $handoffLink = $this->root . '/.agent-loop/recall/ABC-123';
        mkdir(dirname($learningLink), 0o775, true);
        mkdir(dirname($handoffLink), 0o775, true);
        if (!@symlink($outside, $learningLink) || !@symlink($outside, $handoffLink)) {
            foreach ([$learningLink, $handoffLink] as $link) {
                if (is_link($link)) {
                    unlink($link);
                }
            }
            $this->rm($outside);
            self::markTestSkipped('symlink() is not available.');
        }

        try {
            $layout = new ProjectLayout($this->root);
            try {
                $layout->learningSubdirectory('findings');
                self::fail('Dream must not fingerprint files through a linked Learning child.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('symbolic link', $exception->getMessage());
            }
            try {
                $layout->recallHandoffDirectory('ABC-123');
                self::fail('Recall handoff output must not follow a task directory symlink.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('symbolic link', $exception->getMessage());
            }
        } finally {
            unlink($learningLink);
            unlink($handoffLink);
            $this->rm($outside);
        }
    }

    public function testRecallHandoffDirectoryRejectsLinkedOutputFiles(): void
    {
        $outside = $this->root . '-handoff-target.md';
        file_put_contents($outside, 'outside');
        $handoff = $this->root . '/.agent-loop/recall/ABC-123/handoff';
        mkdir($handoff, 0o775, true);
        if (!@symlink($outside, $handoff . '/system.md')) {
            unlink($outside);
            self::markTestSkipped('symlink() is not available.');
        }

        try {
            try {
                (new ProjectLayout($this->root))->recallHandoffDirectory('ABC-123');
                self::fail('Recall must not overwrite a linked handoff output file.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('symbolic links', $exception->getMessage());
            }
        } finally {
            unlink($handoff . '/system.md');
            unlink($outside);
        }
    }

    public function testAcceptedRiskAndDiagnosticPathsRejectLinkedFiles(): void
    {
        $outside = $this->root . '-outside-state-file.json';
        file_put_contents($outside, '{}');
        $layout = new ProjectLayout($this->root);
        $riskPath = $this->root . '/.agent-loop/risks/ABC-123.accepted-risk.json';
        $diagnosticPath = $this->root . '/.agent-loop/diagnostics/ABC-123.json';
        mkdir(dirname($riskPath), 0o775, true);
        mkdir(dirname($diagnosticPath), 0o775, true);
        if (!@symlink($outside, $riskPath) || !@symlink($outside, $diagnosticPath)) {
            foreach ([$riskPath, $diagnosticPath] as $link) {
                if (is_link($link)) {
                    unlink($link);
                }
            }
            unlink($outside);
            self::markTestSkipped('symlink() is not available.');
        }

        try {
            foreach ([
                fn (): string => $layout->acceptedRiskPath('ABC-123', 'json'),
                fn (): string => $layout->diagnosticPath('ABC-123'),
            ] as $resolve) {
                try {
                    $resolve();
                    self::fail('A state writer must not follow a linked leaf file.');
                } catch (RuntimeException $exception) {
                    self::assertStringContainsString('symbolic link', $exception->getMessage());
                }
            }
        } finally {
            unlink($riskPath);
            unlink($diagnosticPath);
            unlink($outside);
        }
    }

    public function testContractHistoryRejectsLinkedDirectoriesAndFiles(): void
    {
        $outside = $this->root . '-outside-contract-history';
        mkdir($outside, 0o775, true);
        $layout = new ProjectLayout($this->root);
        $historyRoot = $this->root . '/.agent-loop/contracts/ABC-123/history';
        mkdir(dirname($historyRoot), 0o775, true);
        if (!@symlink($outside, $historyRoot)) {
            $this->rm($outside);
            self::markTestSkipped('symlink() is not available.');
        }

        try {
            try {
                $layout->contractHistoryRoot('ABC-123');
                self::fail('Contract history must not follow a linked directory.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('symbolic link', $exception->getMessage());
            }
        } finally {
            unlink($historyRoot);
        }

        mkdir($historyRoot, 0o775, true);
        $historyFile = $historyRoot . '/contract.001.json';
        file_put_contents($outside . '/contract.001.json', '{}');
        if (!@symlink($outside . '/contract.001.json', $historyFile)) {
            $this->rm($outside);
            self::markTestSkipped('symlink() is not available.');
        }
        try {
            try {
                $layout->contractHistoryFile('ABC-123', 'contract.001.json');
                self::fail('Contract history must not follow a linked artifact.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('symbolic link', $exception->getMessage());
            }
        } finally {
            unlink($historyFile);
            $this->rm($outside);
        }
    }

    public function testInitPathsReportsTheLayoutAsJsonForAgents(): void
    {
        $this->writeConfig(['state_root' => 'var/agent-state']);

        ob_start();
        $exit = (new InitPathsCommand($this->root))->run(['--format=json']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        $paths = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($paths);
        self::assertSame('var/agent-state', $paths['state_root'] ?? null);
        self::assertSame('var/agent-state/runs', $paths['runs_root'] ?? null);
        self::assertSame('var/agent-state/contracts', $paths['contracts_root'] ?? null);
    }

    public function testInitPathsRejectsAnUnknownOption(): void
    {
        ob_start();
        $exit = (new InitPathsCommand($this->root))->run(['--bogus']);
        ob_end_clean();

        self::assertSame(1, $exit);
    }

    /** @param array<string, string> $paths */
    private function writeConfig(array $paths): void
    {
        file_put_contents(
            $this->root . '/.agent-loop/init.json',
            json_encode(['version' => 1, 'paths' => $paths], JSON_THROW_ON_ERROR),
        );
    }

    /** @param list<string> $paths */
    private function trustExternalStatePaths(array $paths): void
    {
        putenv('AGENT_LOOP_TRUSTED_EXTERNAL_STATE_PATHS=' . json_encode($paths, JSON_THROW_ON_ERROR));
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
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }

    public function testEditBundlesDiscoversMatchingNonEmptyBundlesAndIgnoresEmptyDirectories(): void
    {
        $layout = new ProjectLayout($this->root);
        $editRoot = $layout->editRoot();
        mkdir($editRoot . '/ABC-123', 0o775, true); // empty directory
        mkdir($editRoot . '/ABC-123-part1', 0o775, true);
        file_put_contents($editRoot . '/ABC-123-part1/execution.json', '{}');
        mkdir($editRoot . '/ABC-123-part2', 0o775, true);
        file_put_contents($editRoot . '/ABC-123-part2/verification-result.json', '{}');
        mkdir($editRoot . '/OTHER-456', 0o775, true);
        file_put_contents($editRoot . '/OTHER-456/execution.json', '{}');

        $bundles = $layout->editBundles('ABC-123');
        self::assertSame([
            $editRoot . '/ABC-123-part1',
            $editRoot . '/ABC-123-part2',
        ], $bundles);
    }
}
