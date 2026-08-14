<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\GitHooks\CheckCommandFactory;

/**
 * A misread check option must fail loudly rather than weaken the check.
 *
 * `--level` is the whole strictness of a PHPStan run. Reading it with `(int)`
 * turned every spelling the cast does not understand into level 0 - the weakest
 * one - so the pre-commit gate a project set to `max` kept passing while checking
 * almost nothing, and nothing in the output said so.
 *
 * @internal
 */
final class CheckCommandLevelTest extends TestCase
{
    public function testMaxIsARealLevelAndIsNotDowngradedToZero(): void
    {
        $check = CheckCommandFactory::create(['type' => 'phpstan', 'level' => 'max']);

        self::assertStringContainsString('--level=max', $check['command']);
    }

    #[DataProvider('numericLevels')]
    public function testNumericLevelsAreKept(int|string $level, string $expected): void
    {
        $check = CheckCommandFactory::create(['type' => 'phpstan', 'level' => $level]);

        self::assertStringContainsString('--level=' . $expected, $check['command']);
    }

    /** @return list<array{int|string, string}> */
    public static function numericLevels(): array
    {
        return [
            [0, '0'],
            [8, '8'],
            [9, '9'],
            ['8', '8'],
        ];
    }

    #[DataProvider('unreadableLevels')]
    public function testALevelThisFactoryCannotReadIsAConfigurationError(mixed $level): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/phpstan check level/');

        CheckCommandFactory::create(['type' => 'phpstan', 'level' => $level]);
    }

    /** @return list<array{mixed}> */
    public static function unreadableLevels(): array
    {
        return [
            ['strict'],
            ['MAXIMUM'],
            ['8.5'],
            [-1],
            [true],
            [['max']],
        ];
    }

    public function testOmittingTheLevelStillOmitsTheOption(): void
    {
        $check = CheckCommandFactory::create(['type' => 'phpstan', 'config' => 'phpstan.neon']);

        self::assertStringNotContainsString('--level', $check['command']);
    }
}
