<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Dispatcher;
use voku\AgentLoop\Workflow\ImplementationSnapshot;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowReviewReportReader;
use voku\AgentSession\SessionStore;
use voku\AgentSession\ValidationEvidenceStore;

final class PostExecutionEvidenceDispatcherTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-evidence-dispatch-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nreturn 'A';\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testUmbrellaValidationAndReviewAreBoundToCurrentApprovedImplementation(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('BIND-1', 'Bind post-execution evidence.', ['src/Foo.php'], [], ['php -l src/Foo.php'], 'fixture');
        $contract = $contracts->approve('BIND-1', 'fixture');
        $session = (new SessionStore())->create($this->root . '/.agent-loop/sessions', 'BIND-1', by: 'fixture');
        $snapshot = ImplementationSnapshot::capture($this->root, $contract);
        $dispatcher = new Dispatcher($this->root);

        ob_start();
        $validationExit = $dispatcher->run([
            'agent-loop', 'session', 'validation', 'record', 'BIND-1',
            '--contract-revision', (string) $contract->revision,
            '--command', 'php -l src/Foo.php',
            '--status', 'passed',
            '--exit-code', '0',
            '--by', 'fixture',
        ]);
        ob_end_clean();

        self::assertSame(0, $validationExit);
        $evidence = (new ValidationEvidenceStore())->all($session);
        self::assertCount(1, $evidence);
        self::assertSame($snapshot->digest, $evidence[0]->implementationSnapshot);

        $recall = $this->root . '/.agent-loop/recall/BIND-1';
        mkdir($recall, 0o775, true);
        file_put_contents($recall . '/meta.json', '{"task_id":"BIND-1"}');
        file_put_contents($recall . '/validation-plan.md', "composer ci\nreview blindspots\nlog-outcome\n");

        ob_start();
        $reviewExit = $dispatcher->run(['agent-loop', 'review', 'blindspots', 'BIND-1']);
        ob_end_clean();

        self::assertSame(0, $reviewExit);
        $review = (new WorkflowReviewReportReader($this->root))->read('BIND-1');
        self::assertFalse($review['invalid']);
        self::assertSame($contract->revision, $review['contract_revision']);
        self::assertSame($snapshot->digest, $review['implementation_snapshot']);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
