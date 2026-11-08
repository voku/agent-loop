<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use JsonException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Run\RunPolicyEvaluation;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowContractApprovalPresentation;
use voku\AgentLoop\Workflow\WorkflowHumanDecisionProjector;

final class WorkflowApprovalBaselineClassificationTest extends TestCase
{
    public function testNamedArchivedApprovalIsStillARealApprovalBaseline(): void
    {
        $previous = $this->contract('maintainer', '2026-11-08T09:00:00Z');
        self::assertSame(TaskContract::SUPERSEDED, $previous->status);
        self::assertFalse($previous->hasNonReservedApproval());
        self::assertTrue($previous->hasNamedApprovalEvidence());

        $delta = (new WorkflowContractApprovalPresentation($this->contract(null, null), $previous, ''))->revisionDelta();
        self::assertNotNull($delta);
        self::assertTrue($delta['previous_was_approved']);
        self::assertSame('maintainer', $delta['previous_approved_by']);
    }

    public function testStandingActorAndIncompleteEvidenceAreNeverHumanApproval(): void
    {
        foreach ([['standing:runner', '2026-11-08T09:00:00Z'], ['maintainer', null], [null, '2026-11-08T09:00:00Z']] as [$actor, $at]) {
            $previous = $this->contract($actor, $at);
            $delta = (new WorkflowContractApprovalPresentation($this->contract(null, null), $previous, ''))->revisionDelta();

            self::assertNotNull($delta);
            self::assertFalse($previous->hasNamedApprovalEvidence());
            self::assertFalse($delta['previous_was_approved']);
            self::assertSame($actor, $delta['previous_approved_by'], 'Preserve raw attribution for audit.');
            self::assertSame($at, $delta['previous_approved_at']);
        }
    }

    /**
     * A legacy archive may contain a reserved pseudo-actor despite the current
     * writer rejecting it. The owner must not promote that into human approval.
     *
     * @throws JsonException
     */
    public function testProjectorDoesNotTreatLegacyStandingActorAsApproved(): void
    {
        $root = sys_get_temp_dir() . '/loop-approval-evidence-' . bin2hex(random_bytes(6));
        if (!mkdir($root, 0o775, true) && !is_dir($root)) {
            throw new RuntimeException('Unable to create fixture directory.');
        }

        try {
            $store = new TaskContractStore($root);
            $store->create('APPROVAL-1', 'Original goal', ['src/A.php'], [], ['composer ci'], 'planner');
            $store->approve('APPROVAL-1', 'maintainer', 1);
            $store->revise('APPROVAL-1', 'Second goal', ['src/B.php'], [], ['composer ci'], 'planner');

            $history = $store->supersededRevisions('APPROVAL-1');
            self::assertCount(1, $history);
            $path = $history[0]->path;
            $raw = file_get_contents($path);
            self::assertIsString($raw);
            /** @var array<string, mixed> $document */
            $document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $document['approved_by'] = 'standing:legacy-runner';
            self::assertNotFalse(file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR)));

            $presentation = (new WorkflowHumanDecisionProjector($root))->projectContractApprovalPresentation(
                'APPROVAL-1',
                'agent-loop workflow approve APPROVAL-1 --revision 2 --by <named-actor>',
                RunPolicyEvaluation::KIND_DECISION_REQUIRED,
            );
            self::assertNotNull($presentation);
            $delta = $presentation->revisionDelta();
            self::assertNotNull($delta);
            self::assertSame('standing:legacy-runner', $delta['previous_approved_by']);
            self::assertFalse($delta['previous_was_approved']);
        } finally {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($root);
        }
    }

    private function contract(?string $actor, ?string $at): TaskContract
    {
        return new TaskContract(
            taskId: 'APPROVAL-1',
            goal: 'Original goal',
            scope: ['src/A.php'],
            nonGoals: [],
            validation: ['composer ci'],
            status: TaskContract::SUPERSEDED,
            revision: 1,
            createdAt: '2026-11-08T08:00:00Z',
            updatedAt: '2026-11-08T09:00:00Z',
            path: '/tmp/contract.json',
            plannedBy: 'planner',
            approvedBy: $actor,
            approvedAt: $at,
        );
    }
}
