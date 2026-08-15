<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowLearningCommand;
use voku\AgentSession\SessionStore;

final class WorkflowLearningEvidenceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-learning-boundary-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nreturn 'A';\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testLearningCannotConcludeBeforeCurrentValidationAndReviewEvidenceExists(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('LEARN-STALE-1', 'Bind Learning to post-execution evidence.', ['src/Foo.php'], [], ['php -l src/Foo.php'], 'fixture');
        $contract = $contracts->approve('LEARN-STALE-1', 'fixture');
        $session = (new SessionStore())->create($this->root . '/.agent-loop/sessions', 'LEARN-STALE-1', by: 'fixture');
        (new GovernedRunStore($this->root))->prepare($contract, $session, $this->root . '/.agent-loop/learning');

        $exit = (new WorkflowLearningCommand($this->root))->run([
            'LEARN-STALE-1',
            '--status', 'no_durable_learning',
            '--by', 'fixture',
            '--reason', 'Premature conclusion before validation and review.',
        ]);

        self::assertSame(1, $exit, 'Learning must not become authoritative before the current post-execution evidence boundary exists.');
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
