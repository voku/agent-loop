<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Execution\ExecutionCandidateHasher;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoop\Execution\StageResult;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentLoop\Workflow\WorkflowExecutionProfileCommand;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;

final class StageResultAuthorityTest extends TestCase
{
    private const string VALIDATION = "php -r 'exit(0);'";

    private string $root;

    private string $baseCommit;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-stage-authority-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo {}\n");
        file_put_contents($this->root . '/.gitignore', ".agent-loop/\n");
        $this->git(['init', '-q']);
        $this->git(['config', 'user.email', 'authority@example.test']);
        $this->git(['config', 'user.name', 'Authority Test']);
        $this->git(['add', '.']);
        $this->git(['commit', '-qm', 'base']);
        $this->baseCommit = trim($this->git(['rev-parse', 'HEAD']));
        $this->prepareSurgicalRun();
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testForgedCandidateIdentityFailsClosed(): void
    {
        $gateway = $this->advanceInvestigation();
        $bundle = $gateway->prepareStage('AUTH-1', 'build');
        $gateway->bindStageWorkspace($bundle->taskId, $bundle->stageId, $bundle->attempt, $this->root);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo { public const int VALUE = 1; }\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('StageResult candidate identity does not match owner-observed workspace state');
        $gateway->observeStageResult(new StageResult(
            'submission:forged-candidate',
            $bundle->taskId,
            $bundle->runId,
            $bundle->contractRevision,
            $bundle->executionPlanDigest,
            $bundle->stageId,
            $bundle->attempt,
            StageOutcome::COMPLETED,
            'forged:candidate',
            ['src/Foo.php'],
            [self::VALIDATION],
            'Forged candidate.',
        ), $this->root);
    }

    public function testMutatingCompletionWithoutRequiredValidationEvidenceFailsClosed(): void
    {
        $gateway = $this->advanceInvestigation();
        $bundle = $gateway->prepareStage('AUTH-1', 'build');
        $gateway->bindStageWorkspace($bundle->taskId, $bundle->stageId, $bundle->attempt, $this->root);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo { public const int VALUE = 2; }\n");
        $candidate = (new ExecutionCandidateHasher($this->root))->candidateRevision($this->root, $this->baseCommit);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MISSING_EVIDENCE mutating completion requires every current Contract validation obligation');
        $gateway->observeStageResult(new StageResult(
            'submission:missing-validation',
            $bundle->taskId,
            $bundle->runId,
            $bundle->contractRevision,
            $bundle->executionPlanDigest,
            $bundle->stageId,
            $bundle->attempt,
            StageOutcome::COMPLETED,
            $candidate,
            ['src/Foo.php'],
            [],
            'Missing validation.',
        ), $this->root);
    }

    public function testMissingArtifactEvidenceFailsClosed(): void
    {
        $gateway = $this->advanceInvestigation();
        $bundle = $gateway->prepareStage('AUTH-1', 'build');
        $gateway->bindStageWorkspace($bundle->taskId, $bundle->stageId, $bundle->attempt, $this->root);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo { public const int VALUE = 3; }\n");
        $candidate = (new ExecutionCandidateHasher($this->root))->candidateRevision($this->root, $this->baseCommit);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MISSING_EVIDENCE artifact does not exist');
        $gateway->observeStageResult(new StageResult(
            'submission:missing-artifact',
            $bundle->taskId,
            $bundle->runId,
            $bundle->contractRevision,
            $bundle->executionPlanDigest,
            $bundle->stageId,
            $bundle->attempt,
            StageOutcome::COMPLETED,
            $candidate,
            ['missing.txt'],
            [self::VALIDATION],
            'Missing artifact.',
        ), $this->root);
    }

    public function testCandidateChangeAfterEvidenceObservationFailsClosed(): void
    {
        $gateway = $this->advanceInvestigation();
        $bundle = $gateway->prepareStage('AUTH-1', 'build');
        $gateway->bindStageWorkspace($bundle->taskId, $bundle->stageId, $bundle->attempt, $this->root);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo { public const int VALUE = 4; }\n");
        $candidate = (new ExecutionCandidateHasher($this->root))->candidateRevision($this->root, $this->baseCommit);
        $result = new StageResult(
            'submission:stale-after-observe',
            $bundle->taskId,
            $bundle->runId,
            $bundle->contractRevision,
            $bundle->executionPlanDigest,
            $bundle->stageId,
            $bundle->attempt,
            StageOutcome::COMPLETED,
            $candidate,
            ['src/Foo.php'],
            [self::VALIDATION],
            'Observed candidate.',
        );
        $gateway->observeStageResult($result, $this->root);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo { public const int VALUE = 5; }\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('STALE_EVIDENCE: candidate changed after authoritative evidence observation');
        $gateway->submitStageResult($result, $this->root);
    }

