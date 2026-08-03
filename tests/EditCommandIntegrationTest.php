<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Edit\EditCommand;

final class EditCommandIntegrationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-edit-integration-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/tests', 0o775, true);
        file_put_contents($this->root . '/src/UserService.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Demo;

        final class UserService
        {
            public function save(bool $active): void
            {
                if (!$active) {
                    return;
                }
            }
        }
        PHP);
        file_put_contents($this->root . '/tests/UserServiceTest.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Demo\Tests;

        use Demo\UserService;

        final class UserServiceTest
        {
            public function testSave(): void
            {
                (new UserService())->save(true);
            }
        }
        PHP);
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testPreparesTargetAwareExecutionBundle(): void
    {
        $command = new EditCommand($this->root);

        ob_start();
        $exit = $command->run([
            'Demo\\UserService::save',
            '--task=EDIT-1',
            '--map-paths=src,tests',
            '--dry-run',
            '--',
            'Reject inactive users before persistence.',
        ]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('Edit execution bundle prepared:', $output);
        self::assertFileExists($this->root . '/.agent-map/php-symbols.json');
        self::assertFileExists($this->root . '/.agent-loop/edit/EDIT-1/prompt.md');
        self::assertFileExists($this->root . '/.agent-loop/edit/EDIT-1/execution.json');

        $prompt = file_get_contents($this->root . '/.agent-loop/edit/EDIT-1/prompt.md');
        self::assertIsString($prompt);
        self::assertStringContainsString('### Target: `Demo\\UserService::save`', $prompt);
        self::assertStringContainsString('public function save(bool $active): void', $prompt);
        self::assertStringContainsString('Reject inactive users before persistence.', $prompt);

        $executionJson = file_get_contents($this->root . '/.agent-loop/edit/EDIT-1/execution.json');
        self::assertIsString($executionJson);
        $execution = json_decode($executionJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($execution);
        self::assertSame('prepared', $execution['status']);
        self::assertSame('Demo\\UserService::save', $execution['target']);
        self::assertIsString($execution['map_digest']);
        self::assertIsString($execution['recall_bundle_sha256']);
    }
}
