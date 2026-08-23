<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

/** @internal */
final class BranchAliasReleaseSeriesTest extends TestCase
{
    /** Prevents a minor release from leaving dev-main on the previous Composer series. */
    public function testDevMainTracksTheLatestReleasedMinorSeries(): void
    {
        $root = dirname(__DIR__);
        $composerJson = (string) file_get_contents($root . '/composer.json');
        $composer = json_decode($composerJson, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);

        $alias = $composer['extra']['branch-alias']['dev-main'] ?? null;
        self::assertIsString($alias);

        $changelog = (string) file_get_contents($root . '/CHANGELOG.md');
        $matches = [];
        if (preg_match('/^## (\d+)\.(\d+)\.\d+ - \d{4}-\d{2}-\d{2}$/m', $changelog, $matches) !== 1) {
            self::fail('CHANGELOG.md must expose at least one dated release section.');
        }

        self::assertSame(
            $matches[1] . '.' . $matches[2] . '.x-dev',
            $alias,
            'dev-main must advertise the same minor series as the newest documented release.',
        );
    }
}
