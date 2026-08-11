<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Workflow\WorkflowStartCommand;
use voku\AgentSession\SessionStore;

final class WorkflowStartCommandTest extends TestCase
{
    public function testStartCreatesSessionAndDelegatesRecall(): void
    {
        $root = $this->root();
        /** @var list<list<string>> $recallCalls */
        $recallCalls = [];
        $command = new WorkflowStartCommand(
            $root,
            static function (array $argv) use (&$recallCalls): int {
                $recallCalls[] = $argv;

                return 0;
            },
        );

        try {
            ob_start();
            $exit = $command->run([
                'ABC-123', '--by', 'lars', '--learning-root', 'infra/doc/agent-learning', '--file', 'src/Foo.php',
            ]);
            $output = (string) ob_get_clean();

            self::assertSame(0, $exit);
            self::assertSame([
                ['compile', '--root', 'infra/doc/agent-learning', '--task', 'ABC-123', '--file', 'src/Foo.php'],
            ], $recallCalls);

            $sessions = (new SessionStore())->all($root . '/.agent-loop/sessions');
            self::assertCount(1, $sessions);
            self::assertSame('ABC-123', $sessions[0]->taskId);
            self::assertSame('lars', $sessions[0]->claimedBy);
            self::assertNull($sessions[0]->baseCommit);
            self::assertStringContainsString('workflow start: recall compile completed', $output);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testStartSupportsRepeatedFileAndBaseCommit(): void
    {
        $root = $this->root();
        /** @var list<list<string>> $recallCalls */
        $recallCalls = [];
        $command = new WorkflowStartCommand(
            $root,
            static function (array $argv) use (&$recallCalls): int {
                $recallCalls[] = $argv;

                return 0;
            },
        );

        try {
            ob_start();
            $exit = $command->run([
                'ABC-123', '--by', 'lars', '--root', 'learn', '--file', 'a.php', '--file', 'b.php', '--base-commit', 'abc',
            ]);
            ob_end_clean();

            self::assertSame(0, $exit);
            self::assertSame([
                ['compile', '--root', 'learn', '--task', 'ABC-123', '--file', 'a.php', '--file', 'b.php'],
            ], $recallCalls);

            $sessions = (new SessionStore())->all($root . '/.agent-loop/sessions');
            self::assertCount(1, $sessions);
            self::assertSame('abc', $sessions[0]->baseCommit);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testRestartReusesExistingActiveSession(): void
    {
        $root = $this->root();
        /** @var list<list<string>> $recallCalls */
        $recallCalls = [];
        $command = new WorkflowStartCommand(
            $root,
            static function (array $argv) use (&$recallCalls): int {
                $recallCalls[] = $argv;

                return 0;
            },
        );
        $args = ['ABC-123', '--by', 'lars', '--learning-root', 'learn', '--file', 'src/Foo.php'];

        try {
            ob_start();
            self::assertSame(0, $command->run($args));
            ob_end_clean();

            $sessionsBeforeRestart = (new SessionStore())->all($root . '/.agent-loop/sessions');
            self::assertCount(1, $sessionsBeforeRestart);
            $sessionId = $sessionsBeforeRestart[0]->id;

            ob_start();
            $exit = $command->run($args);
            $output = (string) ob_get_clean();

            self::assertSame(0, $exit);
            self::assertCount(2, $recallCalls);
            $sessionsAfterRestart = (new SessionStore())->all($root . '/.agent-loop/sessions');
            self::assertCount(1, $sessionsAfterRestart);
            self::assertSame($sessionId, $sessionsAfterRestart[0]->id);
            self::assertStringContainsString("reusing active session {$sessionId}", $output);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testStartFailsWhenMultipleActiveSessionsAlreadyExist(): void
    {
        $root = $this->root();
        $store = new SessionStore();
        $store->create($root . '/.agent-loop/sessions', 'ABC-123', by: 'first');
        $store->create($root . '/.agent-loop/sessions', 'ABC-123', by: 'second');
        $recallCalls = 0;
        $command = new WorkflowStartCommand(
            $root,
            static function (array $argv) use (&$recallCalls): int {
                ++$recallCalls;

                return 0;
            },
        );

        try {
            ob_start();
            $exit = $command->run(['ABC-123', '--by', 'lars', '--learning-root', 'learn', '--file', 'src/Foo.php']);
            ob_end_clean();

            self::assertSame(1, $exit);
            self::assertSame(0, $recallCalls);
            self::assertCount(2, $store->all($root . '/.agent-loop/sessions'));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testStartValidatesRequiredInputsBeforeWritingSession(): void
    {
        $root = $this->root();
        $command = new WorkflowStartCommand($root, static fn (array $argv): int => 0);

        try {
            ob_start();
            self::assertSame(1, $command->run(['ABC-123', '--learning-root', 'x', '--file', 'f']));
            ob_end_clean();
            ob_start();
            self::assertSame(1, $command->run(['ABC-123', '--by', 'lars']));
            ob_end_clean();

            self::assertSame([], (new SessionStore())->all($root . '/.agent-loop/sessions'));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testStartKeepsSessionWhenRecallFails(): void
    {
        $root = $this->root();
        $command = new WorkflowStartCommand($root, static fn (array $argv): int => 8);

        try {
            ob_start();
            $exit = $command->run(['ABC-123', '--by', 'lars', '--learning-root', 'x', '--file', 'f']);
            ob_end_clean();

            self::assertSame(8, $exit);
            self::assertCount(1, (new SessionStore())->all($root . '/.agent-loop/sessions'));
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function root(): string
    {
        return sys_get_temp_dir() . '/agent-loop-start-' . bin2hex(random_bytes(6));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
