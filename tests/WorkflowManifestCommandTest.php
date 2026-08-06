<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Workflow\WorkflowManifestCommand;

final class WorkflowManifestCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-manifest-command-' . bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testDefaultCommandIsReadOnly(): void
    {
        $before = $this->files();

        [$exit, $output] = $this->invoke(['ABC-123']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('task:ABC-123:legacy', $output);
        self::assertStringContainsString('Manifest:  missing', $output);
        self::assertSame($before, $this->files());
    }

    public function testWritePersistsTheCurrentProjectionAndBothJsonFormsExposeStorageState(): void
    {
        [$writeExit, $writeOutput] = $this->invoke(['ABC-123', '--write']);

        self::assertSame(0, $writeExit);
        self::assertStringContainsString('Manifest:  current', $writeOutput);
        self::assertFileExists($this->root . '/.agent-loop/runs/ABC-123/manifest.json');

        foreach ([['--format', 'json'], ['--format=json']] as $formatArgs) {
            [$jsonExit, $jsonOutput] = $this->invoke(['ABC-123', ...$formatArgs]);
            $decoded = json_decode($jsonOutput, true, 512, JSON_THROW_ON_ERROR);

            self::assertSame(0, $jsonExit);
            self::assertIsArray($decoded);
            self::assertSame('current', $decoded['storage']['state']);
            self::assertSame('legacy_inferred', $decoded['manifest']['mode']);
            self::assertSame('ABC-123', $decoded['manifest']['task_id']);
        }
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string}
     */
    private function invoke(array $args): array
    {
        ob_start();
        $exit = (new WorkflowManifestCommand($this->root))->run($args);
        $output = (string) ob_get_clean();

        return [$exit, $output];
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $files[] = $file->getPathname();
        }
        sort($files);

        return $files;
    }

    private function rm(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
