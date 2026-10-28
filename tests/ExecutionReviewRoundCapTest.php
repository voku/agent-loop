<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use stdClass;
use voku\AgentLoop\Execution\AttentionKind;
use voku\AgentLoop\Execution\AttentionResolutionStore;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Execution\ExecutionPlanStore;
use voku\AgentLoop\Execution\ExecutionStateStore;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentLoop\Workflow\WorkflowExecutionProfileCommand;
use voku\AgentLoop\Workflow\WorkflowPipelineCommand;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;

/**
 * A governed Run may send at most two review rounds back to build; every third `changes_required` opens human Attention.
 */
final class ExecutionReviewRoundCapTest extends TestCase
{
    private string $root;
    private string $workspace;
    private string $base;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(5));
        $this->root = sys_get_temp_dir() . '/agent-loop-review-cap-' . $suffix;
        $this->workspace = sys_get_temp_dir() . '/agent-loop-review-cap-ws-' . $suffix;
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo {}\n");
        file_put_contents($this->root . '/.gitignore', "/.agent-loop/\n/vendor/\n");
        $this->git($this->root, 'init', '-q');
        $this->git($this->root, 'config', 'user.email', 'x@y.test');
        $this->git($this->root, 'config', 'user.name', 'X');
        $this->git($this->root, 'add', '.');
        $this->git($this->root, 'commit', '-qm', 'base');
        $this->base = $this->git($this->root, 'rev-parse', 'HEAD');
        $this->git($this->root, 'worktree', 'add', '-q', '--detach', $this->workspace, $this->base);

        ob_start();
        try {
            $exits = [
                (new WorkflowPlanCommand($this->root))->run([
                    'SUB-1', '--by', 'planner', '--file', 'src/Foo.php', '--goal', 'Change Foo.',
                    '--validation', 'php -l src/Foo.php', '--base-commit', $this->base,
                ]),
                (new WorkflowApproveCommand($this->root))->run(['SUB-1', '--revision', '1', '--by', 'approver']),
                (new WorkflowExecutionProfileCommand($this->root))->run(['SUB-1', '--profile', 'surgical', '--by', 'approver']),
                (new HostFrontDoorCommand($this->root))->run('enter', ['SUB-1', '--format=json']),
            ];
        } finally {
            $output = (string) ob_get_clean();
        }
        self::assertSame([0, 0, 0, 0], $exits, $output);

        [$exit] = $this->submit(['--outcome', 'completed']);
        self::assertSame(0, $exit);
        self::assertSame('build', $this->projection()->currentStageId);
    }

    protected function tearDown(): void
    {
        exec('git -C ' . escapeshellarg($this->root) . ' worktree remove --force ' . escapeshellarg($this->workspace) . ' 2>/dev/null');
        exec('rm -rf ' . escapeshellarg($this->root) . ' ' . escapeshellarg($this->workspace));
    }

    public function testReviewRequestingChangesSendsTheRunBackToBuildUntilTheCap(): void
    {
        $this->changeWorkspace();
        for ($round = 1; $round < ExecutionStateStore::MAX_REVIEW_ROUNDS; ++$round) {
            $this->buildAndRequestChanges($round);
            self::assertSame('build', $this->projection()->currentStageId, 'round ' . $round);
            self::assertNull($this->projection()->attention);
        }
    }

    public function testTheCappedRoundOpensHumanAttentionInsteadOfReopeningBuild(): void
    {
        $this->changeWorkspace();
        for ($round = 1; $round <= ExecutionStateStore::MAX_REVIEW_ROUNDS; ++$round) {
            $this->buildAndRequestChanges($round);
        }

        $projection = $this->projection();
        self::assertNotNull($projection->attention);
        self::assertSame(AttentionKind::REVIEW_CHANGES_REQUIRED, $projection->attention->kind);
        self::assertSame('review', $projection->currentStageId, 'The Run must not silently continue past the cap.');
        self::assertStringContainsString('human decides', $projection->attention->message);
    }

    public function testNoFurtherResultIsAcceptedWhileTheCapAttentionIsPending(): void
    {
        $this->changeWorkspace();
        for ($round = 1; $round <= ExecutionStateStore::MAX_REVIEW_ROUNDS; ++$round) {
            $this->buildAndRequestChanges($round);
        }

        [$exit, $payload] = $this->submit(['--outcome', 'pass', '--artifact', 'src/Foo.php', '--context-id', 'ctx:review-late']);

        self::assertNotSame(0, $exit);
        self::assertStringContainsString('waiting for attention', json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertSame('review', $this->projection()->currentStageId);
    }

    public function testResolvingTheCapAttentionLetsLaterRoundsReachBuildUntilTheNextCap(): void
    {
        $this->changeWorkspace();
        for ($round = 1; $round <= ExecutionStateStore::MAX_REVIEW_ROUNDS; ++$round) {
            $this->buildAndRequestChanges($round);
        }
        $attention = $this->projection()->attention;
        self::assertNotNull($attention);
        $plan = (new ExecutionPlanStore($this->root))->load('SUB-1');
        (new AttentionResolutionStore($this->root))->record($plan, $attention, $this->projection()->currentAttempt, 'lars');
        (new ExecutionGateway($this->root))->resolveAttention('SUB-1', $attention->id);
        self::assertNull($this->projection()->attention);
        self::assertSame('review', $this->projection()->currentStageId);

        [$exit, $payload] = $this->submit(['--outcome', 'changes_required', '--artifact', 'src/Foo.php', '--context-id', 'ctx:review-after']);
        self::assertSame(0, $exit, (string) json_encode($payload));
        self::assertSame('build', $this->projection()->currentStageId, 'After a human decision the Run must be able to reach build again.');
    }

    public function testTheCapRecursAfterAnotherSetOfRoundsAndNamesTheRealCount(): void
    {
        $this->changeWorkspace();
        for ($round = 1; $round <= ExecutionStateStore::MAX_REVIEW_ROUNDS; ++$round) {
            $this->buildAndRequestChanges($round);
        }
        $attention = $this->projection()->attention;
        self::assertNotNull($attention);
        $plan = (new ExecutionPlanStore($this->root))->load('SUB-1');
        (new AttentionResolutionStore($this->root))->record($plan, $attention, $this->projection()->currentAttempt, 'lars');
        (new ExecutionGateway($this->root))->resolveAttention('SUB-1', $attention->id);

        // The resolved review runs again; its changes_required is round 4 and returns to build.
        [$exit] = $this->submit(['--outcome', 'changes_required', '--artifact', 'src/Foo.php', '--context-id', 'ctx:review-4']);
        self::assertSame(0, $exit);
        self::assertSame('build', $this->projection()->currentStageId);
        $this->buildAndRequestChanges(5);
        self::assertNull($this->projection()->attention);
        $this->buildAndRequestChanges(6);

        $second = $this->projection()->attention;
        self::assertNotNull($second);
        self::assertStringContainsString('6 rounds', $second->message);
    }

    public function testIllegalThirdChangesRequiredDoesNotWriteHistoryOrAttention(): void
    {
        $this->changeWorkspace();
        $this->buildAndRequestChanges(1);
        $this->buildAndRequestChanges(2);

        $store = new ExecutionStateStore($this->root);
        $before = $store->find('SUB-1');
        self::assertNotNull($before);
        self::assertSame('build', $this->projection()->currentStageId);

        [$exit, $payload] = $this->submit([
            '--outcome', 'changes_required', '--context-id', 'ctx:invalid-third',
        ]);

        self::assertNotSame(0, $exit);
        self::assertStringContainsString('not legal for execution stage build', json_encode($payload, JSON_THROW_ON_ERROR));
        $after = $store->find('SUB-1');
        self::assertNotNull($after);
        self::assertCount(count($before->history), $after->history);
        self::assertNull($this->projection()->attention);
        self::assertSame('build', $this->projection()->currentStageId);
    }

    private function buildAndRequestChanges(int $round): void
    {
        [$exit, $payload] = $this->submit(['--outcome', 'completed', '--context-id', 'ctx:build-' . $round]);
        self::assertSame(0, $exit, (string) json_encode($payload));
        [$exit, $payload] = $this->submit(['--outcome', 'changes_required', '--artifact', 'src/Foo.php', '--context-id', 'ctx:review-' . $round]);
        // A round that opens human Attention is a pending decision, not a plain success.
        self::assertSame($round % ExecutionStateStore::MAX_REVIEW_ROUNDS === 0 ? 1 : 0, $exit, (string) json_encode($payload));
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: stdClass}
     */
    private function submit(array $arguments): array
    {
        $level = ob_get_level();
        ob_start();
        try {
            $exit = $this->runSubmit($arguments);
            $output = (string) ob_get_clean();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
        $decoded = json_decode($output, false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $decoded);

        return [$exit, $decoded];
    }

    /** @param list<string> $arguments */
    private function runSubmit(array $arguments): int
    {
        return (new WorkflowPipelineCommand($this->root))->run([
            'submit', 'SUB-1', ...$arguments, '--workspace', $this->workspace, '--format=json',
        ]);
    }

    private function projection(): \voku\AgentLoop\Execution\ExecutionProjection
    {
        return (new ExecutionGateway($this->root))->projection('SUB-1');
    }

    private function changeWorkspace(): void
    {
        file_put_contents($this->workspace . '/src/Foo.php', "<?php\nfinal class Foo { public int \$x = 1; }\n");
    }

    private function git(string $directory, string ...$arguments): string
    {
        exec('git -C ' . escapeshellarg($directory) . ' ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));

        return trim(implode("\n", $output));
    }
}
