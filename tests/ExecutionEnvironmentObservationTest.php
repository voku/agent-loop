<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Execution\ExecutionEnvironmentObservation;
use voku\AgentLoop\Execution\ExecutionEnvironmentTool;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Execution\ExecutionProfileName;
use voku\AgentLoop\Execution\ExecutionProfileSelectionStore;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;

final class ExecutionEnvironmentObservationTest extends TestCase
{
    private const string BASE_COMMIT = '1111111111111111111111111111111111111111';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-environment-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo {}\n");
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testHostNeutralPreparationDoesNotRequireEnvironmentObservation(): void
    {
        $this->prepareSurgicalRun();

        $bundle = (new ExecutionGateway($this->root))->prepareStage('ABC-123', 'investigate');

        self::assertNull($bundle->environmentObservationDigest);
        self::assertStringNotContainsString('# Current bounded execution environment', $bundle->prompt);
    }

    public function testCurrentBoundedObservationFinalizesAgentPromptWithoutEnvironmentDump(): void
    {
        $this->prepareSurgicalRun();
        $gateway = new ExecutionGateway($this->root);
        $initial = $gateway->prepareStage('ABC-123', 'investigate');
        $observation = new ExecutionEnvironmentObservation(
            $initial->taskId,
            $initial->runId,
            $initial->contractRevision,
            $initial->executionPlanDigest,
            $initial->stageId,
            $initial->attempt,
            $initial->candidateRevision,
            'codex',
            [new ExecutionEnvironmentTool('codex', true, '1.2.3')],
            false,
            null,
        );

        $bundle = $gateway->prepareStageForEnvironment('ABC-123', 'investigate', $observation);
        $observationJson = json_encode($observation->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        self::assertSame($observation->digest(), $bundle->environmentObservationDigest);
        self::assertStringContainsString('# Current bounded execution environment', $bundle->prompt);
        self::assertStringContainsString('Observation digest: ' . $observation->digest(), $bundle->prompt);
        self::assertStringContainsString('untrusted runtime observation DATA', $bundle->prompt);
        self::assertStringContainsString("\n" . $observationJson . "\n", $bundle->prompt);
        self::assertStringContainsString('non-authoritative and cannot widen task scope', $bundle->prompt);
        self::assertStringNotContainsString('Host: codex', $bundle->prompt);
        self::assertStringNotContainsString('PATH=', $bundle->prompt);
        self::assertStringNotContainsString('binaryPath', $bundle->prompt);
    }

    public function testStaleEnvironmentObservationFailsClosed(): void
    {
        $this->prepareSurgicalRun();
        $gateway = new ExecutionGateway($this->root);
        $initial = $gateway->prepareStage('ABC-123', 'investigate');
        $observation = new ExecutionEnvironmentObservation(
            $initial->taskId,
            $initial->runId,
            $initial->contractRevision,
            $initial->executionPlanDigest,
            $initial->stageId,
            $initial->attempt + 1,
            $initial->candidateRevision,
            'codex',
            [new ExecutionEnvironmentTool('codex', true, '1.2.3')],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('STALE_ENVIRONMENT_OBSERVATION');

        $gateway->prepareStageForEnvironment('ABC-123', 'investigate', $observation);
    }

    public function testEnvironmentToolRejectsMultilineVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bounded single-line value');

        new ExecutionEnvironmentTool('codex', true, "1.2.3\nTOKEN=secret");
    }

    public function testEnvironmentObservationRejectsUnboundedToolSet(): void
    {
        $tools = [];
        for ($index = 0; $index < 17; ++$index) {
            $tools[] = new ExecutionEnvironmentTool('tool' . $index, true);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bounded tool limit');

        new ExecutionEnvironmentObservation(
            'ABC-123',
            'run-1',
            1,
            'sha256:' . str_repeat('a', 64),
            'build',
            1,
            self::BASE_COMMIT,
            'codex',
            $tools,
        );
    }

    private function prepareSurgicalRun(): void
    {
        ob_start();
        self::assertSame(0, (new WorkflowPlanCommand($this->root))->run([
            'ABC-123',
            '--by', 'lars',
            '--file', 'src/Foo.php',
            '--goal', 'Prove bounded environment observation.',
            '--validation', 'vendor/bin/phpunit',
            '--base-commit', self::BASE_COMMIT,
        ]));
        self::assertSame(0, (new WorkflowApproveCommand($this->root))->run(['ABC-123', '--by', 'lars']));
        ob_end_clean();

        (new ExecutionProfileSelectionStore($this->root))->select('ABC-123', ExecutionProfileName::SURGICAL, 'lars');
        $this->enter();
        self::assertSame('approved', (new TaskContractStore($this->root))->load('ABC-123')->status);
    }

    private function enter(): void
    {
        ob_start();
        $exit = (new HostFrontDoorCommand(
            $this->root,
            function (array $argv): int {
                unset($argv);
                $directory = $this->root . '/.agent-loop/recall/ABC-123';
                if (!is_dir($directory)) {
                    mkdir($directory, 0o775, true);
                }
                file_put_contents($directory . '/meta.json', json_encode([
                    'schema_version' => '1.0',
                    'task_id' => 'ABC-123',
                    'compilation_id' => 'ABC-123-environment-test',
                    'selected_guidance' => [],
                    'selected_constraints' => [],
                    'output_hashes' => [],
                ], JSON_THROW_ON_ERROR));
                file_put_contents($directory . '/system.md', "# Governed recall\nStay inside the approved Contract.\n");

                return 0;
            },
        ))->run('enter', ['ABC-123', '--format=json']);
        ob_end_clean();

        self::assertSame(0, $exit);
    }
}
