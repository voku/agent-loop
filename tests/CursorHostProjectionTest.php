<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Init\InitAgent;
use voku\AgentLoop\Init\ManagedAssetTargetCatalog;

/** @internal */
final class CursorHostProjectionTest extends TestCase
{
    public function testCursorIsCanonicalAndAllSelectionStillExpandsThroughCanonicalHosts(): void
    {
        self::assertContains('cursor', InitAgent::canonicalNames());

        $cursor = InitAgent::parse('cursor', InitAgent::canonicalNames());
        self::assertSame('cursor', $cursor->canonicalName());
        self::assertFalse($cursor->isAll());

        $all = InitAgent::parse('all', InitAgent::canonicalNames(), true);
        self::assertSame('all', $all->canonicalName());
        self::assertTrue($all->isAll());
    }

    public function testManagedAssetTargetCatalogOwnsCursorTargetsAndEnvironmentOverrides(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-cursor-targets-' . bin2hex(random_bytes(6));
        $skills = $root . '/custom-cursor-skills';
        $agents = $root . '/custom-cursor-agents';
        $skillsBefore = getenv('CURSOR_SKILLS_DIR');
        $agentsBefore = getenv('CURSOR_AGENTS_DIR');

        try {
            $catalog = new ManagedAssetTargetCatalog($root);
            self::assertSame($root . '/.cursor/skills', $catalog->skillsTargetRoot('cursor'));
            self::assertSame($root . '/.cursor/agents', $catalog->subagentsTargetRoot('cursor'));
            self::assertSame('.md', $catalog->subagentSuffix('cursor'));

            putenv('CURSOR_SKILLS_DIR=' . $skills);
            putenv('CURSOR_AGENTS_DIR=' . $agents);

            $catalog = new ManagedAssetTargetCatalog($root);
            self::assertSame($skills, $catalog->skillsTargetRoot('cursor'));
            self::assertSame($agents, $catalog->subagentsTargetRoot('cursor'));
        } finally {
            $skillsBefore === false
                ? putenv('CURSOR_SKILLS_DIR')
                : putenv('CURSOR_SKILLS_DIR=' . $skillsBefore);
            $agentsBefore === false
                ? putenv('CURSOR_AGENTS_DIR')
                : putenv('CURSOR_AGENTS_DIR=' . $agentsBefore);
        }
    }
}
