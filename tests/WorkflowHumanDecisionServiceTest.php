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
            self::assertSame([WorkflowHumanDecisionProjection::APPROVE_CONTRACT], $available->actions);

            $approved = $service->approveContract('UI-1', 'lars', 1);

            self::assertSame(TaskContract::APPROVED, $approved->status);
            self::assertSame('lars', $approved->approvedBy);
            self::assertSame(TaskContract::APPROVED, $contracts->load('UI-1')->status);
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
                self::assertStringContainsString('standing: is reserved', $exception->getMessage());
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
                self::assertStringContainsString('standing: is reserved', $exception->getMessage());
            }

            self::assertSame(TaskContract::CANDIDATE, $contracts->load('STANDING-SERVICE')->status);
        } finally {
            $this->removeDirectory($root);
        }
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
