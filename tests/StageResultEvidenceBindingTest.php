<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Execution\ExecutionPlan;
use voku\AgentLoop\Execution\ExecutionProfileName;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoop\Execution\StageResult;
use voku\AgentLoop\Execution\StageResultEvidence;
use voku\AgentLoop\Execution\StageResultEvidenceStore;
use voku\AgentLoop\ProjectLayout;

final class StageResultEvidenceBindingTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-evidence-binding-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($this->root, 0o775, true));
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    /** @return iterable<string, array{non-empty-string, int|non-empty-string}> */
    public static function staleBindings(): iterable
    {
        yield 'run' => ['run_id', 'RUN-OTHER'];
        yield 'contract revision' => ['contract_revision', 2];
        yield 'plan' => ['execution_plan_digest', 'sha256:' . str_repeat('f', 64)];
        yield 'stage' => ['stage_id', 'other-stage'];
        yield 'attempt' => ['attempt', 2];
    }

    /** @param non-empty-string $field */
    #[DataProvider('staleBindings')]
    public function testEvidenceFromAnotherAuthorityBindingFailsClosed(string $field, int|string $value): void
    {
        $plan = new ExecutionPlan(
            'TASK-1',
            'RUN-1',
            1,
            ['path' => '.agent-loop/contracts/TASK-1/contract.json', 'sha256' => 'sha256:' . str_repeat('a', 64)],
            str_repeat('1', 40),
            ExecutionProfileName::MANUAL,
            [],
            [],
            '2026-08-23T00:00:00+00:00',
        );
        $result = new StageResult(
            'submission:binding',
            'TASK-1',
            'RUN-1',
            1,
            $plan->digest(),
            'build',
            1,
            StageOutcome::COMPLETED,
            'candidate:one',
            ['src/Foo.php'],
            ['php -r "exit(0);"'],
            'Candidate complete.',
        );
        $evidence = new StageResultEvidence(
            $result->submissionId,
            $result->taskId,
            $result->runId,
            $result->contractRevision,
            $result->executionPlanDigest,
            $result->stageId,
            $result->attempt,
            $result->candidateRevision,
            'workspace:sha256:' . str_repeat('b', 64),
            ['src/Foo.php' => 'sha256:' . str_repeat('c', 64)],
            ['php -r "exit(0);"' => 0],
            '2026-08-23T00:00:01+00:00',
        );
        $data = $evidence->toArray();
        $data[$field] = $value;

        $path = (new ProjectLayout($this->root))->stageResultEvidencePath($result->taskId, $result->submissionId);
        self::assertTrue(mkdir(dirname($path), 0o775, true));
        self::assertNotFalse(file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('STALE_EVIDENCE: authoritative StageResult evidence does not match submitted result');
        (new StageResultEvidenceStore($this->root))->assertMatches($plan, $result);
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        if (!is_array($entries)) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $candidate = $path . '/' . $entry;
            if (is_dir($candidate)) {
                $this->remove($candidate);
            } else {
                unlink($candidate);
            }
        }
        rmdir($path);
    }
}
