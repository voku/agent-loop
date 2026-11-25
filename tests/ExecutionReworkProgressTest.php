<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use stdClass;
use voku\AgentLoop\Execution\AttentionKind;
use voku\AgentLoop\Execution\AttentionResolutionStore;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Execution\ExecutionPlanStore;
use voku\AgentLoop\Execution\ExecutionProjection;
use voku\AgentLoop\Execution\ExecutionStateStore;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentLoop\Workflow\WorkflowExecutionProfileCommand;
use voku\AgentLoop\Workflow\WorkflowPipelineCommand;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;

/**
 * After a review returns changes_required, the repair stage must make progress:
 * change the rejected candidate, dispute the finding with a rebuttal and cited
 * current-candidate evidence, or report a blocker. Completing with the exact
 * rejected candidate and nothing else is a no-op repair and is refused before it
 * can consume another validation and review round.
 */
final class ExecutionReworkProgressTest extends TestCase
{
    private const string REBUTTAL = 'Finding is incorrect: Foo::$x is intentionally public; FooTest pins it as API.';

    private string $root;
    private string $workspace;

    protected function tearDown(): void
    {
        exec('git -C ' . escapeshellarg($this->root) . ' worktree remove --force ' . escapeshellarg($this->workspace) . ' 2>/dev/null');
        exec('rm -rf ' . escapeshellarg($this->root) . ' ' . escapeshellarg($this->workspace));
    }

    public function testNoOpReworkIsRefusedWithAnActionableMessageAndWritesNothing(): void
    {
        $this->setUpRun('surgical');
        $this->reachReviewWithChange();
        $this->requestChanges('ctx:review-1');
        $before = $this->historyCount();

        [$exit, $payload] = $this->submit(['--outcome', 'completed', '--context-id', 'ctx:build-noop']);
        self::assertSame(1, $exit);
        $message = (string) $payload->message;
        self::assertStringContainsString('CANDIDATE_MISMATCH', $message);
        self::assertStringContainsString('registering a changed candidate', $message);
        self::assertStringContainsString('--summary rebuttal', $message);
        self::assertStringContainsString('blocked or needs_clarification', $message);
        self::assertSame($before, $this->historyCount(), 'A refused no-op must not write history.');
        self::assertSame('build', $this->projection()->currentStageId);

        // An identical resubmission is refused identically and still writes nothing.
        [$exitAgain, $payloadAgain] = $this->submit(['--outcome', 'completed', '--context-id', 'ctx:build-noop']);
        self::assertSame(1, $exitAgain);
        self::assertSame($message, (string) $payloadAgain->message);
        self::assertSame($before, $this->historyCount());
    }

