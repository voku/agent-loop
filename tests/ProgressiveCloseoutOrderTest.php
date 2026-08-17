<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowStatusCommand;
use voku\AgentSession\SessionStore;

/** @internal */
final class ProgressiveCloseoutOrderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-progressive-closeout-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/src', 0o775, true) && !is_dir($this->root . '/src')) {
            throw new RuntimeException('Unable to create close-out fixture.');
        }
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo {}\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testMissingValidationIsTheNextGapBeforeMissingReviewAndLearning(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'SIMPLE-2',
            'Exercise progressive close-out ordering.',
            ['src/Foo.php'],
            [],
            ['vendor/bin/phpunit --testsuite unit'],
            'planner',
        );
        $contract = $contracts->approve('SIMPLE-2', 'approver');

        $sessions = new SessionStore();
        $session = $sessions->create($this->root . '/.agent-loop/sessions', 'SIMPLE-2', by: 'planner');
        (new GovernedRunStore($this->root))->prepare(
            $contract,
            $session,
            $this->root . '/.agent-loop/learning',
        );

        $recallDirectory = $this->root . '/.agent-loop/recall/SIMPLE-2';
        if (!mkdir($recallDirectory, 0o775, true) && !is_dir($recallDirectory)) {
            throw new RuntimeException('Unable to create Recall fixture.');
        }
        file_put_contents(
            $recallDirectory . '/meta.json',
            json_encode([
                'schema_version' => '1.0',
                'task_id' => 'SIMPLE-2',
                'compilation_id' => 'simple-2-001',
                'selected_guidance' => [],
                'selected_constraints' => [],
                'output_hashes' => [],
            ], JSON_THROW_ON_ERROR),
        );

        ob_start();
        try {
            $exit = (new WorkflowStatusCommand($this->root))->run(['SIMPLE-2', '--format=json']);
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertSame(0, $exit);
        self::assertIsString($output);
        $status = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($status);

        self::assertSame('incomplete', $status['manifest']['state'] ?? null);
        self::assertSame('missing', $status['manifest']['references']['review']['state'] ?? null);
        self::assertSame('missing', $status['manifest']['references']['learning']['state'] ?? null);
        self::assertSame('blocked', $status['manifest']['references']['verification']['state'] ?? null);
        self::assertSame('validation', $status['manifest']['references']['verification']['gate'] ?? null);
        self::assertSame(
            'vendor/bin/phpunit --testsuite unit',
            $status['manifest']['next_action'] ?? null,
            'The next action should satisfy the earliest deterministic evidence gap before asking for review or Learning judgment.',
        );
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
