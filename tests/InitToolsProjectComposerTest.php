<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Init\InitToolsCommand;

/**
 * @internal
 */
final class InitToolsProjectComposerTest extends TestCase
{
    private string $root;

    private string $fakeBinDir;

    private string|false $originalPath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-init-project-tools-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);

        $this->fakeBinDir = sys_get_temp_dir() . '/agent-loop-init-project-tools-bin-' . bin2hex(random_bytes(6));
        mkdir($this->fakeBinDir, 0o775, true);

        $this->originalPath = getenv('PATH');
        putenv('PATH=' . $this->fakeBinDir);
    }

    protected function tearDown(): void
    {
        if ($this->originalPath !== false) {
            putenv('PATH=' . $this->originalPath);
        }

        $this->removeDirectory($this->root);
        $this->removeDirectory($this->fakeBinDir);
    }

    public function testMissingProjectIntegratedPhpstanToolsProduceOneRootComposerCommand(): void
    {
        $this->writeComposer([
            'require-dev' => [
                'phpunit/phpunit' => '^11.5',
            ],
        ]);

        $output = $this->runTools();

        self::assertStringContainsString(
            '[INFO] voku/phpstan-agent-format: not configured in root Composer',
            $output,
        );
        self::assertStringContainsString(
            '[INFO] voku/phpstan-rules: not configured in root Composer',
            $output,
        );
        self::assertStringContainsString(
            '[INFO] root Composer install: composer require --dev voku/phpstan-agent-format voku/phpstan-rules',
            $output,
        );
    }

    public function testRootRequireDevConfigurationIsReportedWithoutInstallCommand(): void
    {
        $this->writeComposer([
            'require-dev' => [
                'voku/phpstan-agent-format' => '^0.1',
                'voku/phpstan-rules' => '^6.0',
            ],
        ]);

        $output = $this->runTools();

        self::assertStringContainsString(
            '[OK] voku/phpstan-agent-format: configured in root Composer require-dev (^0.1;',
            $output,
        );
        self::assertStringContainsString(
            '[OK] voku/phpstan-rules: configured in root Composer require-dev (^6.0;',
            $output,
        );
        self::assertStringNotContainsString('root Composer install:', $output);
    }

    public function testRuntimeRequirementIsReportedAsADevDependencyWarning(): void
    {
        $this->writeComposer([
            'require' => [
                'voku/phpstan-agent-format' => '^0.1',
            ],
            'require-dev' => [
                'voku/phpstan-rules' => '^6.0',
            ],
        ]);

        $output = $this->runTools();

        self::assertStringContainsString(
            '[WARN] voku/phpstan-agent-format: configured in root Composer require (^0.1;',
            $output,
        );
        self::assertStringContainsString('dev tooling should normally live in require-dev', $output);
        self::assertStringNotContainsString('root Composer install:', $output);
    }

    public function testMissingRootComposerIsReportedWithoutPretendingTheToolsAreInstalled(): void
    {
        $output = $this->runTools();

        self::assertStringContainsString(
            '[INFO] project-integrated PHPStan tools: root composer.json missing or unreadable',
            $output,
        );
        self::assertStringNotContainsString('configured in root Composer', $output);
    }

    /** @param array<string, mixed> $composer */
    private function writeComposer(array $composer): void
    {
        file_put_contents(
            $this->root . '/composer.json',
            json_encode(
                $composer,
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
            ) . "\n",
        );
    }

    private function runTools(): string
    {
        $command = new InitToolsCommand($this->root);

        ob_start();
        $exit = $command->run(['--refresh']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);

        return $output;
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
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
