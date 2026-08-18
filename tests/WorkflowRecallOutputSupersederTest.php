<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunManifestStore;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentSession\SessionStore;

final class WorkflowRecallOutputSupersederTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-recall-superseder-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o775, true);
        mkdir($this->root . '/learning-root', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testEnterArchivesPreviousRecallBeforeCompilationStarts(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('ABC-123', 'New task scope.', ['src/New.php'], [], ['vendor/bin/phpunit'], 'lars');

        mkdir($this->root . '/.agent-loop/recall/ABC-123/reviews', 0o775, true);
        file_put_contents(
            $this->root . '/.agent-loop/recall/ABC-123/meta.json',
            json_encode([
                'schema_version' => '1.0',
                'task_id' => 'ABC-123',
                'compilation_id' => 'old-compilation',
            ], JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $this->root . '/.agent-loop/recall/ABC-123/reviews/ABC-123.blindspots.json',
            json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR),
        );

        ob_start();
        $approveExit = (new WorkflowApproveCommand($this->root))->run(['ABC-123', '--by', 'lars']);
        ob_end_clean();

        self::assertSame(0, $approveExit);
        self::assertDirectoryExists($this->root . '/.agent-loop/recall/ABC-123');
        self::assertSame([], (new SessionStore())->all($this->root . '/.agent-loop/sessions'));
        self::assertNull((new GovernedRunStore($this->root))->find('ABC-123'));

        ob_start();
        $enterExit = (new HostFrontDoorCommand($this->root, static fn (array $argv): int => 7))->run(
            'enter',
            ['ABC-123', '--format=json'],
        );
        ob_end_clean();

        self::assertSame(1, $enterExit);
        $contract = $contracts->load('ABC-123');
        self::assertSame('lars', $contract->approvedBy);
        $sessions = (new SessionStore())->all($this->root . '/.agent-loop/sessions');
        self::assertCount(1, $sessions);
        $run = (new GovernedRunStore($this->root))->find('ABC-123');
        self::assertNotNull($run);
        self::assertSame($sessions[0]->id, $run->sessionId);
        self::assertSame($contract->revision, $run->contractRevision);
        self::assertDirectoryDoesNotExist($this->root . '/.agent-loop/recall/ABC-123');

        $archives = glob($this->root . '/.agent-loop/recall/ABC-123.superseded-*', GLOB_ONLYDIR) ?: [];
        self::assertCount(1, $archives);
        self::assertFileExists($archives[0] . '/meta.json');
        self::assertFileExists($archives[0] . '/reviews/ABC-123.blindspots.json');

        $manifest = (new RunManifestStore($this->root))->read('ABC-123');
        self::assertNotNull($manifest);
        self::assertSame($run->runId, $manifest['run_id'] ?? null);
        $references = $manifest['references'] ?? null;
        self::assertIsArray($references);
        $recall = $references['recall'] ?? null;
        self::assertIsArray($recall);
        self::assertSame('missing', $recall['state']);
    }

    private function rm(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
