<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Dogfood\ProcessRunner;

final class ProcessRunnerTest extends TestCase
{
    public function testVendorBinaryUsesTheCurrentPlatformsComposerWrapper(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-process-runner-' . bin2hex(random_bytes(6));
        $base = $root . '/vendor/bin/phpstan';
        mkdir(dirname($base), 0o775, true);
        file_put_contents($base, '#!/usr/bin/env php');
        file_put_contents($base . '.bat', '@echo off');

        try {
            self::assertSame(
                DIRECTORY_SEPARATOR === '\\' ? $base . '.bat' : $base,
                (new ProcessRunner($root))->vendorBinary('phpstan'),
            );
        } finally {
            unlink($base . '.bat');
            unlink($base);
            rmdir(dirname($base));
            rmdir(dirname(dirname($base)));
            rmdir($root);
        }
    }
}
