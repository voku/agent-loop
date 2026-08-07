<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Dispatcher;

/** Keeps the concise README namespace list and executable help in lockstep. @internal */
final class CliSurfaceContractTest extends TestCase
{
    /** @var list<string> */
    private const STABLE_NAMESPACES = [
        'edit',
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

        $block = $this->cliNamespaceBlock($readme);
        preg_match_all('/^([a-z][a-z:-]*)\s+/m', $block, $matches);
        $documented = array_values(array_unique($matches[1]));

        $expected = self::STABLE_NAMESPACES;
        sort($documented);
        sort($expected);
        self::assertSame($expected, $documented);

        $dispatcher = new Dispatcher(sys_get_temp_dir());
        ob_start();
        $exit = $dispatcher->run(['agent-loop', 'help']);
        $help = (string) ob_get_clean();

        self::assertSame(0, $exit);
        foreach (self::STABLE_NAMESPACES as $namespace) {
            self::assertStringContainsString($namespace, $help);
        }
    }

    public function testReadmeDocumentsRunnableRepositoryStart(): void
    {
        $readme = file_get_contents(dirname(__DIR__) . '/README.md');
        self::assertNotFalse($readme);
        self::assertStringContainsString('## Start a repository', $readme);
        self::assertStringContainsString('vendor/bin/agent-loop init scaffold', $readme);
        self::assertStringContainsString('docs/quick-start.md', $readme);
    }

    private function cliNamespaceBlock(string $readme): string
    {
        $heading = strpos($readme, '## CLI namespaces');
        self::assertNotFalse($heading);

        $start = strpos($readme, "```text\n", $heading);
        self::assertNotFalse($start);
        $start += strlen("```text\n");

        $end = strpos($readme, "\n```", $start);
        self::assertNotFalse($end);

        return substr($readme, $start, $end - $start);
    }
}
