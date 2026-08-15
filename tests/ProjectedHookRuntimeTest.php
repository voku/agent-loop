<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * @internal
 */
final class ProjectedHookRuntimeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-projected-hook-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);

        mkdir($this->root . '/vendor', 0o775, true);
        file_put_contents(
            $this->root . '/vendor/autoload.php',
            "<?php\nthrow new RuntimeException('host autoloader must not win over isolated agent-loop tooling');\n",
        );

        mkdir($this->root . '/tools/agent-loop/vendor', 0o775, true);
        file_put_contents(
            $this->root . '/tools/agent-loop/vendor/autoload.php',
            <<<'PHP'
<?php

declare(strict_types=1);

namespace voku\AgentLoop\AgentGuidance;

final class AgentDisciplineHook
{
    public function __construct(private readonly string $rootPath)
    {
    }

    /** @return array{runtime: string} */
    public function claudeContextOutput(string $event, string $rawPayload): array
    {
        return ['runtime' => 'isolated'];
    }

    /** @return array{runtime: string} */
    public function contextOutput(string $event, string $rawPayload): array
    {
        return ['runtime' => 'isolated'];
    }

    /** @return array{runtime: string} */
    public function preToolUseOutput(string $rawPayload): array
    {
        return ['runtime' => 'isolated'];
    }
}
PHP,
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    /**
     * @return iterable<string, array{source: string, target: string, arguments: list<string>}>
     */
    public static function hookCases(): iterable
    {
        yield 'Claude SessionStart' => [
            'source' => 'docs/agents/claude-hooks/hooks/context.php',
            'target' => '.claude/hooks/context.php',
            'arguments' => ['--event=SessionStart'],
        ];
        yield 'Claude PreToolUse' => [
            'source' => 'docs/agents/claude-hooks/hooks/pre_tool_use_policy.php',
            'target' => '.claude/hooks/pre_tool_use_policy.php',
            'arguments' => [],
        ];
        yield 'Codex SessionStart' => [
            'source' => 'docs/agents/codex-hooks/hooks/context.php',
            'target' => '.codex/hooks/context.php',
            'arguments' => ['--event=SessionStart'],
        ];
        yield 'Codex PreToolUse' => [
            'source' => 'docs/agents/codex-hooks/hooks/pre_tool_use_policy.php',
            'target' => '.codex/hooks/pre_tool_use_policy.php',
            'arguments' => [],
        ];
    }

    /** @param list<string> $arguments */
    #[DataProvider('hookCases')]
    public function testProjectedHookPrefersTheIsolatedRuntimeOverAnUnrelatedHostAutoloader(
        string $source,
        string $target,
        array $arguments,
    ): void {
        $sourcePath = dirname(__DIR__) . '/' . $source;
        $targetPath = $this->root . '/' . $target;
        $targetDirectory = dirname($targetPath);
        if (!is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0o775, true);
        }
        self::assertTrue(copy($sourcePath, $targetPath));

        $result = $this->runHook($targetPath, $arguments);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame('', $result['stderr']);
        self::assertSame(['runtime' => 'isolated'], json_decode(trim($result['stdout']), true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<string> $arguments
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runHook(string $targetPath, array $arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, $targetPath, ...$arguments],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->root,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start projected hook.');
        }

        fwrite($pipes[0], "{}\n");
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit_code' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());

                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($path);
    }
}
