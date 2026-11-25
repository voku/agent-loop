<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\DeclaredValidationShell;

final class DeclaredValidationShellTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function streamOrders(): iterable
    {
        foreach (['STDERR' => 'STDOUT', 'STDOUT' => 'STDERR'] as $first => $last) {
            foreach (['' => 'without', "\n" => 'with'] as $lineBreak => $label) {
                yield "{$first} before {$last} {$label} line break" => [
                    "fwrite({$first}, str_repeat('n', 100000) . " . var_export($lineBreak, true) . "); fwrite({$last}, 'Foo.php:91 real error' . " . var_export($lineBreak, true) . '); exit(1);',
                    str_repeat('n', 100000) . $lineBreak . 'Foo.php:91 real error',
                ];
            }
        }
    }

    #[DataProvider('streamOrders')]
    public function testOutputEndsWithWhatTheCommandWroteLast(string $code, string $expected): void
    {
        $result = $this->execute($code);

        self::assertSame(1, $result['exitCode']);
        self::assertSame($expected, $result['output']);
    }

    public function testAlternatingWritesKeepTheirOrder(): void
    {
        $result = $this->execute('for ($i = 0; $i < 500; ++$i) { fwrite($i % 2 === 0 ? STDOUT : STDERR, $i . PHP_EOL); }');

        self::assertSame(implode(PHP_EOL, range(0, 499)), $result['output']);
    }

    public function testLargeUnbrokenOutputOnBothStreamsIsCompleteInLinearTime(): void
    {
        $start = microtime(true);
        $result = $this->execute('for ($i = 0; $i < 16384; ++$i) { fwrite(STDOUT, str_repeat("o", 1024)); fwrite(STDERR, str_repeat("e", 1024)); }');

        self::assertSame(0, $result['exitCode']);
        self::assertSame(16384 * 1024, substr_count($result['output'], 'o'));
        self::assertSame(16384 * 1024, substr_count($result['output'], 'e'));
        self::assertLessThan(15.0, microtime(true) - $start);
    }

    public function testEmptyOutputKeepsTheExitCode(): void
    {
        self::assertSame(['exitCode' => 7, 'output' => ''], $this->execute('exit(7);'));
    }

    /** @return array{exitCode: int, output: string} */
    private function execute(string $code): array
    {
        return (new DeclaredValidationShell())->executeDeclaredValidationShell(
            escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code),
            sys_get_temp_dir(),
        );
    }
}
