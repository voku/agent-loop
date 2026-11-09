<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Init\InitScaffoldCommand;

/** @internal */
final class InitScaffoldSymlinkSecurityTest extends TestCase
{
    private string $fixtureRoot;

    private string $projectRoot;

    private string $outsideRoot;

    protected function setUp(): void
    {
        $this->fixtureRoot = sys_get_temp_dir() . '/agent-loop-scaffold-symlink-' . bin2hex(random_bytes(6));
        $this->projectRoot = $this->fixtureRoot . '/project';
        $this->outsideRoot = $this->fixtureRoot . '/outside';

        self::assertTrue(mkdir($this->fixtureRoot, 0o775, true));
        self::assertTrue(mkdir($this->projectRoot, 0o775));
        self::assertTrue(mkdir($this->outsideRoot, 0o775));
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->fixtureRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            // Never follow or recurse into the symlinked outside target.
            if ($item->isLink() || !$item->isDir()) {
                self::assertTrue(unlink($item->getPathname()));
            } else {
                self::assertTrue(rmdir($item->getPathname()));
            }
        }

        self::assertTrue(rmdir($this->fixtureRoot));
    }

    public function testFirstScaffoldRejectsLinkedStateRootBeforeWritingOutsideProject(): void
    {
        self::assertTrue(symlink($this->outsideRoot, $this->projectRoot . '/.agent-loop'));

        foreach ([[], ['--dry-run'], ['--prefix=ABC'], ['--demo']] as $arguments) {
            self::assertSame(1, $this->scaffold($arguments));
            self::assertFileDoesNotExist($this->outsideRoot . '/init.json');
            self::assertFileDoesNotExist($this->outsideRoot . '/.gitignore');
            self::assertDirectoryDoesNotExist($this->outsideRoot . '/tasks');
        }
    }

    public function testDanglingStateRootSymlinkIsNeverReplacedOrFollowed(): void
    {
        $outsideMissing = $this->fixtureRoot . '/not-created';
        self::assertTrue(symlink($outsideMissing, $this->projectRoot . '/.agent-loop'));

        self::assertSame(1, $this->scaffold([]));
        self::assertTrue(is_link($this->projectRoot . '/.agent-loop'));
        self::assertDirectoryDoesNotExist($outsideMissing);
    }

    public function testLinkedExistingInitJsonIsRejectedBeforeCreatingAnyScaffoldFiles(): void
    {
        self::assertTrue(mkdir($this->projectRoot . '/.agent-loop', 0o775));
        $outsideConfig = $this->outsideRoot . '/sensitive.json';
        self::assertSame(14, file_put_contents($outsideConfig, 'preserve this!'));
        self::assertTrue(symlink($outsideConfig, $this->projectRoot . '/.agent-loop/init.json'));

        self::assertSame(1, $this->scaffold([]));
        self::assertSame('preserve this!', file_get_contents($outsideConfig));
        self::assertFileDoesNotExist($this->projectRoot . '/.agent-loop/.gitignore');
        self::assertDirectoryDoesNotExist($this->projectRoot . '/.agent-loop/tasks');
    }

    public function testLinkedTasksDirectoryIsRejectedBeforeTheDemoCanWriteThroughIt(): void
    {
        self::assertTrue(mkdir($this->projectRoot . '/.agent-loop', 0o775));
        self::assertTrue(symlink($this->outsideRoot, $this->projectRoot . '/.agent-loop/tasks'));

        self::assertSame(1, $this->scaffold(['--demo']));
        self::assertFileDoesNotExist($this->outsideRoot . '/DEMO-1.md');
        self::assertFileDoesNotExist($this->projectRoot . '/.agent-loop/init.json');
    }

    public function testLinkedBoardRootIsRejectedBeforeAnyScaffoldFilesAreCreated(): void
    {
        self::assertTrue(mkdir($this->projectRoot . '/.agent-loop', 0o775));
        self::assertTrue(symlink($this->outsideRoot, $this->projectRoot . '/.agent-loop/todo'));

        self::assertSame(1, $this->scaffold(['--prefix=SEC']));
        self::assertFileDoesNotExist($this->projectRoot . '/.agent-loop/init.json');
        self::assertFileDoesNotExist($this->projectRoot . '/.agent-loop/.gitignore');
        self::assertFileDoesNotExist($this->outsideRoot . '/kanban.config.json');
    }

    public function testLinkedGitignoreIsRejectedWithoutModifyingItsTarget(): void
    {
        self::assertTrue(mkdir($this->projectRoot . '/.agent-loop', 0o775));
        $outsideIgnore = $this->outsideRoot . '/ignore.txt';
        self::assertSame(9, file_put_contents($outsideIgnore, 'unchanged'));
        self::assertTrue(symlink($outsideIgnore, $this->projectRoot . '/.agent-loop/.gitignore'));

        self::assertSame(1, $this->scaffold([]));
        self::assertSame('unchanged', file_get_contents($outsideIgnore));
        self::assertFileDoesNotExist($this->projectRoot . '/.agent-loop/init.json');
    }

    /** @param list<string> $args */
    private function scaffold(array $args): int
    {
        ob_start();
        try {
            return (new InitScaffoldCommand($this->projectRoot))->run($args);
        } finally {
            ob_end_clean();
        }
    }
}
