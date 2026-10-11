<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests\Dogfood;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Dogfood\ProcessRunner;
use voku\AgentLoop\Dogfood\SelfShapeGitChangedPaths;

/** @internal */
final class SelfShapeGitChangedPathsTest extends TestCase
{
    public function testDeletedAndBothRenamedPathsRemainVisibleFromRealCommits(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-diff-paths-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root . '/src', 0o700, true));

        $runner = new ProcessRunner($root);
        try {
            self::assertNotFalse(file_put_contents($root . '/MEMORY.md', 'before'));
            self::assertNotFalse(file_put_contents($root . '/src/Deleted.php', '<?php'));
            self::assertNotFalse(file_put_contents($root . '/src/Old name.php', '<?php'));

            $runner->mustRun(['git', 'init', '--quiet']);
            $runner->mustRun(['git', 'add', '--all']);
            $runner->mustRun([
                'git', '-c', 'user.name=Diff Test', '-c', 'user.email=diff@example.invalid',
                'commit', '--quiet', '-m', 'before',
            ]);
            $base = trim($runner->mustRun(['git', 'rev-parse', 'HEAD'])['stdout']);

            self::assertNotFalse(file_put_contents($root . '/MEMORY.md', 'after'));
            self::assertTrue(unlink($root . '/src/Deleted.php'));
            self::assertTrue(rename($root . '/src/Old name.php', $root . '/src/New name.php'));

            $runner->mustRun(['git', 'add', '--all']);
            $runner->mustRun([
                'git', '-c', 'user.name=Diff Test', '-c', 'user.email=diff@example.invalid',
                'commit', '--quiet', '-m', 'after',
            ]);
            $head = trim($runner->mustRun(['git', 'rev-parse', 'HEAD'])['stdout']);

            $paths = (new SelfShapeGitChangedPaths($runner))->between($base, $head);
            sort($paths);
            self::assertSame([
                'MEMORY.md',
                'src/Deleted.php',
                'src/New name.php',
                'src/Old name.php',
            ], $paths);
        } finally {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $entry) {
                if ($entry->isDir() && !$entry->isLink()) {
                    rmdir($entry->getPathname());
                } else {
                    unlink($entry->getPathname());
                }
            }
            rmdir($root);
        }
    }
}
