<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;
use voku\AgentSession\SessionStore;
use voku\AgentSession\WorkBriefStore;

final class WorkflowPlanCommandTest extends TestCase
{
    public function testPlanStartsWorkflowAndCreatesCandidateBrief(): void
    {
        /** @var list<list<string>> $sessionCalls */
        $sessionCalls = [];
        /** @var list<list<string>> $recallCalls */
        $recallCalls = [];
        $command = new WorkflowPlanCommand(
            sys_get_temp_dir() . '/agent-loop-plan-command-empty',
            static function (array $argv) use (&$sessionCalls): int {
                $sessionCalls[] = $argv;

                return 0;
            },
        );

        ob_start();
        $exit = $command->run([
            'ABC-123', '--by', 'lars', '--learning-root', 'infra/doc/agent-learning',
            '--file', 'src/Foo.php', '--goal', 'Keep scope reviewable.',
            '--scope', 'src/Foo.php', '--non-goal', 'No new memory layer.',
            '--validation', 'vendor/bin/phpunit tests/FooTest.php', '--base-commit', 'abc123',
        ]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertSame([
            ['start', '--task', 'ABC-123', '--by', 'lars', '--base-commit', 'abc123'],
            ['brief', 'create', 'ABC-123', '--goal', 'Keep scope reviewable.', '--scope', 'src/Foo.php', '--non-goal', 'No new memory layer.', '--validation', 'vendor/bin/phpunit tests/FooTest.php'],
        ], $sessionCalls);
        self::assertSame([], $recallCalls);
        self::assertStringContainsString('candidate work brief created', $output);
    }

    public function testPlanUsesRecallFilesAsDefaultScope(): void
    {
        /** @var list<list<string>> $sessionCalls */
        $sessionCalls = [];
        $command = new WorkflowPlanCommand(
            sys_get_temp_dir() . '/agent-loop-plan-command-empty',
            static function (array $argv) use (&$sessionCalls): int {
                $sessionCalls[] = $argv;

                return 0;
            },
        );

        ob_start();
        $exit = $command->run(['ABC-123', '--by', 'lars', '--root', 'learn', '--file', 'src/Foo.php', '--file', 'tests/FooTest.php', '--goal', 'Keep scope reviewable.', '--validation', 'vendor/bin/phpunit']);
        ob_end_clean();

        self::assertSame(0, $exit);
        self::assertSame([
            'brief', 'create', 'ABC-123', '--goal', 'Keep scope reviewable.',
            '--scope', 'src/Foo.php', '--scope', 'tests/FooTest.php',
            '--validation', 'vendor/bin/phpunit',
        ], $sessionCalls[1]);
    }

    public function testPlanValidatesRequiredInputsBeforeItWrites(): void
    {
        $calls = 0;
        $command = new WorkflowPlanCommand(
            sys_get_temp_dir() . '/agent-loop-plan-command-empty',
            static function (array $argv) use (&$calls): int {
                ++$calls;

                return 0;
            },
        );

        ob_start();
        $exit = $command->run(['ABC-123', '--by', 'lars', '--learning-root', 'learn', '--file', 'src/Foo.php', '--goal', 'Goal']);
        ob_end_clean();

        self::assertSame(1, $exit);
        self::assertSame(0, $calls);
    }

    public function testApproveDelegatesApprovedWorkBriefToRecall(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-approve-' . bin2hex(random_bytes(6));
        mkdir($root . '/session_plan', 0o775, true);
        $session = (new SessionStore())->create($root . '/session_plan', 'ABC-123');
        (new WorkBriefStore())->create($session, 'Keep scope reviewable.', ['src/Foo.php'], [], ['vendor/bin/phpunit']);
        $sessionCalls = [];
        $recallCalls = [];
        $command = new WorkflowApproveCommand(
            $root,
            static function (array $argv) use (&$sessionCalls, $session): int {
                $sessionCalls[] = $argv;
                (new WorkBriefStore())->approve($session, 'lars');

                return 0;
            },
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
            self::assertSame([['brief', 'approve', 'ABC-123', '--by', 'lars']], $sessionCalls);
            self::assertSame([
                ['compile', '--root', 'learn', '--task', 'ABC-123', '--task-brief', $session->path . '/work-brief.json'],
            ], $recallCalls);
            self::assertStringContainsString('work brief approved and recall compiled', $output);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testApproveProjectsTypedKanbanContextWhenTheTaskHasACard(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-approve-kanban-' . bin2hex(random_bytes(6));
        mkdir($root . '/session_plan', 0o775, true);
        mkdir($root . '/todo/cards', 0o775, true);
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
        $session = (new SessionStore())->create($root . '/session_plan', 'ABC-123');
        (new WorkBriefStore())->create($session, 'Keep scope reviewable.', ['src/Foo.php'], [], ['vendor/bin/phpunit']);
        $recallCalls = [];
        $command = new WorkflowApproveCommand(
            $root,
            static function (array $argv) use ($session): int {
                (new WorkBriefStore())->approve($session, 'lars');

                return 0;
            },
            static function (array $argv) use (&$recallCalls): int {
                $recallCalls[] = $argv;

                return 0;
            },
        );

        try {
            ob_start();
            self::assertSame(0, $command->run(['ABC-123', '--by', 'lars', '--learning-root', 'learn']));
            ob_end_clean();

            $contextPath = $session->path . '/kanban-context.json';
            self::assertFileExists($contextPath);
            $context = json_decode((string) file_get_contents($contextPath), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('todo/cards/ABC-123.md', $context['source']['path']);
            self::assertSame('READY', $context['card']['lane']);
            self::assertSame([
                ['compile', '--root', 'learn', '--task', 'ABC-123', '--task-brief', $session->path . '/work-brief.json', '--kanban-context', $contextPath],
            ], $recallCalls);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testApprovePassesHostMapRootWithExistingIndex(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-approve-map-' . bin2hex(random_bytes(6));
        mkdir($root . '/session_plan', 0o775, true);
        mkdir($root . '/.agent-map', 0o775, true);
        file_put_contents($root . '/.agent-map/php-symbols.json', '{}');
        $session = (new SessionStore())->create($root . '/session_plan', 'ABC-123');
        (new WorkBriefStore())->create($session, 'Keep scope reviewable.', ['src/Foo.php'], [], ['vendor/bin/phpunit']);
        $recallCalls = [];
        $command = new WorkflowApproveCommand(
            $root,
            static function (array $argv) use ($session): int {
                (new WorkBriefStore())->approve($session, 'lars');

                return 0;
            },
            static function (array $argv) use (&$recallCalls): int {
                $recallCalls[] = $argv;

                return 0;
            },
        );

        try {
            ob_start();
            self::assertSame(0, $command->run(['ABC-123', '--by', 'lars', '--learning-root', 'learn']));
            ob_end_clean();

            self::assertSame([
                ['compile', '--root', 'learn', '--task', 'ABC-123', '--task-brief', $session->path . '/work-brief.json', '--map-index', $root . '/.agent-map/php-symbols.json', '--map-root', $root],
            ], $recallCalls);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testApprovePassesExplicitLearningRootDocumentManifest(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-approve-documents-' . bin2hex(random_bytes(6));
        $learningRoot = $root . '/learning';
        mkdir($root . '/session_plan', 0o775, true);
        mkdir($learningRoot, 0o775, true);
        file_put_contents($learningRoot . '/recall-documents.json', json_encode([
            'schema_version' => '1.0',
            'documents' => [],
        ], JSON_THROW_ON_ERROR));
        $session = (new SessionStore())->create($root . '/session_plan', 'ABC-123');
        (new WorkBriefStore())->create($session, 'Keep scope reviewable.', ['src/Foo.php'], [], ['vendor/bin/phpunit']);
        $recallCalls = [];
        $command = new WorkflowApproveCommand(
            $root,
            static function (array $argv) use ($session): int {
                (new WorkBriefStore())->approve($session, 'lars');

                return 0;
            },
            static function (array $argv) use (&$recallCalls): int {
                $recallCalls[] = $argv;

                return 0;
            },
        );

        try {
            ob_start();
            self::assertSame(0, $command->run(['ABC-123', '--by', 'lars', '--learning-root', $learningRoot]));
            ob_end_clean();

            self::assertSame([
                ['compile', '--root', $learningRoot, '--task', 'ABC-123', '--task-brief', $session->path . '/work-brief.json', '--document-manifest', $learningRoot . '/recall-documents.json'],
            ], $recallCalls);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testPlanRecompilesRecallAndRevisesExistingBriefWithoutStartingAnotherSession(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-plan-revise-' . bin2hex(random_bytes(6));
        mkdir($root . '/session_plan', 0o775, true);
        $session = (new SessionStore())->create($root . '/session_plan', 'ABC-123');
        $briefs = new WorkBriefStore();
        $briefs->create($session, 'Initial scope.', ['src/Foo.php'], [], ['vendor/bin/phpunit']);
        $briefs->approve($session, 'lars');

        $sessionCalls = [];
        $recallCalls = [];
        $command = new WorkflowPlanCommand(
            $root,
            static function (array $argv) use (&$sessionCalls): int {
                $sessionCalls[] = $argv;

                return 0;
            },
        );

        try {
            ob_start();
            $exit = $command->run(['ABC-123', '--by', 'lars', '--learning-root', 'learn', '--file', 'src/Foo.php', '--file', 'tests/FooTest.php', '--goal', 'Expanded scope.', '--validation', 'vendor/bin/phpunit tests/FooTest.php']);
            $output = (string) ob_get_clean();

            self::assertSame(0, $exit);
            self::assertSame([['brief', 'revise', 'ABC-123', '--goal', 'Expanded scope.', '--scope', 'src/Foo.php', '--scope', 'tests/FooTest.php', '--validation', 'vendor/bin/phpunit tests/FooTest.php']], $sessionCalls);
            self::assertSame([], $recallCalls);
            self::assertStringContainsString('candidate work brief revised', $output);
        } finally {
            $this->removeDirectory($root);
        }
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
