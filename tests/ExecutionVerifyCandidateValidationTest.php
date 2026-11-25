<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Execution\AttentionKind;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Execution\ExecutionStateStore;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoop\Tests\Support\VerifyStageRunFixture;
use voku\AgentLoop\Workflow\WorkflowHumanDecisionService;

/**
 * The deterministic verify stage must validate the candidate the Run accepted, not the project checkout.
 * Regression for #799: a candidate that failed its declared validation used to complete the plan with a PASS.
 */
final class ExecutionVerifyCandidateValidationTest extends TestCase
{
    private VerifyStageRunFixture $run;

    protected function setUp(): void
    {
        $this->run = new VerifyStageRunFixture();
    }

    protected function tearDown(): void
    {
        $this->run->remove();
    }

    public function testCandidateThatFailsItsDeclaredValidationDoesNotPassVerify(): void
    {
        $this->run->reachVerifyWith("<?php\nfinal class Foo { this is not php }\n");

        $projection = (new ExecutionGateway($this->run->root))->runDeterministicStage('VER-1', 'verify', $this->run->workspace);

        self::assertFalse($projection->complete());
        self::assertNotNull($projection->attention);
        self::assertStringContainsString('[FAIL] php -l src/Foo.php', $projection->attention->message);
    }

    public function testRepeatedFailedFinalVerifyRemainsResolvableWithoutFailedTransition(): void
    {
        $this->run->reachVerifyWith("<?php\nfinal class Foo { this is not php }\n");
        $gateway = new ExecutionGateway($this->run->root);
        $decisions = new WorkflowHumanDecisionService($this->run->root);

        $firstFailure = $gateway->runDeterministicStage('VER-1', 'verify', $this->run->workspace);
        self::assertNotNull($firstFailure->attention);
        self::assertSame(AttentionKind::RUNNER_FAILED, $firstFailure->attention->kind);
        $firstResume = $decisions->resolveAttention('VER-1', $firstFailure->attention->id, 'reviewer', 'Retry final validation.');
        self::assertNull($firstResume->attention);
        self::assertSame('verify', $firstResume->currentStageId);

        // Final verify has PASS but no FAILED transition. A second failure
        // must open another Attention rather than crash during legacy recovery.
        $secondFailure = $gateway->runDeterministicStage('VER-1', 'verify', $this->run->workspace);
        self::assertNotNull($secondFailure->attention);
        self::assertSame(AttentionKind::RUNNER_FAILED, $secondFailure->attention->kind);
        self::assertNotSame($firstFailure->attention->id, $secondFailure->attention->id);

        $secondResume = $decisions->resolveAttention('VER-1', $secondFailure->attention->id, 'reviewer', 'Investigate the failing validation.');
        self::assertNull($secondResume->attention);
        self::assertSame('verify', $secondResume->currentStageId);
    }

    public function testCandidateThatPassesItsDeclaredValidationCompletesThePlan(): void
    {
        $this->run->reachVerifyWith("<?php\nfinal class Foo { public const int X = 1; }\n");

        $projection = (new ExecutionGateway($this->run->root))->runDeterministicStage('VER-1', 'verify', $this->run->workspace);

        self::assertTrue($projection->complete());
        self::assertNull($projection->attention);

        // The consistency check stays a receipt of its own kind; the Contract command executed on the candidate is the
        // only validation evidence verify claims.
        $state = (new ExecutionStateStore($this->run->root))->find('VER-1');
        self::assertNotNull($state);
        $verify = $state->history[count($state->history) - 1]->result;
        self::assertSame(StageOutcome::PASS, $verify->outcome);
        self::assertCount(1, $verify->consistencyReferences);
        self::assertCount(1, $verify->validationReferences);
    }

    public function testPersistedFinalVerifyRetainsItsExistingPostSequenceCandidateCheck(): void
    {
        $this->run->reachVerifyWith("<?php\nfinal class Foo { public const int X = 1; }\n", [
            'php -r "file_put_contents(\'temporary-validation-output.txt\', \'temporary\');"',
            'php -r "unlink(\'temporary-validation-output.txt\');"',
        ]);

        $projection = (new ExecutionGateway($this->run->root))->runDeterministicStage('VER-1', 'verify', $this->run->workspace);

        self::assertTrue($projection->complete(), 'An existing persisted verify plan checks the final candidate after all declared commands, as before.');
        self::assertFileDoesNotExist($this->run->workspace . '/temporary-validation-output.txt');
    }

