<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowHumanDecisionProjection;
use voku\AgentLoop\Workflow\WorkflowHumanDecisionService;

final class WorkflowHumanDecisionServiceTest extends TestCase
{
    public function testProjectsAndRecordsCandidateContractApprovalThroughOwnerStore(): void
    {
        $root = $this->root('approve');

        try {
            $contracts = new TaskContractStore($root);
            $contracts->create(
                'UI-1',
                'Expose the human decision boundary.',
                ['src/Foo.php'],
                [],
                ['vendor/bin/phpunit'],
                'planner',
            );
            $service = new WorkflowHumanDecisionService($root);

            $available = $service->availableActions('UI-1');
            self::assertSame([WorkflowHumanDecisionProjection::APPROVE_CONTRACT, WorkflowHumanDecisionProjection::REJECT_CONTRACT], $available->actions);

            $approved = $service->approveContract('UI-1', 'lars', 1);

            self::assertSame(TaskContract::APPROVED, $approved->status);
            self::assertSame('lars', $approved->approvedBy);
            self::assertSame(TaskContract::APPROVED, $contracts->load('UI-1')->status);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testRejectionPreservesReasonAndRequiresNewRevision(): void
    {
        $root = $this->root('reject');
        try {
            $contracts = new TaskContractStore($root);
            $contracts->create('UI-9', 'Original goal', ['src/Foo.php'], [], ['php -l src/Foo.php'], 'planner');
            $service = new WorkflowHumanDecisionService($root);
            foreach (['approve', 'reject'] as $action) {
                try {
                    if ($action === 'approve') {
                        $service->approveContract('UI-9', 'lars', 1, str_repeat('a', 40));
                    } else {
                        $service->rejectContract('UI-9', 'lars', 1, 'Wrong scope', str_repeat('a', 40));
                    }
                    self::fail('A decision based on an unavailable HEAD must fail closed.');
                } catch (RuntimeException $exception) {
                    self::assertStringContainsString('HEAD changed', $exception->getMessage());
                }
            }
            try {
                $service->rejectContract('UI-9', 'lars', 2, 'Wrong scope');
                self::fail('A stale revision must not be rejected.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('revision changed', $exception->getMessage());
            }
            $rejected = $service->rejectContract('UI-9', 'lars', 1, 'Wrong scope');
            self::assertSame(TaskContract::REJECTED, $rejected->status);
            self::assertSame('lars', $contracts->load('UI-9')->rejectedBy);
            self::assertSame('Wrong scope', $contracts->load('UI-9')->rejectionReason);
            self::assertSame([], $service->availableActions('UI-9')->actions);
            foreach (['approve', 'reject'] as $action) {
                try {
                    if ($action === 'approve') {
                        $service->approveContract('UI-9', 'lars', 1);
                    } else {
                        $service->rejectContract('UI-9', 'lars', 1, 'Another reason');
                    }
                    self::fail('A rejected Contract cannot be approved or rejected again.');
                } catch (RuntimeException $exception) {
                    self::assertStringContainsString('not a currently available human action', $exception->getMessage());
                }
            }
            $revision = $contracts->revise('UI-9', 'Revised goal', ['src/Foo.php'], [], ['php -l src/Foo.php'], 'planner');
            self::assertSame(2, $revision->revision);
            self::assertSame('Wrong scope', $contracts->supersededRevisions('UI-9')[0]->rejectionReason);
            self::assertSame(TaskContract::APPROVED, $service->approveContract('UI-9', 'lars', 2)->status);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testCliRejectionRoutesToRevision(): void
    {
        $root = $this->root('reject-cli');
        try {
            (new TaskContractStore($root))->create('UI-10', 'Goal', ['src/Foo.php'], [], ['php -l src/Foo.php'], 'planner');
            [$exit, , $error] = $this->invokeCli($root, ['workflow', 'reject', 'UI-10', '--revision', '1', '--by', 'lars', '--reason', 'Scope needs revision']);
            self::assertSame(0, $exit, is_string($error) ? $error : '');
            [$status, $output] = $this->invokeCli($root, ['workflow', 'status', 'UI-10', '--format=json']);
            self::assertSame(0, $status);
            self::assertIsString($output);
            $projection = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('rejected', $projection['manifest']['references']['contract']['state']);
            self::assertSame('Scope needs revision', $projection['manifest']['references']['contract']['rejection_reason']);
            self::assertStringContainsString('workflow plan UI-10 --supersede', $projection['policy']['next_action']);
            [$replay] = $this->invokeCli($root, ['workflow', 'reject', 'UI-10', '--revision', '1', '--by', 'lars', '--reason', 'Scope needs revision']);
            self::assertSame(1, $replay);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testConcurrentApprovalAndRejectionCannotBothSucceed(): void
    {
        $root = $this->root('decision-race');
        try {
            $contracts = new TaskContractStore($root);
            $contracts->create('UI-11', 'Goal', ['src/Foo.php'], [], ['php -l src/Foo.php'], 'planner');
            $binary = dirname(__DIR__) . '/bin/agent-loop';
            $commands = [
                [PHP_BINARY, $binary, 'workflow', 'approve', 'UI-11', '--revision', '1', '--by', 'alice'],
                [PHP_BINARY, $binary, 'workflow', 'reject', 'UI-11', '--revision', '1', '--by', 'bob', '--reason', 'Change scope'],
            ];
            $processes = [];
            foreach ($commands as $command) {
                $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
                self::assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = [$process, $pipes];
            }
            $exits = [];
            foreach ($processes as [$process, $pipes]) {
                stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exits[] = proc_close($process);
            }
            sort($exits);
            self::assertSame([0, 1], $exits);
            self::assertContains($contracts->load('UI-11')->status, [TaskContract::APPROVED, TaskContract::REJECTED]);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testChangedHeadInvalidatesReviewedContractDecision(): void
    {
        $root = $this->root('decision-head');
        try {
            $git = 'git -C ' . escapeshellarg($root);
            exec("$git init -q && $git config user.email test@example.invalid && $git config user.name Tester && $git commit --allow-empty -qm first", $output, $exit);
            self::assertSame(0, $exit);
            $head = trim((string) shell_exec("$git rev-parse HEAD"));
            $contracts = new TaskContractStore($root);
            $contracts->create('UI-12', 'Goal', ['src/Foo.php'], [], ['php -l src/Foo.php'], 'planner');
            exec("$git commit --allow-empty -qm second", $output, $exit);
            self::assertSame(0, $exit);
            [$approveExit] = $this->invokeCli($root, ['workflow', 'approve', 'UI-12', '--revision', '1', '--by', 'lars', '--head', $head]);
            [$rejectExit] = $this->invokeCli($root, ['workflow', 'reject', 'UI-12', '--revision', '1', '--by', 'lars', '--reason', 'Change scope', '--head', $head]);
            self::assertSame(1, $approveExit);
            self::assertSame(1, $rejectExit);
            $service = new WorkflowHumanDecisionService($root);
            foreach (['approve', 'reject'] as $action) {
                try {
                    if ($action === 'approve') {
                        $service->approveContract('UI-12', 'lars', 1, $head);
                    } else {
                        $service->rejectContract('UI-12', 'lars', 1, 'Change scope', $head);
                    }
                    self::fail('A stale HEAD must prevent either Contract decision.');
                } catch (RuntimeException $exception) {
                    self::assertStringContainsString('HEAD changed', $exception->getMessage());
                }
            }
            self::assertSame(TaskContract::CANDIDATE, $contracts->load('UI-12')->status);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testApproveContractWithMatchingExpectedRevisionSucceeds(): void
    {
        $root = $this->root('approve-revision-match');

        try {
            $contracts = new TaskContractStore($root);
            $contracts->create(
                'UI-2',
                'Expose the human decision boundary.',
                ['src/Foo.php'],
                [],
                ['vendor/bin/phpunit'],
                'planner',
            );
            $service = new WorkflowHumanDecisionService($root);

            $approved = $service->approveContract('UI-2', 'lars', 1);

            self::assertSame(TaskContract::APPROVED, $approved->status);
            self::assertSame(1, $approved->revision);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testApproveContractRefusesStaleExpectedRevision(): void
    {
        $root = $this->root('approve-revision-stale');

        try {
            $contracts = new TaskContractStore($root);
            $contracts->create(
                'UI-3',
                'Expose the human decision boundary.',
                ['src/Foo.php'],
                [],
                ['vendor/bin/phpunit'],
                'planner',
            );
            $contracts->revise(
                'UI-3',
                'Expose the human decision boundary (revised).',
                ['src/Foo.php'],
                [],
                ['vendor/bin/phpunit'],
                'planner',
            );
            $service = new WorkflowHumanDecisionService($root);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Contract revision changed since it was reviewed');

            // The caller reviewed revision 1, but the Contract moved on to revision 2.
            $service->approveContract('UI-3', 'lars', 1);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testProjectsNoHumanActionForMissingContract(): void
    {
        $root = $this->root('missing');

        try {
            self::assertSame([], (new WorkflowHumanDecisionService($root))->availableActions('UI-1')->actions);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testRejectsInvalidReviewIdentityBeforeLookingForRunState(): void
    {
        $root = $this->root('review-digest');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('sha256:<64 lowercase hex>');

            (new WorkflowHumanDecisionService($root))->acknowledgeReview('UI-1', 'not-a-digest', 'lars');
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testAttentionResolutionRefusesReservedStandingActorBeforeLookingForRunState(): void
    {
        $root = $this->root('attention-standing-actor');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('standing:');

            (new WorkflowHumanDecisionService($root))->resolveAttention('UI-1', 'attention-1', '  standing:forged ');
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testApprovalOwnerRefusesReservedStandingActorWithoutPersistingApproval(): void
    {
        $root = $this->root('standing-actor');

        try {
            $contracts = new TaskContractStore($root);
            $contracts->create(
                'STANDING-OWNER',
                'An actor string cannot impersonate a standing grant.',
                ['src/Foo.php'],
                [],
                ['vendor/bin/phpunit'],
                'planner',
            );

            try {
                $contracts->approve('STANDING-OWNER', '  standing:forged ', 1);
                self::fail('The owner must refuse the reserved standing actor.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('standing:', $exception->getMessage());
            }

            self::assertSame(TaskContract::CANDIDATE, $contracts->load('STANDING-OWNER')->status);
            self::assertNull($contracts->load('STANDING-OWNER')->approvedBy);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testHumanDecisionServiceCannotBypassTheApprovalOwner(): void
    {
        $root = $this->root('standing-service');

        try {
            $contracts = new TaskContractStore($root);
            $contracts->create(
                'STANDING-SERVICE',
                'Human adapters must not reinterpret reserved actor identity.',
                ['src/Foo.php'],
                [],
                ['vendor/bin/phpunit'],
                'planner',
            );

            try {
                (new WorkflowHumanDecisionService($root))->approveContract('STANDING-SERVICE', 'standing:forged', 1);
                self::fail('The human decision adapter must not bypass the owner.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('standing:', $exception->getMessage());
            }

            self::assertSame(TaskContract::CANDIDATE, $contracts->load('STANDING-SERVICE')->status);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testHistoricalReservedStandingApprovalFailsClosedAndCanBeReplacedByRevision(): void
    {
        $root = $this->root('legacy-standing-contract');

        try {
            $contracts = new TaskContractStore($root);
            $candidate = $contracts->create(
                'STANDING-LEGACY-CONTRACT',
                'A persisted pseudo-actor must not remain approved after the reservation is enforced.',
                ['src/Foo.php'],
                [],
                ['vendor/bin/phpunit'],
                'planner',
            );
            $artifact = json_decode((string) file_get_contents($candidate->path), true, 512, JSON_THROW_ON_ERROR);
            $artifact['status'] = TaskContract::APPROVED;
            $artifact['approved_by'] = 'standing:legacy-spoof';
            $artifact['approved_at'] = '2026-10-31T12:00:00+00:00';
            self::assertNotFalse(file_put_contents(
                $candidate->path,
                json_encode($artifact, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n",
            ));

            $legacy = $contracts->load($candidate->taskId);
            self::assertSame(TaskContract::SUPERSEDED, $legacy->status);
            self::assertSame('standing:legacy-spoof', $legacy->approvedBy);

            $revised = $contracts->revise(
                $candidate->taskId,
                'Re-review the Contract after invalidating its pseudo-actor approval.',
                $candidate->scope,
                $candidate->nonGoals,
                $candidate->validation,
                'planner',
            );
            self::assertSame(TaskContract::CANDIDATE, $revised->status);
            self::assertSame(2, $revised->revision);

            $history = $contracts->supersededRevisions($candidate->taskId);
            self::assertCount(1, $history);
            self::assertSame(TaskContract::SUPERSEDED, $history[0]->status);
            self::assertSame('standing:legacy-spoof', $history[0]->approvedBy);

            $approved = $contracts->approve($candidate->taskId, 'lars', $revised->revision);
            self::assertSame(TaskContract::APPROVED, $approved->status);
            self::assertSame('lars', $approved->approvedBy);
        } finally {
            $this->removeDirectory($root);
        }
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string|false, string|false}
     */
    private function invokeCli(string $root, array $arguments): array
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/agent-loop', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    private function root(string $suffix): string
    {
        $root = sys_get_temp_dir() . '/agent-loop-human-decision-' . $suffix . '-' . bin2hex(random_bytes(6));
        if (!mkdir($root, 0o775, true) && !is_dir($root)) {
            throw new RuntimeException('Unable to create test root.');
        }

        return $root;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if (!is_array($items)) {
            throw new RuntimeException('Unable to scan test directory.');
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . '/' . $item;
            if (is_dir($child)) {
                $this->removeDirectory($child);
                continue;
            }
            if (!unlink($child)) {
                throw new RuntimeException('Unable to remove test file.');
            }
        }
        if (!rmdir($path)) {
            throw new RuntimeException('Unable to remove test directory.');
        }
    }
}
