<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\RecallOutputRoot;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\AnalysisFingerprint;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Search\ChunkPolicy;
use voku\AgentRecallCompiler\CompileRequest;
use voku\AgentRecallCompiler\CompileResult;
use voku\AgentSession\SessionStore;

final class WorkflowPlanCommandTest extends TestCase
{
    public function testPlanPersistsCandidateContractWithoutSession(): void
    {
        $root = $this->root('plan');

        try {
            ob_start();
            $exit = (new WorkflowPlanCommand($root))->run([
                'ABC-123',
                '--by', 'lars',
                '--file', 'src/Foo.php',
                '--goal', 'Keep scope reviewable.',
                '--scope', 'src/Foo.php',
                '--non-goal', 'No new memory layer.',
                '--validation', 'vendor/bin/phpunit tests/FooTest.php',
                '--tag', 'identity',
                '--behavior-anchor', 'POST request -> FooAction -> persisted state',
                '--base-commit', 'abc123',
            ]);
            $output = (string) ob_get_clean();

            self::assertSame(0, $exit);
            self::assertSame([], (new SessionStore())->all($root . '/.agent-loop/sessions'));

            $contract = (new TaskContractStore($root))->load('ABC-123');
            self::assertSame(TaskContract::CANDIDATE, $contract->status);
            self::assertSame('Keep scope reviewable.', $contract->goal);
            self::assertSame(['src/Foo.php'], $contract->scope);
            self::assertSame(['No new memory layer.'], $contract->nonGoals);
            self::assertSame(['vendor/bin/phpunit tests/FooTest.php'], $contract->validation);
            self::assertSame(['identity'], $contract->tags);
            self::assertSame(['POST request -> FooAction -> persisted state'], $contract->behaviorAnchors);
            self::assertSame('lars', $contract->plannedBy);
            self::assertSame('abc123', $contract->baseCommit);
            self::assertStringContainsString('candidate Contract created', $output);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testPlanUsesFilesAsDefaultScopeAndRejectsEphemeralWorkflowPlans(): void
    {
        $root = $this->root('scope');

        try {
            ob_start();
            $exit = (new WorkflowPlanCommand($root))->run([
                'ABC-123', '--by', 'lars',
                '--file', 'src/Foo.php', '--file', 'tests/FooTest.php',
                '--goal', 'Keep scope reviewable.', '--validation', 'vendor/bin/phpunit',
            ]);
            ob_end_clean();

            self::assertSame(0, $exit);
            self::assertSame(
                ['src/Foo.php', 'tests/FooTest.php'],
                (new TaskContractStore($root))->load('ABC-123')->scope,
            );
            self::assertSame([], (new SessionStore())->all($root . '/.agent-loop/sessions'));

            ob_start();
            $ephemeralExit = (new WorkflowPlanCommand($root))->run([
                'EXP-1', '--by', 'lars',
                '--file', 'src/Foo.php', '--goal', 'Experiment.', '--validation', 'vendor/bin/phpunit', '--ephemeral',
            ]);
            ob_end_clean();
            self::assertSame(1, $ephemeralExit);
            self::assertNull((new TaskContractStore($root))->find('EXP-1'));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testPlanValidatesRequiredInputsBeforeWriting(): void
    {
        $root = $this->root('invalid');

        try {
            ob_start();
            $exit = (new WorkflowPlanCommand($root))->run([
                'ABC-123', '--by', 'lars',
                '--file', 'src/Foo.php', '--goal', 'Goal',
            ]);
            ob_end_clean();

            self::assertSame(1, $exit);
            self::assertNull((new TaskContractStore($root))->find('ABC-123'));
            self::assertSame([], (new SessionStore())->all($root . '/.agent-loop/sessions'));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testPlanRevisionInvalidatesApprovalWithoutStartingSession(): void
    {
        $root = $this->root('revise');
        $contracts = new TaskContractStore($root);
        $contracts->create('ABC-123', 'Initial scope.', ['src/Foo.php'], [], ['vendor/bin/phpunit'], 'lars');
        $contracts->approve('ABC-123', 'lars');

        try {
            ob_start();
            $exit = (new WorkflowPlanCommand($root))->run([
                'ABC-123', '--by', 'lars',
                '--file', 'src/Foo.php', '--file', 'tests/FooTest.php',
                '--goal', 'Expanded scope.', '--validation', 'vendor/bin/phpunit tests/FooTest.php',
            ]);
            $output = (string) ob_get_clean();

            self::assertSame(0, $exit);
            self::assertSame([], (new SessionStore())->all($root . '/.agent-loop/sessions'));
            $contract = $contracts->load('ABC-123');
            self::assertSame(2, $contract->revision);
            self::assertSame(TaskContract::CANDIDATE, $contract->status);
            self::assertSame(['src/Foo.php', 'tests/FooTest.php'], $contract->scope);
            self::assertNull($contract->approvedBy);
            self::assertFileExists($root . '/.agent-loop/contracts/ABC-123/history/contract.001.json');
            self::assertStringContainsString('candidate Contract revised', $output);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testEnterCreatesRunAndSessionWithoutSessionOwnedContractCopy(): void
    {
        $root = $this->root('enter');
        $contracts = new TaskContractStore($root);
        $contracts->create('ABC-123', 'Keep scope reviewable.', ['src/Foo.php'], [], ['vendor/bin/phpunit'], 'planner');
        /** @var list<CompileRequest> $recallCalls */
        $recallCalls = [];

        try {
            self::assertSame(0, $this->approve($root));
            $this->assertNoPreparedRunState($root);

            $exit = $this->enter(
                $root,
                function (CompileRequest $request) use (&$recallCalls, $root): CompileResult {
                    $recallCalls[] = $request;

                    return $this->compileRecallFixture($root, $request);
                },
            );

            self::assertSame(0, $exit);
            $contract = $contracts->load('ABC-123');
            self::assertSame(TaskContract::APPROVED, $contract->status);
            self::assertSame('lars', $contract->approvedBy);

            $sessions = (new SessionStore())->all($root . '/.agent-loop/sessions');
            self::assertCount(1, $sessions);
            self::assertFileDoesNotExist($sessions[0]->path . '/work-brief.json');
            self::assertFileDoesNotExist($sessions[0]->path . '/approval.json');

            $run = (new GovernedRunStore($root))->find('ABC-123');
            self::assertNotNull($run);
            self::assertSame($sessions[0]->id, $run->sessionId);
            self::assertSame(1, $run->contractRevision);
            $recallInput = $root . '/.agent-loop/runs/ABC-123/recall-input.json';
            self::assertFileExists($recallInput);
            self::assertCount(1, $recallCalls);
            self::assertSame($root . '/.agent-loop/learning', $recallCalls[0]->learningRoot);
            self::assertSame($recallInput, $recallCalls[0]->taskBrief);
            self::assertSame(RecallOutputRoot::resolve($root) . '/ABC-123', $recallCalls[0]->outputDirectory);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testEnterBuildsRecallInputFromOwnedArtifacts(): void
    {
        $root = $this->root('recall-input');
        $learningRoot = $root . '/.agent-loop/learning';
        mkdir($root . '/.agent-loop/todo/cards', 0o775, true);
        mkdir($root . '/.agent-loop/map', 0o775, true);
        mkdir($root . '/docs', 0o775, true);
        mkdir($learningRoot, 0o775, true);
        file_put_contents($root . '/.agent-loop/init.json', json_encode([
            'version' => 1,
            'recall' => ['document_manifests' => ['docs/recall-documents.json']],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($root . '/docs/recall-documents.json', json_encode([
            'schema_version' => '1.0',
            'documents' => [],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($root . '/.agent-loop/todo/kanban.config.json', json_encode(['projectPrefix' => 'ABC'], JSON_THROW_ON_ERROR));
        file_put_contents(
            $root . '/.agent-loop/todo/cards/ABC-123.md',
            <<<'CARD'
# ABC-123: Keep the view reviewable

- **Ticket:** ABC-123
- **Lane:** READY
- **Status:** Selected
- **Summary:** Preserve the existing view boundary.
- **Next:** Approve and compile the task context.
- **Validation:** vendor/bin/phpunit tests/FooTest.php
- **Priority:** 1

## Handoff / Context
Use the existing view factory seam.
CARD,
        );
        $this->writeReadyMapAndSearch($root, 'sha256:current');

        $contracts = new TaskContractStore($root);
        $contracts->create('ABC-123', 'Keep scope reviewable.', ['src/Foo.php'], [], ['vendor/bin/phpunit'], 'lars');
        /** @var list<CompileRequest> $recallCalls */
        $recallCalls = [];

        try {
            self::assertSame(0, $this->approve($root));
            self::assertSame(0, $this->enter(
                $root,
                function (CompileRequest $request) use (&$recallCalls, $root): CompileResult {
                    $recallCalls[] = $request;

                    return $this->compileRecallFixture($root, $request);
                },
            ));

            $sessions = (new SessionStore())->all($root . '/.agent-loop/sessions');
            self::assertCount(1, $sessions);
            self::assertFileDoesNotExist($sessions[0]->path . '/kanban-context.json');
            $recallInput = $root . '/.agent-loop/runs/ABC-123/recall-input.json';
            self::assertFileExists($recallInput);
            self::assertCount(1, $recallCalls);
            $request = $recallCalls[0];
            $kanbanContext = $request->kanbanContextProjection?->toArray();
            self::assertIsArray($kanbanContext);
            self::assertSame('ABC-123', $kanbanContext['task_id']);
            self::assertSame('todo/cards/ABC-123.md', $kanbanContext['source']['path']);
            self::assertSame(
                ['title', 'lane', 'status', 'priority', 'next_action'],
                array_keys($kanbanContext['card']),
            );
            self::assertSame($learningRoot, $request->learningRoot);
            self::assertSame($recallInput, $request->taskBrief);
            self::assertSame([$root . '/docs/recall-documents.json'], $request->documentManifests);
            self::assertSame($root . '/.agent-loop/map/php-symbols.json', $request->mapIndex);
            self::assertSame($root, $request->mapRoot);
            self::assertSame($root . '/.agent-loop/map/search.sqlite', $request->mapSearchIndex);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testEnterCanResumeRecallAfterCompilationFailureWithoutRecreatingRun(): void
    {
        $root = $this->root('resume');
        $contracts = new TaskContractStore($root);
        $contracts->create('ABC-123', 'Keep scope reviewable.', ['src/Foo.php'], [], ['vendor/bin/phpunit'], 'lars');

        try {
            self::assertSame(0, $this->approve($root));
            self::assertSame(1, $this->enter(
                $root,
                static fn (CompileRequest $request): CompileResult => throw new RuntimeException('Recall compilation refused by fixture.'),
            ));
            self::assertSame(TaskContract::APPROVED, $contracts->load('ABC-123')->status);
            $sessions = (new SessionStore())->all($root . '/.agent-loop/sessions');
            self::assertCount(1, $sessions);
            $firstRun = (new GovernedRunStore($root))->find('ABC-123');
            self::assertNotNull($firstRun);

            self::assertSame(0, $this->enter(
                $root,
                fn (CompileRequest $request): CompileResult => $this->compileRecallFixture($root, $request),
            ));

            self::assertSame($firstRun->runId, (new GovernedRunStore($root))->find('ABC-123')?->runId);
            self::assertCount(1, (new SessionStore())->all($root . '/.agent-loop/sessions'));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testEnterRejectsMissingConfiguredRecallDocumentManifestBeforeCompilation(): void
    {
        $root = $this->root('missing-project-policy');
        mkdir($root . '/.agent-loop', 0o775, true);
        file_put_contents($root . '/.agent-loop/init.json', json_encode([
            'version' => 1,
            'recall' => ['document_manifests' => ['docs/missing.json']],
        ], JSON_THROW_ON_ERROR));
        (new TaskContractStore($root))->create(
            'ABC-123',
            'Reject silently missing review policy.',
            ['src/Foo.php'],
            [],
            ['vendor/bin/phpunit'],
            'lars',
        );
        $recallCalled = false;

        try {
            self::assertSame(0, $this->approve($root));
            self::assertSame(1, $this->enter(
                $root,
                static function (CompileRequest $request) use (&$recallCalled): CompileResult {
                    $recallCalled = true;

                    throw new RuntimeException('Typed Recall compilation must not run when document policy is invalid.');
                },
            ));
            self::assertFalse($recallCalled);
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function assertNoPreparedRunState(string $root): void
    {
        self::assertSame([], (new SessionStore())->all($root . '/.agent-loop/sessions'));
        self::assertNull((new GovernedRunStore($root))->find('ABC-123'));
    }

    private function approve(string $root): int
    {
        ob_start();
        try {
            return (new WorkflowApproveCommand($root))->run(['ABC-123', '--by', 'lars']);
        } finally {
            ob_end_clean();
        }
    }

    /** @param callable(CompileRequest): CompileResult $compiler */
    private function enter(string $root, callable $compiler): int
    {
        ob_start();
        try {
            return (new HostFrontDoorCommand($root, recallCompiler: $compiler))
                ->run('enter', ['ABC-123', '--format=json']);
        } finally {
            ob_end_clean();
        }
    }

    private function compileRecallFixture(string $root, CompileRequest $request): CompileResult
    {
        $this->writeRecallMeta($root);

        return new CompileResult(
            $request->outputDirectory,
            'ABC-123-001',
            str_repeat('a', 64),
        );
    }

    private function writeRecallMeta(string $root): void
    {
        $directory = RecallOutputRoot::resolve($root) . '/ABC-123';
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create Recall fixture directory.');
        }
        file_put_contents(
            $directory . '/meta.json',
            json_encode([
                'schema_version' => '1.0',
                'task_id' => 'ABC-123',
                'compilation_id' => 'ABC-123-001',
                'bundle_sha256' => str_repeat('a', 64),
                'selected_guidance' => [],
                'selected_constraints' => [],
                'output_hashes' => [],
            ], JSON_THROW_ON_ERROR),
        );
    }

    private function writeReadyMapAndSearch(string $root, string $snapshot): void
    {
        (new IndexWriter())->write(
            new AgentMapIndex(
                schemaVersion: '2.0',
                root: $root,
                backend: 'test',
                files: [],
                fingerprint: new AnalysisFingerprint(
                    '2.2.0',
                    'sha256:config',
                    'sha256:lock',
                    $snapshot,
                ),
            ),
            $root . '/.agent-loop/map/php-symbols.json',
        );

        $pdo = new PDO(
            'sqlite:' . $root . '/.agent-loop/map/search.sqlite',
            null,
            null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $pdo->exec('CREATE TABLE search_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $statement = $pdo->prepare('INSERT INTO search_meta (key, value) VALUES (:key, :value)');
        $statement->execute(['key' => 'map_snapshot', 'value' => $snapshot]);
        $statement->execute(['key' => 'chunk_policy_version', 'value' => (string) ChunkPolicy::VERSION]);
        $pdo->exec('CREATE TABLE code_chunks (chunk_id TEXT PRIMARY KEY)');
    }

    public function testPlanWithJsonFlagOutputsStructuredJson(): void
    {
        $root = $this->root('plan-json');

        try {
            ob_start();
            $exit = (new WorkflowPlanCommand($root))->run([
                'ABC-JSON',
                '--by', 'lars',
                '--file', 'src/Foo.php',
                '--goal', 'Structured plan test.',
                '--validation', 'vendor/bin/phpunit',
                '--json',
            ]);
            $output = (string) ob_get_clean();

            self::assertSame(0, $exit);
            $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('1.0', $decoded['schema_version']);
            self::assertSame('workflow plan', $decoded['command']);
            self::assertSame('ABC-JSON', $decoded['task_id']);
            self::assertSame('created', $decoded['action']);
            self::assertSame('Structured plan test.', $decoded['goal']);
            self::assertSame('decision_required', $decoded['next_action_kind']);
            self::assertStringContainsString('agent-loop workflow approve ABC-JSON --by lars', $decoded['next_action']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function root(string $suffix): string
    {
        return sys_get_temp_dir() . '/agent-loop-' . $suffix . '-' . bin2hex(random_bytes(6));
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }
}
