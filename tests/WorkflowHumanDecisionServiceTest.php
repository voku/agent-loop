<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowHumanDecisionService;

final class WorkflowHumanDecisionServiceTest extends TestCase
{
    public function testApprovesCandidateContractThroughOwnerStore(): void
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

            $approved = (new WorkflowHumanDecisionService($root))->approveContract('UI-1', 'lars');

            self::assertSame(TaskContract::APPROVED, $approved->status);
            self::assertSame('lars', $approved->approvedBy);
            self::assertSame(TaskContract::APPROVED, $contracts->load('UI-1')->status);
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
