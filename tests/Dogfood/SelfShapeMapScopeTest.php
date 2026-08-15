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
            'docs/agents/claude-hooks/hooks/context.php',
            'docs/agents/codex-hooks/hooks/context.php',
            'README.md',
        ]);

        self::assertSame([
            'src',
            'tests',
            'tools',
            'docs/agents/claude-hooks/hooks/context.php',
            'docs/agents/codex-hooks/hooks/context.php',
        ], $paths);
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