    public function testEvidenceBoundToAnotherRunFailsClosed(): void
    {
        $gateway = $this->advanceInvestigation();
        $bundle = $gateway->prepareStage('AUTH-1', 'build');
        $gateway->bindStageWorkspace($bundle->taskId, $bundle->stageId, $bundle->attempt, $this->root);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo { public const int VALUE = 6; }\n");
        $candidate = (new ExecutionCandidateHasher($this->root))->candidateRevision($this->root, $this->baseCommit);
        $result = new StageResult(
            'submission:wrong-evidence-run',
            $bundle->taskId,
            $bundle->runId,
            $bundle->contractRevision,
            $bundle->executionPlanDigest,
            $bundle->stageId,
            $bundle->attempt,
            StageOutcome::COMPLETED,
            $candidate,
            ['src/Foo.php'],
            [self::VALIDATION],
            'Current candidate.',
        );
        $gateway->observeStageResult($result, $this->root);
        $path = (new ProjectLayout($this->root))->stageResultEvidencePath('AUTH-1', $result->submissionId);
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $data['run_id'] = 'forged-run';
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('STALE_EVIDENCE: authoritative StageResult evidence does not match submitted result');
        $gateway->submitStageResult($result, $this->root);
    }

    public function testValidMutatingEvidenceAdvancesAndExactReplayIsIdempotent(): void
    {
        $gateway = $this->advanceInvestigation();
        $bundle = $gateway->prepareStage('AUTH-1', 'build');
        $gateway->bindStageWorkspace($bundle->taskId, $bundle->stageId, $bundle->attempt, $this->root);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo { public const int VALUE = 7; }\n");
        $candidate = (new ExecutionCandidateHasher($this->root))->candidateRevision($this->root, $this->baseCommit);
        $result = new StageResult(
            'submission:valid-build',
            $bundle->taskId,
            $bundle->runId,
            $bundle->contractRevision,
            $bundle->executionPlanDigest,
            $bundle->stageId,
            $bundle->attempt,
            StageOutcome::COMPLETED,
            $candidate,
            ['src/Foo.php'],
            [self::VALIDATION],
            'Valid candidate.',
        );
        $gateway->observeStageResult($result, $this->root);
        $after = $gateway->submitStageResult($result, $this->root);
        self::assertSame('review', $after->currentStageId);
        self::assertSame($candidate, $after->candidateRevision);

        $replayed = $gateway->submitStageResult($result, $this->root);
        self::assertSame('review', $replayed->currentStageId);
        self::assertSame($candidate, $replayed->candidateRevision);
        self::assertCount(2, $replayed->handoffs);
    }

    private function advanceInvestigation(): ExecutionGateway
    {
        $gateway = new ExecutionGateway($this->root);
        $bundle = $gateway->prepareStage('AUTH-1', 'investigate');
        $gateway->bindStageWorkspace($bundle->taskId, $bundle->stageId, $bundle->attempt, $this->root);
        $result = new StageResult(
            'submission:investigate',
            $bundle->taskId,
            $bundle->runId,
            $bundle->contractRevision,
            $bundle->executionPlanDigest,
            $bundle->stageId,
            $bundle->attempt,
            StageOutcome::COMPLETED,
            $bundle->candidateRevision,
            ['src/Foo.php'],
            [],
            'Investigated.',
        );
        $gateway->observeStageResult($result, $this->root);
        $gateway->submitStageResult($result, $this->root);

        return $gateway;
    }

    private function prepareSurgicalRun(): void
    {
        ob_start();
        self::assertSame(0, (new WorkflowPlanCommand($this->root))->run([
            'AUTH-1',
            '--by', 'lars',
            '--file', 'src/Foo.php',
            '--goal', 'Harden owner-side StageResult acceptance.',
            '--validation', self::VALIDATION,
            '--base-commit', $this->baseCommit,
        ]));
        self::assertSame(0, (new WorkflowApproveCommand($this->root))->run(['AUTH-1', '--by', 'lars']));
        self::assertSame(0, (new WorkflowExecutionProfileCommand($this->root))->run([
            'AUTH-1', '--profile', 'surgical', '--by', 'lars',
        ]));
        ob_end_clean();

        ob_start();
        $exit = (new HostFrontDoorCommand(
            $this->root,
            function (array $argv): int {
                $directory = $this->root . '/.agent-loop/recall/AUTH-1';
                if (!is_dir($directory)) {
                    mkdir($directory, 0o775, true);
                }
                file_put_contents($directory . '/meta.json', json_encode([
                    'schema_version' => '1.0',
                    'task_id' => 'AUTH-1',
                    'compilation_id' => 'AUTH-1-execution-test',
                    'selected_guidance' => [],
                    'selected_constraints' => [],
                    'output_hashes' => [],
                ], JSON_THROW_ON_ERROR));
                file_put_contents($directory . '/system.md', "# Governed recall\nVerify owner evidence.\n");

                return 0;
            },
        ))->run('enter', ['AUTH-1', '--format=json']);
        ob_end_clean();
        self::assertSame(0, $exit);
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): string
    {
        $process = proc_open(
            ['git', '-C', $this->root, ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), is_string($stderr) ? $stderr : 'git failed');

        return is_string($stdout) ? $stdout : '';
    }

    private function rm(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
