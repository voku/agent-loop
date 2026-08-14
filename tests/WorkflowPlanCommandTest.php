<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\AnalysisFingerprint;
use voku\AgentMap\Index\IndexWriter;
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

    public function testApproveCreatesRunAndSessionWithoutSessionOwnedContractCopy(): void
    {
        $root = $this->root('approve');
        $contracts = new TaskContractStore($root);
        $contracts->create('ABC-123', 'Keep scope reviewable.', ['src/Foo.php'], [], ['vendor/bin/phpunit'], 'planner');
        /** @var list<list<string>> $recallCalls */
        $recallCalls = [];
        $command = new WorkflowApproveCommand(
            $root,
            static function (array $argv) use (&$recallCalls): int {
                $recallCalls[] = $argv;

                return 0;
            },
        );

        try {
            ob_start();
            $exit = $command->run(['ABC-123', '--by', 'lars']);
            $output = (string) ob_get_clean();

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
            self::assertSame([
                ['compile', '--root', $root . '/.agent-loop/learning', '--task', 'ABC-123', '--task-brief', $recallInput],
            ], $recallCalls);
            self::assertStringContainsString('governed Run', $output);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testApproveBuildsRecallInputFromOwnedArtifacts(): void
    {
        $root = $this->root('recall-input');
        $learningRoot = $root . '/.agent-loop/learning';
        mkdir($root . '/.agent-loop/todo/cards', 0o775, true);
        mkdir($root . '/.agent-loop/map', 0o775, true);
        mkdir($root . '/docs/agents', 0o775, true);
        mkdir($learningRoot, 0o775, true);
        file_put_contents($root . '/.agent-loop/init.json', json_encode([
            'version' => 1,
            'recall' => ['document_manifests' => ['docs/agents/recall-documents.json']],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($root . '/docs/agents/recall-documents.json', json_encode([
            'schema_version' => '1.0',
            'documents' => [],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($root . '/.agent-loop/todo/kanban.config.json', json_encode(['projectPrefix' => 'ABC'], JSON_THROW_ON_ERROR));
        file_put_contents($root . '/.agent-loop/todo/cards/ABC-123.md', <<<'CARD'
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
CARD
);
        $this->writeReadyMapAndSearch($root, 'sha256:current');
        file_put_contents($learningRoot . '/recall-documents.json', json_encode([
            'schema_version' => '1.0',
            'documents' => [],
        ], JSON_THROW_ON_ERROR));

        $contracts = new TaskContractStore($root);
        $contracts->create('ABC-123', 'Keep scope reviewable.', ['src/Foo.php'], [], ['vendor/bin/phpunit'], 'lars');
        /** @var list<list<string>> $recallCalls */
        $recallCalls = [];
        $command = new WorkflowApproveCommand(
            $root,
            static function (array $argv) use (&$recallCalls): int {
                $recallCalls[] = $argv;

                return 0;
            },
        );

        try {
            ob_start();
            self::assertSame(0, $command->run(['ABC-123', '--by', 'lars']));
            ob_end_clean();

            $sessions = (new SessionStore())->all($root . '/.agent-loop/sessions');
            self::assertCount(1, $sessions);
            $contextPath = $sessions[0]->path . '/kanban-context.json';
            $recallInput = $root . '/.agent-loop/runs/ABC-123/recall-input.json';
            self::assertFileExists($contextPath);
            self::assertFileExists($recallInput);
            self::assertSame([[
                'compile', '--root', $learningRoot,
                '--task', 'ABC-123', '--task-brief', $recallInput,
                '--document-manifest', $root . '/docs/agents/recall-documents.json',
                '--document-manifest', $learningRoot . '/recall-documents.json',
                '--kanban-context', $contextPath,
                '--map-index', $root . '/.agent-loop/map/php-symbols.json', '--map-root', $root,
                '--map-search-index', $root . '/.agent-loop/map/search.sqlite',
            ]], $recallCalls);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testApproveCanResumeRecallAfterCompilationFailureWithoutRecreatingRun(): void
    {
        $root = $this->root('resume');
        $contracts = new TaskContractStore($root);
        $contracts->create('ABC-123', 'Keep scope reviewable.', ['src/Foo.php'], [], ['vendor/bin/phpunit'], 'lars');

        try {
            ob_start();
            $firstExit = (new WorkflowApproveCommand($root, static fn (array $argv): int => 7))->run([
                'ABC-123', '--by', 'lars',
            ]);
            ob_end_clean();

            self::assertSame(7, $firstExit);
            self::assertSame(TaskContract::APPROVED, $contracts->load('ABC-123')->status);
            $sessions = (new SessionStore())->all($root . '/.agent-loop/sessions');
            self::assertCount(1, $sessions);
            $firstRun = (new GovernedRunStore($root))->find('ABC-123');
            self::assertNotNull($firstRun);

            ob_start();
            $secondExit = (new WorkflowApproveCommand($root, static fn (array $argv): int => 0))->run([
                'ABC-123', '--by', 'lars',
            ]);
            $output = (string) ob_get_clean();

            self::assertSame(0, $secondExit);
            self::assertStringContainsString('already approved; resuming Run preparation', $output);
            self::assertSame($firstRun->runId, (new GovernedRunStore($root))->find('ABC-123')?->runId);
            self::assertCount(1, (new SessionStore())->all($root . '/.agent-loop/sessions'));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testApproveRejectsMissingConfiguredRecallDocumentManifestBeforeCompilation(): void
    {
        $root = $this->root('missing-project-policy');
        mkdir($root . '/.agent-loop', 0o775, true);
        file_put_contents($root . '/.agent-loop/init.json', json_encode([
            'version' => 1,
            'recall' => ['document_manifests' => ['docs/agents/missing.json']],
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
        $command = new WorkflowApproveCommand(
            $root,
            static function (array $argv) use (&$recallCalled): int {
                $recallCalled = true;

                return 0;
            },
        );

        try {
            ob_start();
            self::assertSame(1, $command->run(['ABC-123', '--by', 'lars']));
            ob_end_clean();

            self::assertFalse($recallCalled);
        } finally {
            $this->removeDirectory($root);
        }
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
