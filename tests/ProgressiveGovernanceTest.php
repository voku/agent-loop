<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\RecallOutputRoot;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;

/** @internal */
final class ProgressiveGovernanceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-progressive-governance-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/docs', 0o775, true) && !is_dir($this->root . '/docs')) {
            throw new RuntimeException('Unable to create progressive-governance fixture.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testSimpleNonPhpTaskSkipsIrrelevantCapabilitiesAndStillAuthorizesMutation(): void
    {
        file_put_contents($this->root . '/docs/note.txt', "current\n");

        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'SIMPLE-1',
            'Update one bounded text note.',
            ['docs/note.txt'],
            [],
            ['php -r "exit(0);"'],
            'planner',
        );

        self::assertFileDoesNotExist($this->root . '/.agent-loop/map/php-symbols.json');
        self::assertFileDoesNotExist($this->root . '/.agent-loop/map/search.sqlite');
        self::assertSame(0, $this->approve(new WorkflowApproveCommand($this->root), 'SIMPLE-1'));
        self::assertSame(TaskContract::APPROVED, $contracts->load('SIMPLE-1')->status);
        self::assertDirectoryDoesNotExist($this->root . '/.agent-loop/runs/SIMPLE-1');
        self::assertFileDoesNotExist($this->root . '/.agent-loop/recall/SIMPLE-1/meta.json');
        self::assertFileDoesNotExist($this->root . '/.agent-loop/map/php-symbols.json');
        self::assertFileDoesNotExist($this->root . '/.agent-loop/map/search.sqlite');

        $recallCalls = 0;
        [$exit, $payload] = $this->enter(
            'SIMPLE-1',
            function (array $argv) use (&$recallCalls): int {
                ++$recallCalls;
                $this->writeRecallMeta('SIMPLE-1');

                return 0;
            },
        );

        self::assertSame(0, $exit);
        self::assertSame(1, $recallCalls);
        self::assertTrue($payload['mutation_ready']);
        self::assertSame('governed', $payload['manifest']['mode']);
        self::assertSame('not_configured', $payload['manifest']['references']['board']['state']);
        self::assertSame('missing', $payload['manifest']['references']['map']['state']);
        self::assertSame('unavailable', $payload['manifest']['references']['search_index']['state']);
        self::assertSame('not_required', $payload['manifest']['references']['execution_contract']['state']);
        self::assertSame('none', $payload['manifest']['references']['edit']['state']);
        self::assertStringContainsString('agent-map: index missing', implode("\n", $payload['context']['skipped']));
    }

    public function testEnterPreparesAlreadyApprovedSimpleTaskExactlyOnce(): void
    {
        file_put_contents($this->root . '/docs/note.txt', "current\n");

        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'ENTER-1',
            'Prepare one already-approved bounded text change.',
            ['docs/note.txt'],
            [],
            ['php -r "exit(0);"'],
            'planner',
        );
        $contracts->approve('ENTER-1', 'approver');

        self::assertDirectoryDoesNotExist($this->root . '/.agent-loop/runs/ENTER-1');
        self::assertFileDoesNotExist($this->root . '/.agent-loop/map/php-symbols.json');

        $recallCalls = 0;
        $recallRunner = function (array $argv) use (&$recallCalls): int {
            ++$recallCalls;
            $this->writeRecallMeta('ENTER-1');

            return 0;
        };

        [$firstExit, $firstPayload] = $this->enter('ENTER-1', $recallRunner);

        self::assertSame(0, $firstExit);
        self::assertSame(1, $recallCalls);
        self::assertTrue($firstPayload['mutation_ready']);
        self::assertSame('governed', $firstPayload['manifest']['mode']);
        self::assertSame('active', $firstPayload['manifest']['references']['session']['state']);
        self::assertSame('compiled', $firstPayload['manifest']['references']['recall']['state']);
        self::assertDirectoryExists($this->root . '/.agent-loop/runs/ENTER-1');
        self::assertFileDoesNotExist($this->root . '/.agent-loop/map/php-symbols.json');

        [$secondExit, $secondPayload] = $this->enter('ENTER-1', $recallRunner);

        self::assertSame(0, $secondExit);
        self::assertSame(1, $recallCalls, 'Repeated enter must not recompile already-current Recall.');
        self::assertTrue($secondPayload['mutation_ready']);
        self::assertSame($firstPayload['manifest']['run_id'], $secondPayload['manifest']['run_id']);
        self::assertSame(
            $firstPayload['manifest']['references']['session']['session_id'],
            $secondPayload['manifest']['references']['session']['session_id'],
        );
    }

    public function testEnterJsonReportsPreparationFailureAsMachineReadableBlocker(): void
    {
        file_put_contents($this->root . '/docs/note.txt', "current\n");

        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'FAIL-1',
            'Keep preparation failure observable to machine callers.',
            ['docs/note.txt'],
            [],
            ['php -r "exit(0);"'],
            'planner',
        );
        $contracts->approve('FAIL-1', 'approver');

        [$exit, $payload] = $this->enter('FAIL-1', static fn (array $argv): int => 7);

        self::assertSame(1, $exit);
        self::assertFalse($payload['mutation_ready']);
        self::assertSame('enter.preparation_failed', $payload['blockers'][0]['code'] ?? null);
        self::assertStringContainsString('Recall compilation failed with exit code 7', $payload['blockers'][0]['message'] ?? '');
        self::assertSame('governed', $payload['manifest']['mode']);
        self::assertSame('active', $payload['manifest']['references']['session']['state']);
        self::assertSame('missing', $payload['manifest']['references']['recall']['state']);
        self::assertSame('agent-loop enter FAIL-1', $payload['next_action']);
    }

    public function testEnterJsonPreservesCallerBufferWhenRecallRunnerClosesItsOwnBuffer(): void
    {
        file_put_contents($this->root . '/docs/note.txt', "current\n");

        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'BUFFER-1',
            'Keep the host JSON document intact across Recall output handling.',
            ['docs/note.txt'],
            [],
            ['php -r "exit(0);"'],
            'planner',
        );
        $contracts->approve('BUFFER-1', 'approver');

        $runner = function (array $argv): int {
            echo "discarded recall output\n";
            ob_end_flush();
            $this->writeRecallMeta('BUFFER-1');

            return 0;
        };

        [$exit, $payload] = $this->enter('BUFFER-1', $runner);

        self::assertSame(0, $exit);
        self::assertTrue($payload['mutation_ready']);
        self::assertSame('compiled', $payload['manifest']['references']['recall']['state']);
    }

    public function testExistingPhpTaskEscalatesToDiscoveryWithoutWeakeningContractAuthority(): void
    {
        if (!mkdir($this->root . '/src', 0o775, true) && !is_dir($this->root . '/src')) {
            throw new RuntimeException('Unable to create PHP fixture directory.');
        }
        file_put_contents($this->root . '/src/Foo.php', "<?php\n\ndeclare(strict_types=1);\n\nfinal class Foo {}\n");

        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'HARD-1',
            'Change existing PHP behavior.',
            ['src/Foo.php'],
            [],
            ['php -r "exit(0);"'],
            'planner',
        );

        self::assertSame(1, $this->approve(new WorkflowApproveCommand($this->root), 'HARD-1'));
        self::assertSame(TaskContract::CANDIDATE, $contracts->load('HARD-1')->status);
        self::assertDirectoryDoesNotExist($this->root . '/.agent-loop/runs/HARD-1');
    }

    private function approve(WorkflowApproveCommand $command, string $taskId): int
    {
        ob_start();
        try {
            return $command->run([$taskId, '--by', 'approver']);
        } finally {
            ob_end_clean();
        }
    }

    /**
     * @param null|callable(list<string>): int $recallRunner
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function enter(string $taskId, ?callable $recallRunner = null): array
    {
        ob_start();
        try {
            $exit = (new HostFrontDoorCommand($this->root, $recallRunner))->run('enter', [$taskId, '--format=json']);
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        if (!is_string($output)) {
            throw new RuntimeException('Host front door did not produce output.');
        }
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('Host front door did not produce a JSON object.');
        }

        return [$exit, $payload];
    }

    private function writeRecallMeta(string $taskId): void
    {
        $directory = RecallOutputRoot::resolve($this->root) . '/' . $taskId;
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create Recall fixture directory.');
        }
        file_put_contents(
            $directory . '/meta.json',
            json_encode([
                'schema_version' => '1.0',
                'task_id' => $taskId,
                'compilation_id' => $taskId . '-001',
                'bundle_sha256' => str_repeat('a', 64),
                'selected_guidance' => [],
                'selected_constraints' => [],
                'output_hashes' => [],
            ], JSON_THROW_ON_ERROR),
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
