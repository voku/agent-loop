<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;
use voku\AgentSession\SessionStore;
use voku\AgentSession\WorkBriefStatus;
use voku\AgentSession\WorkBriefStore;

final class WorkflowPlanCommandTest extends TestCase
{
    public function testPlanPersistsSessionAndCandidateBrief(): void
    {
        $root = $this->root('plan');

        try {
            $command = new WorkflowPlanCommand($root);

            ob_start();
            $exit = $command->run([
                'ABC-123',
                '--by', 'lars',
                '--learning-root', 'learn',
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
            $sessions = (new SessionStore())->all($root . '/session_plan');
            self::assertCount(1, $sessions);
            self::assertSame('ABC-123', $sessions[0]->taskId);
            self::assertSame('lars', $sessions[0]->claimedBy);
            self::assertSame('abc123', $sessions[0]->baseCommit);

            $brief = (new WorkBriefStore())->load($sessions[0]);
            self::assertSame(WorkBriefStatus::CANDIDATE, $brief->status);
            self::assertSame('Keep scope reviewable.', $brief->goal);
            self::assertSame(['src/Foo.php'], $brief->scope);
            self::assertSame(['No new memory layer.'], $brief->nonGoals);
            self::assertSame(['vendor/bin/phpunit tests/FooTest.php'], $brief->validation);
            self::assertSame(['identity'], $brief->tags);
            self::assertSame(['POST request -> FooAction -> persisted state'], $brief->behaviorAnchors);
            self::assertStringContainsString('candidate work brief created', $output);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testPlanUsesFilesAsDefaultScopeAndPreservesEphemeralMode(): void
    {
        $root = $this->root('scope');

        try {
            $command = new WorkflowPlanCommand($root);
            ob_start();
            $exit = $command->run([
                'ABC-123', '--by', 'lars', '--root', 'learn',
                '--file', 'src/Foo.php', '--file', 'tests/FooTest.php',
                '--goal', 'Keep scope reviewable.', '--validation', 'vendor/bin/phpunit', '--ephemeral',
            ]);
            ob_end_clean();

            self::assertSame(0, $exit);
            $sessions = (new SessionStore())->all($root . '/session_plan');
            self::assertCount(1, $sessions);
            self::assertTrue($sessions[0]->ephemeral);
            self::assertSame(
                ['src/Foo.php', 'tests/FooTest.php'],
                (new WorkBriefStore())->load($sessions[0])->scope,
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testPlanValidatesRequiredInputsBeforeWriting(): void
    {
        $root = $this->root('invalid');

        try {
            $command = new WorkflowPlanCommand($root);
            ob_start();
            $exit = $command->run([
                'ABC-123', '--by', 'lars', '--learning-root', 'learn',
                '--file', 'src/Foo.php', '--goal', 'Goal',
            ]);
            ob_end_clean();

            self::assertSame(1, $exit);
            self::assertSame([], (new SessionStore())->all($root . '/session_plan'));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testPlanRevisesExistingBriefWithoutStartingAnotherSession(): void
    {
        $root = $this->root('revise');
        $session = (new SessionStore())->create($root . '/session_plan', 'ABC-123');
        $briefs = new WorkBriefStore();
        $briefs->create($session, 'Initial scope.', ['src/Foo.php'], [], ['vendor/bin/phpunit']);
        $briefs->approve($session, 'lars');

        try {
            ob_start();
            $exit = (new WorkflowPlanCommand($root))->run([
                'ABC-123', '--by', 'lars', '--learning-root', 'learn',
                '--file', 'src/Foo.php', '--file', 'tests/FooTest.php',
                '--goal', 'Expanded scope.', '--validation', 'vendor/bin/phpunit tests/FooTest.php',
            ]);
            $output = (string) ob_get_clean();

            self::assertSame(0, $exit);
            self::assertCount(1, (new SessionStore())->all($root . '/session_plan'));
            $brief = $briefs->load($session);
            self::assertSame(2, $brief->revision);
            self::assertSame(WorkBriefStatus::CANDIDATE, $brief->status);
            self::assertSame(['src/Foo.php', 'tests/FooTest.php'], $brief->scope);
            self::assertNull($briefs->approval($session));
            self::assertStringContainsString('candidate work brief revised', $output);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testApprovePersistsApprovalAndDelegatesRecall(): void
    {
        $root = $this->root('approve');
        $session = (new SessionStore())->create($root . '/session_plan', 'ABC-123');
        $briefs = new WorkBriefStore();
        $briefs->create($session, 'Keep scope reviewable.', ['src/Foo.php'], [], ['vendor/bin/phpunit']);
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
            $exit = $command->run(['ABC-123', '--by', 'lars', '--learning-root', 'learn']);
            $output = (string) ob_get_clean();

            self::assertSame(0, $exit);
            self::assertSame(WorkBriefStatus::APPROVED, $briefs->load($session)->status);
            self::assertSame('lars', $briefs->approval($session)?->approvedBy);
            self::assertSame([
                ['compile', '--root', 'learn', '--task', 'ABC-123', '--task-brief', $session->path . '/work-brief.json'],
            ], $recallCalls);
            self::assertStringContainsString('work brief approved and recall compiled', $output);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testApproveBuildsRecallInputFromOwnedArtifacts(): void
    {
        $root = $this->root('recall-input');
        $learningRoot = $root . '/learning';
        mkdir($root . '/todo/cards', 0o775, true);
        mkdir($root . '/.agent-map', 0o775, true);
        mkdir($learningRoot, 0o775, true);
        file_put_contents($root . '/todo/kanban.config.json', json_encode(['projectPrefix' => 'ABC'], JSON_THROW_ON_ERROR));
        file_put_contents($root . '/todo/cards/ABC-123.md', <<<'CARD'
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

## Agent Task Brief
Touch only src/Foo.php and its focused test.
CARD
);
        file_put_contents($root . '/.agent-map/php-symbols.json', '{}');
        file_put_contents($root . '/.agent-map/search.sqlite', '');
        file_put_contents($learningRoot . '/recall-documents.json', json_encode([
            'schema_version' => '1.0',
            'documents' => [],
        ], JSON_THROW_ON_ERROR));

        $session = (new SessionStore())->create($root . '/session_plan', 'ABC-123');
        (new WorkBriefStore())->create($session, 'Keep scope reviewable.', ['src/Foo.php'], [], ['vendor/bin/phpunit']);
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
            self::assertSame(0, $command->run(['ABC-123', '--by', 'lars', '--learning-root', $learningRoot]));
            ob_end_clean();

            $contextPath = $session->path . '/kanban-context.json';
            self::assertFileExists($contextPath);
            self::assertSame([[
                'compile', '--root', $learningRoot,
                '--task', 'ABC-123', '--task-brief', $session->path . '/work-brief.json',
                '--document-manifest', $learningRoot . '/recall-documents.json',
                '--kanban-context', $contextPath,
                '--map-index', $root . '/.agent-map/php-symbols.json', '--map-root', $root,
                '--map-search-index', $root . '/.agent-map/search.sqlite',
            ]], $recallCalls);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testApproveCanResumeRecallAfterCompilationFailure(): void
    {
        $root = $this->root('resume');
        $session = (new SessionStore())->create($root . '/session_plan', 'ABC-123');
        $briefs = new WorkBriefStore();
        $briefs->create($session, 'Keep scope reviewable.', ['src/Foo.php'], [], ['vendor/bin/phpunit']);

        try {
            ob_start();
            $firstExit = (new WorkflowApproveCommand($root, static fn (array $argv): int => 7))->run([
                'ABC-123', '--by', 'lars', '--learning-root', 'learn',
            ]);
            ob_end_clean();

            self::assertSame(7, $firstExit);
            self::assertSame(WorkBriefStatus::APPROVED, $briefs->load($session)->status);
            self::assertSame(1, $briefs->approval($session)?->workBriefRevision);

            ob_start();
            $secondExit = (new WorkflowApproveCommand($root, static fn (array $argv): int => 0))->run([
                'ABC-123', '--by', 'lars', '--learning-root', 'learn',
            ]);
            $output = (string) ob_get_clean();

            self::assertSame(0, $secondExit);
            self::assertStringContainsString('already approved; resuming recall compilation', $output);
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
