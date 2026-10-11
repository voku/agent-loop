<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests\Dogfood;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Dogfood\SelfShapeMapScope;

/**
 * @internal
 */
final class SelfShapeMapScopeTest extends TestCase
{
    public function testChangedPhpOutsideStableRootsIsIndexedExplicitly(): void
    {
        $paths = (new SelfShapeMapScope())->paths([
            'src/Init/RepositoryActivation.php',
            'tests/RepositoryActivationTest.php',
            'tools/self-shape-dogfood.php',
            'resources/hooks/claude/hooks/context.php',
            'resources/hooks/codex/hooks/context.php',
            'README.md',
        ]);

        self::assertSame([
            'src',
            'tests',
            'tools',
            'resources/hooks/claude/hooks/context.php',
            'resources/hooks/codex/hooks/context.php',
        ], $paths);
    }

    public function testDeletedPhpOutsideStableRootsIsNotIndexedButExistingPhpIs(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-map-existing-' . bin2hex(random_bytes(8));
        $hooks = $root . '/resources/hooks';

        self::assertTrue(mkdir($hooks, 0o700, true));

        try {
            self::assertNotFalse(file_put_contents($hooks . '/live.php', '<?php'));
            self::assertSame([
                'src',
                'tests',
                'tools',
                'resources/hooks/live.php',
            ], (new SelfShapeMapScope())->paths([
                'resources/hooks/deleted.php',
                'resources/hooks/live.php',
                'resources/hooks/renamed-away.php',
                'tools/deleted.php',
            ], $root));
        } finally {
            unlink($hooks . '/live.php');
            rmdir($hooks);
            rmdir($root . '/resources');
            rmdir($root);
        }
    }

    public function testDuplicateChangedPhpPathsAreProjectedOnce(): void
    {
        self::assertSame([
            'src',
            'tests',
            'tools',
            'bin/custom.php',
        ], (new SelfShapeMapScope())->paths([
            'bin/custom.php',
            'bin/custom.php',
        ]));
    }
}