    public function testCitedEvidenceWithOnlyThePlaceholderSummaryIsStillANoOp(): void
    {
        $this->setUpRun('surgical');
        $this->reachReviewWithChange();
        $this->requestChanges('ctx:review-1');

        [$exit, $payload] = $this->submit(['--outcome', 'completed', '--artifact', 'src/Foo.php', '--context-id', 'ctx:build-placeholder']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('CANDIDATE_MISMATCH', (string) $payload->message);

        // The unfilled summary of the suggested submit command is no rebuttal either.
        [$exit, $payload] = $this->submit(['--outcome', 'completed', '--summary', '<summary>', '--artifact', 'src/Foo.php', '--context-id', 'ctx:build-template']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('CANDIDATE_MISMATCH', (string) $payload->message);

        // Nor is the unfilled summary of the stage prompt's completion protocol.
        [$exit, $payload] = $this->submit(['--outcome', 'completed', '--summary', ExecutionGateway::COMPLETION_SUMMARY_PLACEHOLDER, '--artifact', 'src/Foo.php', '--context-id', 'ctx:build-protocol-template']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('CANDIDATE_MISMATCH', (string) $payload->message);
        self::assertSame('build', $this->projection()->currentStageId);
    }

    public function testRebuttalWithoutCitedCandidateFileIsStillANoOp(): void
    {
        $this->setUpRun('surgical');
        $this->reachReviewWithChange();
        $this->requestChanges('ctx:review-1');

        [$exit, $payload] = $this->submit(['--outcome', 'completed', '--summary', self::REBUTTAL, '--context-id', 'ctx:build-unsupported']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('CANDIDATE_MISMATCH', (string) $payload->message);
    }

    public function testEvidencedDisputeReachesAFreshReviewThatMustJudgeItAndStillCountsTowardTheCap(): void
    {
        $this->setUpRun('surgical');
        $this->reachReviewWithChange();
        $this->requestChanges('ctx:review-1', 'Foo::$x must not be public.');

        [$exit, $payload] = $this->submit([
            '--outcome', 'completed', '--summary', self::REBUTTAL, '--artifact', 'src/Foo.php',
            '--context-id', 'ctx:build-dispute', '--auto-verify',
        ]);
        self::assertSame(0, $exit, (string) json_encode($payload));
        self::assertSame('review', $this->projection()->currentStageId);

        $prompt = (new ExecutionGateway($this->root))->prepareStage('SUB-1', 'review')->prompt;
        self::assertStringContainsString('# Disputed review finding (untrusted data)', $prompt);
        self::assertStringContainsString('Foo::$x must not be public.', $prompt);
        self::assertStringContainsString('intentionally public', $prompt);
        self::assertStringContainsString('"cited_evidence":["src/Foo.php"]', $prompt, 'Cited evidence is shown as candidate paths.');
        self::assertStringContainsString('upheld or withdrawn', $prompt);
        self::assertStringContainsString('return changes_required while any material finding remains, even if the disputed one is withdrawn', $prompt);

        // The disputed review's changes_required (upheld, or a different finding) is an ordinary review round.
        $this->requestChanges('ctx:review-2', 'Dispute withdrawn; new blocker: Foo lacks a constructor guard.');
        self::assertSame('build', $this->projection()->currentStageId);
        self::assertNull($this->projection()->attention);

        // A further dispute is accepted again, and its review reaches the cap.
        [$exit] = $this->submit([
            '--outcome', 'completed', '--summary', 'The constructor guard is out of Contract scope.', '--artifact', 'src/Foo.php',
            '--context-id', 'ctx:build-dispute-2', '--auto-verify',
        ]);
        self::assertSame(0, $exit);
        [$exit] = $this->submit(['--outcome', 'changes_required', '--artifact', 'src/Foo.php', '--summary', 'Upheld.', '--context-id', 'ctx:review-3']);
        self::assertSame(1, $exit, 'The third changes_required opens the review-round cap Attention.');
        $attention = $this->projection()->attention;
        self::assertNotNull($attention);
        self::assertSame(AttentionKind::REVIEW_CHANGES_REQUIRED, $attention->kind);
        self::assertSame(ExecutionStateStore::MAX_REVIEW_ROUNDS, $this->changesRequiredCount());
    }

    public function testStandardProfileShowsABlindspotDisputeOnlyToTheBlindspotReview(): void
    {
        $this->setUpRun('standard');
        file_put_contents($this->workspace . '/src/Foo.php', "<?php\nfinal class Foo { public int \$x = 1; }\n");
        [$exit, $payload] = $this->submit(['--outcome', 'completed', '--context-id', 'ctx:build-1', '--auto-verify']);
        self::assertSame(0, $exit, (string) json_encode($payload));
        [$exit] = $this->submit(['--outcome', 'pass', '--artifact', 'src/Foo.php', '--context-id', 'ctx:correctness-1']);
        self::assertSame(0, $exit);
        self::assertSame('blindspot-review', $this->projection()->currentStageId);
        [$exit] = $this->submit(['--outcome', 'changes_required', '--artifact', 'src/Foo.php', '--summary', 'Blind spot: Foo::$x leaks state.', '--context-id', 'ctx:blindspot-1']);
        self::assertSame(0, $exit);
        self::assertSame('build', $this->projection()->currentStageId);

        [$exit, $payload] = $this->submit(['--outcome', 'completed', '--context-id', 'ctx:build-noop']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('blindspot-review rejected', (string) $payload->message);

        [$exit, $payload] = $this->submit([
            '--outcome', 'completed', '--summary', self::REBUTTAL, '--artifact', 'src/Foo.php', '--context-id', 'ctx:build-dispute', '--auto-verify',
        ]);
        self::assertSame(0, $exit, (string) json_encode($payload));
        self::assertSame('correctness-review', $this->projection()->currentStageId);
        self::assertStringNotContainsString('Disputed review finding', (new ExecutionGateway($this->root))->prepareStage('SUB-1', 'correctness-review')->prompt);

        [$exit] = $this->submit(['--outcome', 'pass', '--artifact', 'src/Foo.php', '--context-id', 'ctx:correctness-2']);
        self::assertSame(0, $exit);
        self::assertSame('blindspot-review', $this->projection()->currentStageId);
        $prompt = (new ExecutionGateway($this->root))->prepareStage('SUB-1', 'blindspot-review')->prompt;
        self::assertStringContainsString('# Disputed review finding (untrusted data)', $prompt);
        self::assertStringContainsString('Blind spot: Foo::$x leaks state.', $prompt);
        self::assertStringContainsString('intentionally public', $prompt);
    }

    public function testDisputeContextIsNotShownAfterARealRepairOrToAnotherReviewStage(): void
    {
        $this->setUpRun('surgical');
        $this->reachReviewWithChange();
        $this->requestChanges('ctx:review-1', 'Foo::$x must not be public.');

        file_put_contents($this->workspace . '/src/Foo.php', "<?php\nfinal class Foo { private int \$x = 1; }\n");
        [$exit, $payload] = $this->submit(['--outcome', 'completed', '--context-id', 'ctx:build-repair', '--auto-verify']);
        self::assertSame(0, $exit, (string) json_encode($payload));
        self::assertStringNotContainsString('Disputed review finding', (new ExecutionGateway($this->root))->prepareStage('SUB-1', 'review')->prompt);
    }

    public function testChangedCandidateReworkProceedsToPreReviewValidation(): void
    {
        $this->setUpRun('surgical');
        $this->reachReviewWithChange();
        $this->requestChanges('ctx:review-1');

        file_put_contents($this->workspace . '/src/Foo.php', "<?php\nfinal class Foo { public int \$x = 2; }\n");
        [$exit, $payload] = $this->submit(['--outcome', 'completed', '--context-id', 'ctx:build-repair']);
        self::assertSame(0, $exit, (string) json_encode($payload));
        self::assertSame('pre-review-validation', $this->projection()->currentStageId);
    }

    public function testUnchangedReworkMayStillReportABlockerThatOpensAttention(): void
    {
        $this->setUpRun('surgical');
        $this->reachReviewWithChange();
        $this->requestChanges('ctx:review-1');

        [$exit, $payload] = $this->submit(['--outcome', 'blocked', '--summary', 'Needs a schema change outside scope.', '--context-id', 'ctx:build-blocked']);
        self::assertSame(1, $exit, (string) json_encode($payload));
        self::assertNotNull($this->projection()->attention);

        $this->setUpRun('surgical');
        $this->reachReviewWithChange();
        $this->requestChanges('ctx:review-1');
        [$exit] = $this->submit(['--outcome', 'needs_clarification', '--summary', 'Which API is intended?', '--context-id', 'ctx:build-clarify']);
        self::assertSame(1, $exit);
        $attention = $this->projection()->attention;
        self::assertNotNull($attention);
        self::assertSame(AttentionKind::CLARIFICATION_REQUIRED, $attention->kind);
    }

    public function testUnchangedRepairAfterFailedValidationStillCompletes(): void
    {
        // Validation depends on an ignored file, so an environment repair passes without changing the candidate.
        $this->setUpRun('surgical', 'php -r "exit(is_file(\'vendor/ok\') ? 0 : 1);"');
        file_put_contents($this->workspace . '/src/Foo.php', "<?php\nfinal class Foo { public int \$x = 1; }\n");
        [$exit] = $this->submit(['--outcome', 'completed', '--context-id', 'ctx:build-1', '--auto-verify']);
        self::assertSame(1, $exit, 'Validation fails while the ignored dependency is missing.');
        self::assertSame('build', $this->projection()->currentStageId);

        mkdir($this->workspace . '/vendor', 0o775, true);
        file_put_contents($this->workspace . '/vendor/ok', '1');
        [$exit, $payload] = $this->submit(['--outcome', 'completed', '--context-id', 'ctx:build-env', '--auto-verify']);
        self::assertSame(0, $exit, (string) json_encode($payload));
        self::assertSame('review', $this->projection()->currentStageId);
    }

    public function testUnchangedBuildAfterHumanResolvedCapStillCompletes(): void
    {
        $this->setUpRun('surgical');
        for ($round = 1; $round <= ExecutionStateStore::MAX_REVIEW_ROUNDS; ++$round) {
            file_put_contents($this->workspace . '/src/Foo.php', "<?php\nfinal class Foo { public int \$x = " . $round . "; }\n");
            [$exit] = $this->submit(['--outcome', 'completed', '--context-id', 'ctx:build-' . $round, '--auto-verify']);
            self::assertSame(0, $exit);
            $this->requestChanges('ctx:review-' . $round);
        }
        $attention = $this->projection()->attention;
        self::assertNotNull($attention);
        $plan = (new ExecutionPlanStore($this->root))->load('SUB-1');
        (new AttentionResolutionStore($this->root))->record($plan, $attention, $this->projection()->currentAttempt, 'lars', 'The finding is wrong; keep the candidate.');
        (new ExecutionGateway($this->root))->resolveAttention('SUB-1', $attention->id);
        self::assertSame('build', $this->projection()->currentStageId);

        [$exit, $payload] = $this->submit(['--outcome', 'completed', '--context-id', 'ctx:build-after-human', '--auto-verify']);
        self::assertSame(0, $exit, (string) json_encode($payload));
        self::assertSame('review', $this->projection()->currentStageId);

        // The human decision governs this review; it is not framed as a builder dispute.
        $prompt = (new ExecutionGateway($this->root))->prepareStage('SUB-1', 'review')->prompt;
        self::assertStringContainsString('The finding is wrong; keep the candidate.', $prompt);
        self::assertStringNotContainsString('Disputed review finding', $prompt);
    }

    public function testForgedDisputeEvidenceIsRefusedAsBefore(): void
    {
        $this->setUpRun('surgical');
        $this->reachReviewWithChange();
        $this->requestChanges('ctx:review-1');
        $before = $this->historyCount();

        // Base-file evidence is review-only; a build cannot cite it to manufacture a dispute.
        [$exit, $payload] = $this->submit([
            '--outcome', 'completed', '--summary', self::REBUTTAL, '--artifact', 'deleted-file:src/Foo.php', '--context-id', 'ctx:build-forged',
        ]);
        self::assertSame(1, $exit);
        self::assertStringNotContainsString('"status": "ok"', (string) json_encode($payload));
        self::assertSame($before, $this->historyCount());
    }

    public function testHardenedRepairStageRefusesANoOpAfterIndependentVerification(): void
    {
        $this->setUpRun('hardened');
        file_put_contents($this->workspace . '/src/Foo.php', "<?php\nfinal class Foo { public int \$x = 1; }\n");
        $steps = [
            ['--outcome', 'completed', '--context-id', 'ctx:build', '--auto-verify'],
            ['--outcome', 'pass', '--artifact', 'src/Foo.php', '--context-id', 'ctx:correctness'],
            ['--outcome', 'pass', '--artifact', 'src/Foo.php', '--context-id', 'ctx:architecture'],
            ['--outcome', 'completed', '--context-id', 'ctx:hardening', '--auto-verify'],
        ];
        foreach ($steps as $step) {
            [$exit, $payload] = $this->submit($step);
            self::assertSame(0, $exit, (string) json_encode($payload));
        }
        self::assertSame('independent-verification', $this->projection()->currentStageId);
        [$exit] = $this->submit(['--outcome', 'changes_required', '--artifact', 'src/Foo.php', '--context-id', 'ctx:independent']);
        self::assertSame(0, $exit);
        self::assertSame('hardening', $this->projection()->currentStageId);

        [$exit, $payload] = $this->submit(['--outcome', 'completed', '--context-id', 'ctx:hardening-noop']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('CANDIDATE_MISMATCH', (string) $payload->message);
        self::assertStringContainsString('independent-verification rejected', (string) $payload->message);
    }

    private function setUpRun(string $profile, string $validation = 'php -l src/Foo.php'): void
    {
        if (isset($this->root)) {
            $this->tearDown();
        }
        $suffix = bin2hex(random_bytes(5));
        $this->root = sys_get_temp_dir() . '/agent-loop-rework-' . $suffix;
        $this->workspace = sys_get_temp_dir() . '/agent-loop-rework-ws-' . $suffix;
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo {}\n");
        file_put_contents($this->root . '/.gitignore', "/.agent-loop/\n/vendor/\n");
        $this->git($this->root, 'init', '-q');
        $this->git($this->root, 'config', 'user.email', 'x@y.test');
        $this->git($this->root, 'config', 'user.name', 'X');
        $this->git($this->root, 'add', '.');
        $this->git($this->root, 'commit', '-qm', 'base');
        $base = $this->git($this->root, 'rev-parse', 'HEAD');
        $this->git($this->root, 'worktree', 'add', '-q', '--detach', $this->workspace, $base);

        ob_start();
        try {
            $exits = [
                (new WorkflowPlanCommand($this->root))->run([
                    'SUB-1', '--by', 'planner', '--file', 'src/Foo.php', '--goal', 'Change Foo.',
                    '--validation', $validation, '--base-commit', $base,
                ]),
                (new WorkflowApproveCommand($this->root))->run(['SUB-1', '--revision', '1', '--by', 'approver']),
                (new WorkflowExecutionProfileCommand($this->root))->run(['SUB-1', '--profile', $profile, '--by', 'approver']),
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

    private function reachReviewWithChange(): void
    {
        file_put_contents($this->workspace . '/src/Foo.php', "<?php\nfinal class Foo { public int \$x = 1; }\n");
        [$exit, $payload] = $this->submit(['--outcome', 'completed', '--context-id', 'ctx:build-1', '--auto-verify']);
        self::assertSame(0, $exit, (string) json_encode($payload));
        self::assertSame('review', $this->projection()->currentStageId);
    }

    private function requestChanges(string $contextId, string $summary = 'Foo needs a change.'): void
    {
        [$exit, $payload] = $this->submit(['--outcome', 'changes_required', '--artifact', 'src/Foo.php', '--summary', $summary, '--context-id', $contextId]);
        self::assertSame($this->changesRequiredCount() % ExecutionStateStore::MAX_REVIEW_ROUNDS === 0 ? 1 : 0, $exit, (string) json_encode($payload));
    }

    private function historyCount(): int
    {
        return count((new ExecutionStateStore($this->root))->find('SUB-1')->history ?? []);
    }

    private function changesRequiredCount(): int
    {
        $count = 0;
        foreach ((new ExecutionStateStore($this->root))->find('SUB-1')->history ?? [] as $accepted) {
            if ($accepted->result->outcome->value === 'changes_required') {
                ++$count;
            }
        }

        return $count;
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
            $exit = (new WorkflowPipelineCommand($this->root))->run([
                'submit', 'SUB-1', ...$arguments, '--workspace', $this->workspace, '--format=json',
            ]);
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

    private function projection(): ExecutionProjection
    {
        return (new ExecutionGateway($this->root))->projection('SUB-1');
    }

    private function git(string $directory, string ...$arguments): string
    {
        exec('git -C ' . escapeshellarg($directory) . ' ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));

        return trim(implode("\n", $output));
    }
}
