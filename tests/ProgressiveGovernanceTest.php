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

        $recallCalls = 0;
        $approve = new WorkflowApproveCommand(
            $this->root,
            function (array $argv) use (&$recallCalls): int {
                ++$recallCalls;
                $this->writeRecallMeta('SIMPLE-1');

                return 0;
            },
        );

        self::assertFileDoesNotExist($this->root . '/.agent-loop/map/php-symbols.json');
        self::assertFileDoesNotExist($this->root . '/.agent-loop/map/search.sqlite');
        self::assertSame(0, $this->approve($approve, 'SIMPLE-1'));
        self::assertSame(1, $recallCalls);
        self::assertSame(TaskContract::APPROVED, $contracts->load('SIMPLE-1')->status);
        self::assertFileDoesNotExist($this->root . '/.agent-loop/map/php-symbols.json');
        self::assertFileDoesNotExist($this->root . '/.agent-loop/map/search.sqlite');

        [$exit, $payload] = $this->enter('SIMPLE-1');

        self::assertSame(0, $exit);
        self::assertTrue($payload['mutation_ready']);
        self::assertSame('governed', $payload['manifest']['mode']);
        self::assertSame('not_configured', $payload['manifest']['references']['board']['state']);
        self::assertSame('missing', $payload['manifest']['references']['map']['state']);
        self::assertSame('missing', $payload['manifest']['references']['search_index']['state']);
        self::assertSame('not_required', $payload['manifest']['references']['execution_contract']['state']);
        self::assertSame('none', $payload['manifest']['references']['edit']['state']);
        self::assertStringContainsString('agent-map: index missing', implode("\n", $payload['context']['skipped']));
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

        $recallCalls = 0;
        $approve = new WorkflowApproveCommand(
            $this->root,
            static function (array $argv) use (&$recallCalls): int {
                ++$recallCalls;

                return 0;
            },
        );

        self::assertSame(1, $this->approve($approve, 'HARD-1'));
        self::assertSame(0, $recallCalls);
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

    /** @return array{0: int, 1: array<string, mixed>} */
    private function enter(string $taskId): array
    {
        ob_start();
        try {
            $exit = (new HostFrontDoorCommand($this->root))->run('enter', [$taskId, '--format=json']);
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
