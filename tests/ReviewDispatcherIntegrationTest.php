<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Dispatcher;

/** @internal */
final class ReviewDispatcherIntegrationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-review-integration-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testReviewBlindspotsDefaultsToAgentLoopRecallLayout(): void
    {
        $this->write('recall/ABC-123/meta.json', json_encode(['task_id' => 'ABC-123', 'task_files' => []], JSON_THROW_ON_ERROR));
        $this->write('recall/ABC-123/validation-plan.md', "PHPStan passed.\nreview blindspots ABC-123 checked.\nrecall-log.draft.json prepared.\n");
        $this->write('recall/ABC-123/recall-log.draft.json', '{"outcome":"prepared"}');

        $result = $this->dispatch(['agent-loop', 'review', 'blindspots', 'ABC-123']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('Review blindspots for ABC-123: ok', $result['output']);
        self::assertFileExists($this->root . '/.agent-recall/reviews/ABC-123.blindspots.md');
        self::assertFileExists($this->root . '/.agent-recall/reviews/ABC-123.blindspots.json');
        self::assertFileExists($this->root . '/.agent-recall/reviews/ABC-123.blindspots.prompt.md');
    }

    public function testReviewBlindspotsUsesTheLearningRootRecallOutputWhenPresent(): void
    {
        mkdir($this->root . '/infra/doc/agent-learning/findings', 0o775, true);
        $this->write('infra/doc/agent-learning/recall-output/ABC-123/meta.json', json_encode(['task_id' => 'ABC-123', 'task_files' => []], JSON_THROW_ON_ERROR));
        $this->write('infra/doc/agent-learning/recall-output/ABC-123/validation-plan.md', "PHPStan passed.\nreview blindspots ABC-123 checked.\nrecall-log.draft.json prepared.\n");
        $this->write('infra/doc/agent-learning/recall-output/ABC-123/recall-log.draft.json', '{"outcome":"prepared"}');

        $result = $this->dispatch(['agent-loop', 'review', 'blindspots', 'ABC-123']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('Review blindspots for ABC-123: ok', $result['output']);
        self::assertFileExists($this->root . '/.agent-recall/reviews/ABC-123.blindspots.json');
    }

    public function testReviewCodeDefaultsToAgentLoopRecallLayout(): void
    {
        $this->write('recall/ABC-123/meta.json', json_encode(['task_id' => 'ABC-123', 'task_files' => ['src/Foo.php']], JSON_THROW_ON_ERROR));
        $this->write('recall/ABC-123/validation-plan.md', "PHPUnit passed.\n");
        $this->write('src/Foo.php', "<?php\n\ndeclare(strict_types=1);\n");

        $result = $this->dispatch(['agent-loop', 'review', 'code', 'ABC-123']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('Review code prompt for ABC-123', $result['output']);
        $prompt = (string) file_get_contents($this->root . '/.agent-recall/reviews/ABC-123.code.prompt.md');
        self::assertStringContainsString('src/Foo.php', $prompt);
        self::assertStringContainsString('L2 code review prompt for ABC-123', $prompt);
    }

    public function testReviewHelpComesFromRecallCompilerReviewNamespace(): void
    {
        $result = $this->dispatch(['agent-loop', 'review', 'help']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('agent-recall-compiler review blindspots <task-id>', $result['output']);
        self::assertStringContainsString('agent-recall-compiler review code <task-id>', $result['output']);
    }

    /**
     * @param list<string> $argv
     *
     * @return array{exit: int, output: string}
     */
    private function dispatch(array $argv): array
    {
        $dispatcher = new Dispatcher($this->root);
        ob_start();
        $exit = $dispatcher->run($argv);
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
