<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLearning\RunLearningDecisionStatus;
use voku\AgentLearning\RunLearningDecisionStore;
use voku\AgentLoop\Execution\AcceptedStageResult;
use voku\AgentLoop\Execution\ExecutionState;
use voku\AgentLoop\Execution\ExecutionStateStore;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoop\Execution\StageResult;
use voku\AgentLoop\Run\GovernedRun;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunPolicyEvaluation;
use voku\AgentLoop\Workflow\HostFrontDoorApplication;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowHumanDecisionProjector;
use voku\AgentLoop\Workflow\WorkflowReviewReportReader;
use voku\AgentSession\SessionStore;

/**
 * Drives `finish` and `workflow learn` through the review-observation gate at
 * the CLI boundary: the observations are visible at the decision point, the
 * template does not offer the refused choice, and each refusal names its own
 * command's flags (#1120).
 *
 * @internal
 */
final class ReviewObservationLearningGateTest extends TestCase
{
    private const string TASK = 'OBS-GATE-1';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-observation-gate-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nreturn 'current';\n");
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

    public function testFinishShowsObservationsAtTheDecisionAndRefusesNoDurableLearning(): void
    {
        $run = $this->prepareLearningDecision(['Budget 0 still admits one target.']);

        $pending = $this->finishJson([]);
        $encoded = json_encode($pending['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        self::assertStringContainsString('"review_observations":["Budget 0 still admits one target."]', $encoded);
        self::assertStringContainsString('<findings_recorded|follow_up_required>', $encoded);
        self::assertStringNotContainsString('<no_durable_learning|', $encoded);

        $refused = $this->finishJson(['--learning', 'no_durable_learning', '--by', 'fixture-reviewer']);
        self::assertSame(1, $refused['exit']);
        $message = json_encode($refused['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        self::assertStringContainsString('finish cannot record no_durable_learning', $message);
        self::assertStringContainsString('Budget 0 still admits one target.', $message);
        self::assertStringContainsString('--follow-up-ref <ref>', $message);
        self::assertNull($this->decisionStore()->find($run->runId));

        $followUp = $this->finishJson(['--follow-up-ref', 'voku/agent-loop#1120', '--by', 'fixture-reviewer']);
        self::assertSame(0, $followUp['exit'], json_encode($followUp['payload'], JSON_THROW_ON_ERROR));
        self::assertSame(RunLearningDecisionStatus::FOLLOW_UP_REQUIRED, $this->decisionStore()->find($run->runId)?->decision);
    }

    public function testHumanDecisionProjectionDoesNotOfferTheRefusedDisposition(): void
    {
        $this->prepareLearningDecision(['Budget 0 still admits one target.']);

        $decision = (new WorkflowHumanDecisionProjector($this->root))->project(self::TASK, 'agent-loop finish ' . self::TASK, RunPolicyEvaluation::KIND_DECISION_REQUIRED);

        self::assertIsArray($decision);
        $encoded = json_encode($decision, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        self::assertStringContainsString('"allowed_dispositions":["findings_recorded","follow_up_required"]', $encoded);
        self::assertStringContainsString('"review_observations":["Budget 0 still admits one target."]', $encoded);
    }

    public function testWorkflowLearnRefusalNamesItsOwnFlags(): void
    {
        $run = $this->prepareLearningDecision(['Guide names a bare sync-githooks command.']);

        $result = $this->runCli(['workflow', 'learn', self::TASK, '--status', 'no_durable_learning', '--by', 'fixture-reviewer']);
        $exit = $result['exit'];
        $stderr = $result['stderr'];

        self::assertSame(1, $exit);
        self::assertStringContainsString('workflow learn cannot record no_durable_learning', $stderr);
        self::assertStringContainsString('--follow-up <ref>', $stderr);
        self::assertStringNotContainsString('--follow-up-ref', $stderr);
        self::assertStringNotContainsString('--finding-observation', $stderr);
        self::assertNull($this->decisionStore()->find($run->runId));
    }

    public function testFinishWithoutObservationsStillOffersNoDurableLearning(): void
    {
        $run = $this->prepareLearningDecision([]);

        $pending = $this->finishJson([]);
        $encoded = json_encode($pending['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        self::assertStringContainsString('<no_durable_learning|findings_recorded|follow_up_required>', $encoded);

        $recorded = $this->finishJson(['--learning', 'no_durable_learning', '--by', 'fixture-reviewer']);
        self::assertSame(0, $recorded['exit'], json_encode($recorded['payload'], JSON_THROW_ON_ERROR));
        self::assertSame(RunLearningDecisionStatus::NO_DURABLE_LEARNING, $this->decisionStore()->find($run->runId)?->decision);
    }

    public function testFastPathAutoCloseNeitherOverridesObservationsNorTheHostsChoice(): void
    {
        file_put_contents($this->root . '/src/QuickTarget.php', "<?php\nfinal class QuickTarget {}\n");
        foreach (['init -q -b main', 'config user.name T', 'config user.email t@example.test', 'add src/QuickTarget.php', 'commit -qm base'] as $git) {
            exec('git -C ' . escapeshellarg($this->root) . ' ' . $git . ' 2>&1');
        }
        $quick = $this->runCli(['quick', 'Q-1', 'Fix docblock', '--file=src/QuickTarget.php', '--verify=php -r "exit(0);"', '--format=json']);
        self::assertSame(0, $quick['exit'], $quick['stderr']);
        $run = (new GovernedRunStore($this->root))->find('Q-1');
        self::assertNotNull($run);
        $contract = (new TaskContractStore($this->root))->load('Q-1');
        $this->writePassingReview('Q-1', $run, $contract->revision, ['Known limit X.']);

        // The auto-close must not record no_durable_learning over a recorded observation.
        $plain = $this->runCli(['finish', 'Q-1', '--format=json']);
        self::assertStringContainsString('Known limit X.', $plain['stdout']);
        self::assertNull($this->decisionStore()->find($run->runId));

        // The remedy the refusal names works on the fast path too.
        $followUp = $this->runCli(['finish', 'Q-1', '--follow-up-ref', 'voku/agent-loop#1120', '--by', 'fixture-reviewer', '--format=json']);
        self::assertSame(0, $followUp['exit'], $followUp['stdout'] . $followUp['stderr']);
        self::assertSame(RunLearningDecisionStatus::FOLLOW_UP_REQUIRED, $this->decisionStore()->find($run->runId)?->decision);
    }

    /** @param list<string> $observations */
    private function writePassingReview(string $taskId, GovernedRun $run, int $revision, array $observations): void
    {
        $digest = 'sha256:' . str_repeat('b', 64);
        $review = new StageResult('submission:review', $taskId, $run->runId, $revision, $digest, 'review', 1, StageOutcome::PASS, 'candidate', [], [], 'Review passed.', 'ctx:review', observations: $observations);
        $state = new ExecutionState($taskId, $run->runId, $revision, $digest, null, 1, 'candidate', null, [new AcceptedStageResult($review, null, '2026-11-21T00:00:00Z')]);
        $path = (new ExecutionStateStore($this->root))->path($taskId);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o775, true);
        }
        file_put_contents($path, json_encode($state->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    /** @param list<string> $observations */
    private function prepareLearningDecision(array $observations): GovernedRun
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(self::TASK, 'Exercise the review-observation Learning gate.', ['src/Foo.php'], [], ['php -r "exit(0);"'], 'fixture-planner');
        $contract = $contracts->approve(self::TASK, 'fixture-approver', $contracts->load(self::TASK)->revision);
        $session = (new SessionStore())->create($this->root . '/.agent-loop/sessions', self::TASK, by: 'fixture-agent');
        $run = (new GovernedRunStore($this->root))->prepare($contract, $session, $this->root . '/.agent-loop/learning');

        $recall = $this->root . '/.agent-loop/recall/' . self::TASK;
        mkdir($recall, 0o775, true);
        file_put_contents($recall . '/meta.json', json_encode([
            'schema_version' => '1.0',
            'task_id' => self::TASK,
            'compilation_id' => 'obs-gate-fixture',
            'bundle_sha256' => str_repeat('a', 64),
            'selected_guidance' => [],
            'selected_constraints' => [],
            'output_hashes' => [],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($recall . '/validation-plan.md', "# Validation\n\nRun the approved command.\n");

        // An accepted passing review of this Run, as pipeline submit stores it.
        $this->writePassingReview(self::TASK, $run, $contract->revision, $observations);

        $prepared = $this->finishJson([]);
        self::assertSame(1, $prepared['exit']);
        $report = (new WorkflowReviewReportReader($this->root))->read(self::TASK);
        self::assertIsString($report['sha256']);
        $ack = $this->finishJson(['--reviewed-report-sha256', $report['sha256'], '--by', 'fixture-reviewer']);
        self::assertSame(1, $ack['exit'], json_encode($ack['payload'], JSON_THROW_ON_ERROR));

        return $run;
    }

    private function decisionStore(): RunLearningDecisionStore
    {
        return new RunLearningDecisionStore($this->root . '/.agent-loop/learning');
    }

    /**
     * @param list<string> $options
     * @return array{exit: int, payload: array<string, mixed>}
     */
    private function finishJson(array $options): array
    {
        ob_start();
        try {
            $exit = (new HostFrontDoorApplication($this->root))->run('finish', [self::TASK, '--format=json', ...$options]);
            $stdout = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $payload = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('Finish JSON did not decode to an object.');
        }

        return ['exit' => $exit, 'payload' => $payload];
    }

    /**
     * @param list<string> $arguments
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runCli(array $arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bin/agent-loop', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
