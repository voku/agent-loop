<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SplFileInfo;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Workflow\ExecutionContractStore;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentLoop\Workflow\WorkflowExecutionProfileCommand;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;

final class ExecutionGatewayExecutionContractTest extends TestCase
{
    private const string TASK = 'ABC-123';
    private const string BASE_COMMIT = '1111111111111111111111111111111111111111';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-gateway-l1-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo {}\n");
        file_put_contents($this->root . '/operating-prompts.json', json_encode([
            'schema_version' => '1.0',
            'prompts' => [[
                'id' => 'test-l2',
                'level' => 2,
                'template' => 'Create a project-specific L1 execution contract.',
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testL2AgentStageFailsClosedUntilExecutionContractIsReady(): void
    {
        $this->prepareSurgicalRun(l2: true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EXECUTION_CONTRACT_NOT_READY');
        $this->expectExceptionMessage('state is missing');

        (new ExecutionGateway($this->root))->prepareStage(self::TASK, 'investigate');
    }

    public function testReadyL2ContractBecomesExecutorPromptAndCarriesExactSource(): void
    {
        $this->prepareSurgicalRun(l2: true);
        $store = new ExecutionContractStore($this->root);
        $store->writeReady(self::TASK, 'constructor', $this->l1Contract());

        $bundle = (new ExecutionGateway($this->root))->prepareStage(self::TASK, 'investigate');

        self::assertNotNull($bundle->executionContractSource);
        self::assertSame(
            '.agent-loop/recall/' . self::TASK . '/execution-contract.md',
            $bundle->executionContractSource['path'],
        );
        self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', $bundle->executionContractSource['sha256']);
        self::assertStringContainsString('# Governed execution contract', $bundle->prompt);
        self::assertStringContainsString('Inspect the exact current owner boundary before changing code.', $bundle->prompt);
        self::assertStringNotContainsString('# Governed Recall', $bundle->prompt);
        self::assertStringNotContainsString('L2 Operational Prompt Construction', $bundle->prompt);
        self::assertStringNotContainsString('Create a project-specific L1 execution contract.', $bundle->prompt);
    }

    public function testStaleL2ContractFailsClosedAtStagePreparation(): void
    {
        $this->prepareSurgicalRun(l2: true);
        $store = new ExecutionContractStore($this->root);
        $store->writeReady(self::TASK, 'constructor', $this->l1Contract());

        $factsPath = $this->root . '/.agent-loop/recall/' . self::TASK . '/facts.json';
        $facts = json_decode((string) file_get_contents($factsPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($facts);
        $facts['bundle_sha256'] = str_repeat('b', 64);
        file_put_contents($factsPath, json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EXECUTION_CONTRACT_NOT_READY');
        $this->expectExceptionMessage('state is stale');

        (new ExecutionGateway($this->root))->prepareStage(self::TASK, 'investigate');
    }

    public function testNoL2TaskKeepsExistingRecallProjection(): void
    {
        $this->prepareSurgicalRun(l2: false);

        $bundle = (new ExecutionGateway($this->root))->prepareStage(self::TASK, 'investigate');

        self::assertNull($bundle->executionContractSource);
        self::assertStringContainsString('# Governed Recall', $bundle->prompt);
        self::assertStringContainsString('Stay inside the approved Contract.', $bundle->prompt);
        self::assertStringNotContainsString('# Governed execution contract', $bundle->prompt);
    }

    private function prepareSurgicalRun(bool $l2): void
    {
        $plan = [
            self::TASK,
            '--by', 'lars',
            '--file', 'src/Foo.php',
            '--goal', 'Prove the executor consumes the current governed contract.',
            '--validation', 'vendor/bin/phpunit',
            '--base-commit', self::BASE_COMMIT,
        ];
        if ($l2) {
            $plan[] = '--operating-prompt-manifest';
            $plan[] = 'operating-prompts.json';
            $plan[] = '--operating-prompt';
            $plan[] = '{"id":"test-l2","arguments":{}}';
        }

        ob_start();
        self::assertSame(0, (new WorkflowPlanCommand($this->root))->run($plan));
        self::assertSame(0, (new WorkflowApproveCommand($this->root))->run([self::TASK, '--by', 'lars']));
        self::assertSame(0, (new WorkflowExecutionProfileCommand($this->root))->run([
            self::TASK, '--profile', 'surgical', '--by', 'lars',
        ]));
        ob_end_clean();

        ob_start();
        $exit = (new HostFrontDoorCommand(
            $this->root,
            function (array $argv) use ($l2): int {
                $directory = $this->root . '/.agent-loop/recall/' . self::TASK;
                if (!is_dir($directory)) {
                    mkdir($directory, 0o775, true);
                }
                file_put_contents($directory . '/meta.json', json_encode([
                    'schema_version' => '1.0',
                    'task_id' => self::TASK,
                    'compilation_id' => self::TASK . '-execution-contract-test',
                    'selected_guidance' => [],
                    'selected_constraints' => [],
                    'output_hashes' => [],
                ], JSON_THROW_ON_ERROR));
                file_put_contents(
                    $directory . '/system.md',
                    $l2
                        ? "# Governed recall\n## L2 Operational Prompt Construction\nCreate a project-specific L1 execution contract.\n"
                        : "# Governed recall\nStay inside the approved Contract.\n",
                );
                if ($l2) {
                    file_put_contents($directory . '/facts.json', json_encode([
                        'schema_version' => '1.0',
                        'bundle_sha256' => str_repeat('a', 64),
                        'facts' => [[
                            'id' => 'operating-prompt.test-l2',
                            'type' => 'operating_prompt',
                            'authority' => 'approved_contract',
                            'source_ref' => 'operating-prompts.json#test-l2',
                            'scope' => ['src/Foo.php'],
                            'payload' => [
                                'prompt_id' => 'test-l2',
                                'level' => 2,
                                'arguments' => [],
                                'content' => 'Create a project-specific L1 execution contract.',
                                'template_sha256' => str_repeat('c', 64),
                            ],
                        ]],
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                }

                return 0;
            },
        ))->run('enter', [self::TASK, '--format=json']);
        ob_end_clean();

        self::assertSame($l2 ? 1 : 0, $exit);
    }

    private function l1Contract(): string
    {
        return <<<'MD'
## Goal
Execute the currently approved bounded task.

## Context
Inspect the exact current owner boundary before changing code.

## Constraints
Stay inside src/Foo.php and preserve workflow authority.

## Verification
Run `vendor/bin/phpunit` and inspect the resulting diff.

## Done When
The bounded task is implemented and the declared verification passes.
MD;
    }

    private function rm(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $directories = [$path];
        for ($index = 0; $index < count($directories); ++$index) {
            foreach (new FilesystemIterator($directories[$index], FilesystemIterator::SKIP_DOTS) as $item) {
                if (!$item instanceof SplFileInfo) {
                    throw new RuntimeException('FilesystemIterator returned an unexpected entry type.');
                }
                if ($item->isDir() && !$item->isLink()) {
                    $directories[] = $item->getPathname();
                    continue;
                }
                unlink($item->getPathname());
            }
        }

        foreach (array_reverse($directories) as $directory) {
            rmdir($directory);
        }
    }
}
