<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

final class BinaryAutoloadOrderTest extends TestCase
{
    /**
     * Preferring the package's own vendor/ silently loads that copy's dependencies instead of the
     * project's whenever one is present - a path repository, a mirrored checkout, a stale local
     * install. It was found by a release-set smoke test that reported "Undefined property
     * Session::$ephemeral" against an installed version that plainly had it.
     */
    public function testTheOuterAutoloaderIsPreferredOverThePackagesOwnVendor(): void
    {
        $bin = (string) file_get_contents(__DIR__ . '/../bin/agent-loop');

        $outer = strpos($bin, "__DIR__ . '/../../../autoload.php'");
        $own = strpos($bin, "__DIR__ . '/../vendor/autoload.php'");

        self::assertIsInt($outer);
        self::assertIsInt($own);
        self::assertLessThan($own, $outer, 'the project autoloader must win over a leftover nested vendor/');
    }
}
