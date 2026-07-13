<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Dispatcher;

/**
 * Keeps the public command table, the executable help output, and the
 * documented availability classification in lockstep.
 *
 * @internal
 */
final class CliSurfaceContractTest extends TestCase
{
    /** @var list<string> */
    private const STABLE_NAMESPACES = [
        'board',
        'session',
        'map',
        'recall',
        'learn',
        'verify',
        'workflow',
        'board:verify',
        'memory',
        'review',
        'init',
    ];

    public function testReadmeStableNamespacesMatchExecutableHelp(): void
    {
        $readme = file_get_contents(dirname(__DIR__) . '/README.md');
        self::assertNotFalse($readme);

        $table = $this->packageMapTable($readme);
        preg_match_all('/^\\| `([^`]+)` \\| (Stable|Experimental|Planned) \\|/m', $table, $matches);

        self::assertSame(self::STABLE_NAMESPACES, $matches[1]);
        self::assertSame(array_fill(0, count(self::STABLE_NAMESPACES), 'Stable'), $matches[2]);

        $dispatcher = new Dispatcher(sys_get_temp_dir());
        ob_start();
        $exit = $dispatcher->run(['agent-loop', 'help']);
        $help = (string) ob_get_clean();

        self::assertSame(0, $exit);
        foreach (self::STABLE_NAMESPACES as $namespace) {
            self::assertStringContainsString($namespace, $help);
        }
    }

    public function testReadmeLabelsReservedScaffoldAsPlanned(): void
    {
        $readme = file_get_contents(dirname(__DIR__) . '/README.md');
        self::assertNotFalse($readme);
        self::assertStringContainsString('`init scaffold` is a **planned/reserved command**', $readme);
        self::assertStringContainsString('`not implemented yet`', $readme);
    }

    private function packageMapTable(string $readme): string
    {
        $start = strpos($readme, '| Namespace | Status | Purpose | Owning package |');
        self::assertNotFalse($start);

        $end = strpos($readme, "\n## The loop", $start);
        self::assertNotFalse($end);

        return substr($readme, $start, $end - $start);
    }
}
