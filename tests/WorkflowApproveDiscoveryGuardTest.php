<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Dispatcher;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;

/** @internal */
final class WorkflowApproveDiscoveryGuardTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-approve-discovery-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testExistingPhpScopeNeedsFreshMapBeforeApproval(): void
    {
        $source = $this->root . '/src/Foo.php';
        file_put_contents($source, $this->phpFixture('Foo'));

        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'ABC-123',
            'Change existing PHP code with governed discovery.',
            ['src/Foo.php'],
            [],
            ['vendor/bin/phpunit'],
            'planner',
        );

        $recallCalls = 0;
        $command = new WorkflowApproveCommand(
            $this->root,
            static function (array $argv) use (&$recallCalls): int {
                ++$recallCalls;

                return 0;
            },
        );

        self::assertSame(1, $this->approve($command));
        self::assertSame(TaskContract::CANDIDATE, $contracts->load('ABC-123')->status);
        self::assertSame(0, $recallCalls);

        self::assertSame(0, $this->dispatch(['agent-loop', 'map', 'build', '--paths=src']));
        file_put_contents($source, $this->phpFixture('FooChanged'));

        self::assertSame(1, $this->approve($command));
        self::assertSame(TaskContract::CANDIDATE, $contracts->load('ABC-123')->status);
        self::assertSame(0, $recallCalls);

        self::assertSame(0, $this->dispatch(['agent-loop', 'map', 'refresh']));
        self::assertSame(0, $this->approve($command));
        self::assertSame(TaskContract::APPROVED, $contracts->load('ABC-123')->status);
        self::assertSame(1, $recallCalls);
    }

    public function testNonPhpAndNotYetExistingPhpScopeDoNotInventDiscoveryRequirements(): void
    {
        file_put_contents($this->root . '/README.md', "# Demo\n");
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'DOC-1',
            'Update documentation.',
            ['README.md'],
            [],
            ['php -r "exit(0);"'],
            'planner',
        );
        $contracts->create(
            'NEW-1',
            'Add a new PHP file.',
            ['src/NewThing.php'],
            [],
            ['php -r "exit(0);"'],
            'planner',
        );

        self::assertSame(0, $this->approve(new WorkflowApproveCommand($this->root, static fn (array $argv): int => 0), 'DOC-1'));
        self::assertSame(TaskContract::APPROVED, $contracts->load('DOC-1')->status);

        self::assertSame(0, $this->approve(new WorkflowApproveCommand($this->root, static fn (array $argv): int => 0), 'NEW-1'));
        self::assertSame(TaskContract::APPROVED, $contracts->load('NEW-1')->status);
    }

    private function approve(WorkflowApproveCommand $command, string $taskId = 'ABC-123'): int
    {
        ob_start();
        try {
            return $command->run([$taskId, '--by', 'approver']);
        } finally {
            ob_end_clean();
        }
    }

    /** @param list<string> $argv */
    private function dispatch(array $argv): int
    {
        ob_start();
        try {
            return (new Dispatcher($this->root))->run($argv);
        } finally {
            ob_end_clean();
        }
    }

    private function phpFixture(string $class): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        final class {$class}
        {
        }
        PHP;
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