    public function testPersistedFinalVerifyKeepsItsEarlierSuccessfulCommandInFailureSummary(): void
    {
        $this->run->reachVerifyWith("<?php\nfinal class Foo { public const int X = 1; }\n", [
            'php -l src/Foo.php',
            'php -r "fwrite(STDERR, \'the second check failed\'); exit(7);"',
        ]);

        $projection = (new ExecutionGateway($this->run->root))->runDeterministicStage('VER-1', 'verify', $this->run->workspace);

        self::assertNotNull($projection->attention);
        $message = $projection->attention->message;
        $successPosition = strpos($message, '[OK] php -l src/Foo.php');
        $failurePosition = strpos($message, '[FAIL] php -r');
        self::assertIsInt($successPosition);
        self::assertIsInt($failurePosition);
        self::assertLessThan($failurePosition, $successPosition);
        self::assertStringContainsString('(exit 7)', $message);
        self::assertStringContainsString('the second check failed', $message);
    }

    public function testUndeclaredDependencyIsMissingInTheFreshWorktreeAndStopsThePlan(): void
    {
        $this->run->reachVerifyWith("<?php\nfinal class Foo { public const int X = 1; }\n", ['vendor/bin/check']);

        $projection = (new ExecutionGateway($this->run->root))->runDeterministicStage('VER-1', 'verify', $this->run->workspace);

        self::assertFalse($projection->complete());
        self::assertNotNull($projection->attention);
        self::assertStringContainsString('[FAIL] vendor/bin/check (exit 127)', $projection->attention->message);
    }

    public function testDeclaredPreparationProvidesDependenciesInsideTheWorktreeOnly(): void
    {
        $this->run->reachVerifyWith("<?php\nfinal class Foo { public const int X = 1; }\n", ['php prepare.php', 'vendor/bin/check']);

        $projection = (new ExecutionGateway($this->run->root))->runDeterministicStage('VER-1', 'verify', $this->run->workspace);

        self::assertTrue($projection->complete());
        self::assertFileExists($this->run->workspace . '/vendor/bin/check', 'Preparation runs in the candidate worktree.');
        self::assertDirectoryDoesNotExist($this->run->root . '/vendor', 'The source checkout is never prepared by verify.');
        $state = (new ExecutionStateStore($this->run->root))->find('VER-1');
        self::assertNotNull($state);
        self::assertCount(2, $state->history[count($state->history) - 1]->result->validationReferences);
    }

    public function testWorkspaceThatDoesNotHoldTheGovernedCandidateIsRefused(): void
    {
        $this->run->reachVerifyWith("<?php\nfinal class Foo { public const int X = 1; }\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CANDIDATE_MISMATCH');
        // The project checkout still holds the base, not the candidate the build stage produced.
        (new ExecutionGateway($this->run->root))->runDeterministicStage('VER-1', 'verify');
    }

    public function testInheritedGitLocationEnvironmentCannotRedirectCandidateIdentity(): void
    {
        $this->run->reachVerifyWith("<?php\nfinal class Foo { public const int X = 1; }\n");
        $otherRepository = $this->run->root . '/other-repository';
        mkdir($otherRepository);
        $this->run->git($otherRepository, 'init', '-q');

        putenv('GIT_DIR=' . $otherRepository . '/.git');
        putenv('GIT_WORK_TREE=' . $otherRepository);
        putenv('GIT_INDEX_FILE=' . $otherRepository . '/index');
        try {
            $projection = (new ExecutionGateway($this->run->root))->runDeterministicStage('VER-1', 'verify', $this->run->workspace);
        } finally {
            putenv('GIT_DIR');
            putenv('GIT_WORK_TREE');
            putenv('GIT_INDEX_FILE');
        }

        self::assertTrue($projection->complete());
    }

    public function testFailedValidationSummaryKeepsAValidUtf8Tail(): void
    {
        $this->run->reachVerifyWith("<?php\nfinal class Foo { public const int X = 1; }\n", ['php -r "echo str_repeat(\'a\', 1998) . \'🙂\'; exit(1);"']);

        $projection = (new ExecutionGateway($this->run->root))->runDeterministicStage('VER-1', 'verify', $this->run->workspace);

        self::assertNotNull($projection->attention);
        self::assertStringContainsString('🙂', $projection->attention->message);
        self::assertNotFalse(json_encode($projection->attention->message, JSON_THROW_ON_ERROR));
    }

    public function testValidationThatChangesTheCandidateIsRefused(): void
    {
        $this->run->reachVerifyWith("<?php\nfinal class Foo { public const int X = 1; }\n", ['php -r "file_put_contents(\'side-effect.txt\', \'x\');"']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('changed the governed candidate');
        (new ExecutionGateway($this->run->root))->runDeterministicStage('VER-1', 'verify', $this->run->workspace);
    }
}
