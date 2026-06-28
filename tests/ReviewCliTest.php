<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Dispatcher;
use voku\AgentLoop\Review\ReviewCli;

/** @internal */
final class ReviewCliTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-review-cli-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testReviewHelpExitsZero(): void
    {
        $result = $this->runCli(['agent-loop', 'help']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('agent-loop review blindspots <task-id>', $result['output']);
    }

    public function testReviewLongHelpExitsZero(): void
    {
        self::assertSame(0, $this->runCli(['agent-loop', '--help'])['exit']);
    }

    public function testReviewShortHelpExitsZero(): void
    {
        self::assertSame(0, $this->runCli(['agent-loop', '-h'])['exit']);
    }

    public function testBlindspotsWithoutTaskIdFails(): void
    {
        self::assertSame(1, $this->runCli(['agent-loop', 'blindspots'])['exit']);
    }

    public function testInvalidTaskIdFails(): void
    {
        self::assertSame(1, $this->runCli(['agent-loop', 'blindspots', '../ABC-123'])['exit']);
    }

    public function testUnknownReviewCommandFails(): void
    {
        self::assertSame(1, $this->runCli(['agent-loop', 'wat'])['exit']);
    }

    public function testTopLevelHelpListsReview(): void
    {
        $dispatcher = new Dispatcher($this->root);

        ob_start();
        $exit = $dispatcher->run(['agent-loop', 'help']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('review', $output);
        self::assertStringContainsString('Deterministic review helpers', $output);
    }

    public function testReviewNamespaceRoutesToReviewCli(): void
    {
        $dispatcher = new Dispatcher($this->root);

        ob_start();
        $exit = $dispatcher->run(['agent-loop', 'review', 'help']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('agent-loop review blindspots <task-id>', $output);
    }

    public function testReviewCodeWritesL2CodePrompt(): void
    {
        $this->write('recall/ABC-123/meta.json', json_encode(['task_files' => ['src/Foo.php']], JSON_THROW_ON_ERROR));
        $this->write('src/Foo.php', "<?php\n\ndeclare(strict_types=1);\n");

        $result = $this->runCli(['agent-loop', 'code', 'ABC-123']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('Review code prompt for ABC-123: .agent-loop/reviews/ABC-123.code.prompt.md', $result['output']);
        self::assertFileExists($this->root . '/.agent-loop/reviews/ABC-123.code.prompt.md');
        self::assertStringContainsString('L2 code review prompt for ABC-123', (string) file_get_contents($this->root . '/.agent-loop/reviews/ABC-123.code.prompt.md'));
        self::assertStringContainsString('src/Foo.php', (string) file_get_contents($this->root . '/.agent-loop/reviews/ABC-123.code.prompt.md'));
    }

    public function testReviewCodeRejectsInvalidTaskId(): void
    {
        self::assertSame(1, $this->runCli(['agent-loop', 'code', '../ABC-123'])['exit']);
    }

    public function testBlindspotsPrintsL2PromptPath(): void
    {
        $this->write('recall/ABC-123/meta.json', '{}');
        $this->write('session_plan/ABC-123.md', "ABC-123\nPHPStan passed.\nagent-loop review blindspots ABC-123 was checked.\n");

        $result = $this->runCli(['agent-loop', 'blindspots', 'ABC-123']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('L2 prompt: .agent-loop/reviews/ABC-123.blindspots.prompt.md', $result['output']);
    }

    /**
     * @param list<string> $argv
     *
     * @return array{exit: int, output: string}
     */
    private function runCli(array $argv): array
    {
        $cli = new ReviewCli($this->root);
        ob_start();
        $exit = $cli->run($argv);
        $output = (string) ob_get_clean();

        return ['exit' => $exit, 'output' => $output];
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->root . '/' . $relative;
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }
        file_put_contents($path, $content);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
