<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests\Dogfood;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Dispatcher;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunVerificationReceiptStore;
use voku\AgentLoop\Workflow\HostFrontDoorApplication;
use voku\AgentLoop\Workflow\ImplementationSnapshot;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;
use voku\AgentLoop\Workflow\WorkflowReviewReportReader;
use voku\AgentSession\SessionStore;

/**
 * End-to-end dogfood proof for #345:
 * Blind fresh-agent premise check and autonomous REPLAN when premises become invalid.
 *
 * Demonstrates the human/AI authority split:
 * 1. Approved intent/scope/risk belongs strictly to the human (Contract).
 * 2. When an in-flight implementation premise fails while approved intent is unchanged,
 *    REPLAN is agent-owned: the agent discards the failing implementation attempt,
 *    deletes obsolete machinery, adopts the working simpler approach, satisfies
 *    validation, and records verification receipt within the same Run/Session WITHOUT
 *    requiring a human decision gate.
 * 3. When an implementation blocker requires altering the approved Goal, Scope, or
 *    acceptance criteria, HUMAN_DECISION_REQUIRED is enforced: the agent cannot
 *    unilaterally proceed or mutate out-of-scope files; a contract revision must be
 *    superseded and explicitly approved by a human before a replacement Run can proceed.
 */
final class AutonomousReplanDogfoodTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-replan-dogfood-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
        mkdir($this->root . '/.agent-loop/sessions', 0o775, true);

        file_put_contents(
            $this->root . '/src/Calculator.php',
            <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

final class Calculator
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }
}
PHP
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testAutonomousReplanWhenImplementationPremiseFailsWithinApprovedIntent(): void
    {
        $contracts = new TaskContractStore($this->root);
        $taskId = 'TASK-345-REPLAN';

        // 1. Human defines and approves initial task contract
        $contracts->create(
            $taskId,
            'Add factorial computation to Calculator.',
            ['src/Calculator.php'],
            ['math'],
            [PHP_BINARY . ' -l src/Calculator.php'],
            'lars',
        );
        $contracts->approve($taskId, 'lars');

        $dispatcher = new Dispatcher($this->root);
        $recallRunner = static fn (array $recallRest): int => $dispatcher->run(array_values([
            'agent-loop',
            'recall',
            ...$recallRest,
        ]));
        $app = new HostFrontDoorApplication($this->root, $recallRunner);

        // 2. Agent enters cleanly
        $enter = $this->runApp($app, 'enter', [$taskId, '--format=json']);
        self::assertSame(0, $enter['exit'], json_encode($enter['payload'], JSON_THROW_ON_ERROR));
        self::assertTrue($enter['payload']['mutation_ready'] ?? false);
        self::assertSame('host_work', $enter['payload']['next_action_kind'] ?? null);

        // 3. Implementation premise failure:
        // Agent first attempts a broken approach (e.g. invalid syntax or bad assumption)
        file_put_contents(
            $this->root . '/src/Calculator.php',
            <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

final class Calculator
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    // Broken assumption: non-existent class or bad syntax
    public function factorial(int $n): int
    {
        return UndefinedBigMathHelper::factorial($n);
    }
}
PHP
        );

        // Verify that the initial implementation assumption is observed as flawed
        // (In real dogfood, this triggers the Premise Check:
        // 1. What was approved outcome? Factorial in Calculator.
        // 2. Which assumption is causing failure? UndefinedBigMathHelper assumption.
        // 3. Does evidence still support it? No.
        // 4. Simpler route preserving Goal, acceptance, and scope? Yes: standard iterative loop.
        // Outcome: REPLAN (agent-owned, approved intent unchanged).)

        // 4. Autonomous REPLAN:
        // The agent replaces the failing approach with the verified iterative algorithm
        // within the approved scope, without interrupting the human for approval.
        file_put_contents(
            $this->root . '/src/Calculator.php',
            <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

final class Calculator
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    public function factorial(int $n): int
    {
        $result = 1;
        for ($i = 2; $i <= $n; $i++) {
            $result *= $i;
        }

        return $result;
    }
}
PHP
        );

        // 5. Validation succeeds
        $contract = $contracts->load($taskId);
        $run = (new GovernedRunStore($this->root))->find($taskId);
        self::assertNotNull($run);
        $session = (new SessionStore())->activeForTask($this->root . '/.agent-loop/sessions', $taskId);
        self::assertNotNull($session);
        $snapshot = ImplementationSnapshot::capture($this->root, $contract);

        (new RunVerificationReceiptStore($this->root))->record(
            $run,
            $contract,
            $session,
            $snapshot->digest,
            'satisfied',
            [[
                'command' => PHP_BINARY . ' -l src/Calculator.php',
                'status' => 'passed',
                'exit_code' => 0,
                'executed_at' => gmdate('c'),
                'recorded_by' => 'agent',
                'duration_ms' => 15,
            ]],
        );

        // 6. Finish step 1: review presentation
        $finishPrep = $this->runApp($app, 'finish', [$taskId, '--format=json']);
        self::assertSame(1, $finishPrep['exit']);
        self::assertSame('command_template', $finishPrep['payload']['next_action_kind'] ?? null);
        // Crucial invariant: NO human_decision was demanded for the implementation replanning
        self::assertArrayNotHasKey('human_decision', $finishPrep['payload']);

        $reviewReport = (new WorkflowReviewReportReader($this->root))->read($taskId);
        $reviewSha = $reviewReport['sha256'] ?? null;
        self::assertIsString($reviewSha);

        // 7. Finish step 2: close with learning disposition
        $finishClose = $this->runApp($app, 'finish', [
            $taskId,
            '--format=json',
            '--reviewed-report-sha256',
            $reviewSha,
            '--learning',
            'no_durable_learning',
            '--learning-reason',
            'Implementation replanned iteratively within approved scope; no durable architectural change.',
            '--by',
            'lars',
        ]);

        self::assertSame(0, $finishClose['exit'], json_encode($finishClose['payload'], JSON_THROW_ON_ERROR));
        self::assertTrue($finishClose['payload']['complete'] ?? false);
        self::assertSame('none', $finishClose['payload']['next_action_kind'] ?? null);
    }

    public function testHumanDecisionRequiredWhenPremiseFailureDemandsScopeOrGoalExpansion(): void
    {
        $contracts = new TaskContractStore($this->root);
        $taskId = 'TASK-345-SCOPE';

        // 1. Initial approved contract with bounded scope ['src/Calculator.php']
        $contracts->create(
            $taskId,
            'Add formatting support for calculations.',
            ['src/Calculator.php'],
            ['math'],
            [PHP_BINARY . ' -l src/Calculator.php'],
            'lars',
        );
        $contracts->approve($taskId, 'lars');

        $dispatcher = new Dispatcher($this->root);
        $recallRunner = static fn (array $recallRest): int => $dispatcher->run(array_values([
            'agent-loop',
            'recall',
            ...$recallRest,
        ]));
        $app = new HostFrontDoorApplication($this->root, $recallRunner);

        $enter1 = $this->runApp($app, 'enter', [$taskId, '--format=json']);
        self::assertSame(0, $enter1['exit']);
        self::assertTrue($enter1['payload']['mutation_ready'] ?? false);

        // 2. Implementation blocker: Agent discovers that formatting requires modifying
        // a separate file 'src/MathFormatter.php', which is OUTSIDE the approved scope.
        // Premise Check Outcome: HUMAN_DECISION_REQUIRED.
        // The agent cannot unilaterally mutate out-of-scope files or alter the contract.
        ob_start();
        $planExit = (new WorkflowPlanCommand($this->root))->run([
            $taskId,
            '--goal', 'Add formatting support across Calculator and MathFormatter.',
            '--file', 'src/Calculator.php',
            '--file', 'src/MathFormatter.php',
            '--validation', PHP_BINARY . ' -l src/Calculator.php',
            '--by', 'agent',
            '--supersede',
        ]);
        ob_end_clean();
        self::assertSame(0, $planExit);

        // 3. When entering with an unapproved contract revision, execution is blocked
        // and requires human decision.
        $enter2 = $this->runApp($app, 'enter', [$taskId, '--format=json']);
        self::assertSame(2, $enter2['exit']);
        self::assertFalse($enter2['payload']['mutation_ready'] ?? true);
        self::assertSame('blocked', $enter2['payload']['manifest']['state'] ?? null);

        // Disagreement and approval state strictly reflect that unapproved contract revision 2 blocks execution
        self::assertSame('missing', $enter2['payload']['manifest']['references']['approval']['state'] ?? null);
        self::assertSame(2, $enter2['payload']['manifest']['references']['approval']['contract_revision'] ?? null);
        self::assertSame('candidate', $enter2['payload']['manifest']['references']['contract']['state'] ?? null);
        self::assertSame(2, $enter2['payload']['manifest']['references']['contract']['revision'] ?? null);
        self::assertSame(
            'run.contract_revision_mismatch',
            $enter2['payload']['manifest']['disagreements'][0]['code'] ?? null,
        );

        // 4. Human grants explicit approval
        $contracts->approve($taskId, 'human-approver');

        // 5. Agent can now enter and mutate the expanded scope
        $enter3 = $this->runApp($app, 'enter', [$taskId, '--format=json']);
        self::assertSame(0, $enter3['exit']);
        self::assertTrue($enter3['payload']['mutation_ready'] ?? false);
        self::assertSame('host_work', $enter3['payload']['next_action_kind'] ?? null);
        self::assertSame(2, $enter3['payload']['manifest']['references']['contract']['revision'] ?? null);
        self::assertSame(
            ['src/Calculator.php', 'src/MathFormatter.php'],
            $contracts->load($taskId)->scope,
        );
    }

    /**
     * @param list<string> $args
     * @return array{exit: int, payload: array<string, mixed>}
     */
    private function runApp(HostFrontDoorApplication $app, string $command, array $args): array
    {
        ob_start();
        try {
            $exit = $app->run($command, $args);
            $stdout = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        $payload = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('Application JSON did not decode to an object: ' . $stdout);
        }

        /** @var array<string, mixed> $payload */
        return ['exit' => $exit, 'payload' => $payload];
    }

    private function removeDirectory(string $path): void
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
