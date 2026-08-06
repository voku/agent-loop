<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\MemoryLimit;

final class MemoryLimitTest extends TestCase
{
    public function testShorthandIsInterpretedRatherThanCastToInt(): void
    {
        self::assertSame(134217728, MemoryLimit::toBytes('128M'));
        self::assertSame(2147483648, MemoryLimit::toBytes('2G'));
        self::assertSame(1048576, MemoryLimit::toBytes('1024K'));
        self::assertSame(134217728, MemoryLimit::toBytes('134217728'));
        self::assertNull(MemoryLimit::toBytes('-1'));
        self::assertNull(MemoryLimit::toBytes(''));
        self::assertNull(MemoryLimit::toBytes('nonsense'));
    }

    /**
     * The floor must never act as a ceiling. `(int) '2G'` is 2, so a naive comparison would decide
     * that a deliberately raised limit needs lowering to 512M - and the commands started with
     * `-d memory_limit=4G` are exactly the ones that need the headroom.
     */
    public function testAHigherLimitIsNeverLowered(): void
    {
        self::assertFalse(MemoryLimit::shouldRaise('2G'));
        self::assertFalse(MemoryLimit::shouldRaise('1G'));
        self::assertFalse(MemoryLimit::shouldRaise('4G'));
        self::assertFalse(MemoryLimit::shouldRaise('1024M'));
        self::assertFalse(MemoryLimit::shouldRaise('512M'));
    }

    public function testATooSmallLimitIsRaisedAndAnUnlimitedOneIsLeftAlone(): void
    {
        self::assertTrue(MemoryLimit::shouldRaise('128M'));
        self::assertTrue(MemoryLimit::shouldRaise('256M'));
        self::assertTrue(MemoryLimit::shouldRaise('134217728'));
        self::assertFalse(MemoryLimit::shouldRaise('-1'), 'unlimited must stay unlimited');
        self::assertFalse(MemoryLimit::shouldRaise('nonsense'), 'an unparseable value is not evidence that a change is safe');
    }
}
