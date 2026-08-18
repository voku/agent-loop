<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;

final class WorkflowApproveAuthorityBoundaryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-approve-authority-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/docs', 0o775, true) && !is_dir($this->root . '/docs')) {
            throw new RuntimeException('Unable to create approval-boundary fixture.');
        }
        file_put_contents($this->root . '/docs/note.txt', "current\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testApprovePersistsAuthorityOnlyAndHandsPreparationToEnter(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'APPROVE-1',
            'Approve one bounded text change without preparing execution state.',
            ['docs/note.txt'],
            [],
            ['php -r "exit(0);"'],
            'planner',
        );

        ob_start();
        try {
            $exit = (new WorkflowApproveCommand($this->root))->run(['APPROVE-1', '--by', 'approver']);
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $exit, $output);
        self::assertSame(TaskContract::APPROVED, $contracts->load('APPROVE-1')->status);
        self::assertDirectoryDoesNotExist($this->root . '/.agent-loop/runs/APPROVE-1');
        self::assertFileDoesNotExist($this->root . '/.agent-loop/recall/APPROVE-1/meta.json');
        self::assertSame([], glob($this->root . '/.agent-loop/sessions/*', GLOB_ONLYDIR) ?: []);
        self::assertStringContainsString('enter APPROVE-1', $output);
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
