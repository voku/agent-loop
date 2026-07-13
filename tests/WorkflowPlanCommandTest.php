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
            static function (array $argv) use (&$recallCalls): int {
                $recallCalls[] = $argv;

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
        self::assertSame([['compile', '--root', 'infra/doc/agent-learning', '--task', 'ABC-123', '--file', 'src/Foo.php']], $recallCalls);
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
            static fn (array $argv): int => 0,
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
            static fn (array $argv): int => 0,
        );

        ob_start();
        $exit = $command->run(['ABC-123', '--by', 'lars', '--learning-root', 'learn', '--file', 'src/Foo.php', '--goal', 'Goal']);
        ob_end_clean();

        self::assertSame(1, $exit);
        self::assertSame(0, $calls);
    }

    public function testApproveDelegatesCurrentTaskBrief(): void
    {
        $calls = [];
        $command = new WorkflowApproveCommand(static function (array $argv) use (&$calls): int {
            $calls[] = $argv;

            return 0;
        });

        ob_start();
        $exit = $command->run(['ABC-123', '--by', 'lars']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertSame([['brief', 'approve', 'ABC-123', '--by', 'lars']], $calls);
        self::assertStringContainsString('work brief approved', $output);
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
            static function (array $argv) use (&$recallCalls): int {
                $recallCalls[] = $argv;

                return 0;
            },
        );

        try {
            ob_start();
            $exit = $command->run(['ABC-123', '--by', 'lars', '--learning-root', 'learn', '--file', 'src/Foo.php', '--file', 'tests/FooTest.php', '--goal', 'Expanded scope.', '--validation', 'vendor/bin/phpunit tests/FooTest.php']);
            $output = (string) ob_get_clean();

            self::assertSame(0, $exit);
            self::assertSame([['brief', 'revise', 'ABC-123', '--goal', 'Expanded scope.', '--scope', 'src/Foo.php', '--scope', 'tests/FooTest.php', '--validation', 'vendor/bin/phpunit tests/FooTest.php']], $sessionCalls);
            self::assertSame([['compile', '--root', 'learn', '--task', 'ABC-123', '--file', 'src/Foo.php', '--file', 'tests/FooTest.php']], $recallCalls);
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
