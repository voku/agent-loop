<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Run\RunPolicyEvaluation;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowHumanDecisionProjector;

final class WorkflowHumanDecisionProjectorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-human-decision-projector-' . bin2hex(random_bytes(4));
        if (!mkdir($this->root, 0o775, true) && !is_dir($this->root)) {
            throw new RuntimeException('Unable to create test root.');
        }

        $store = new TaskContractStore($this->root);
        $store->create(
            'DECISION-3',
            'Keep human authority explicit.',
            ['src/Workflow'],
            [],
            ['composer ci'],
            'codex',
        );
        $store->approve('DECISION-3', 'lars');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testAcceptedRiskProjectsTheExactBlockingEvidence(): void
    {
        $blockers = [[
            'code' => 'verification.validation',
            'owner' => 'agent-loop',
            'message' => 'A required validation remains failed.',
        ]];

        $decision = (new WorkflowHumanDecisionProjector($this->root))->project(
            'DECISION-3',
            'agent-loop workflow close DECISION-3 --status done --accept-risk validation --accept-risk-by <actor>',
            RunPolicyEvaluation::KIND_DECISION_REQUIRED,
            $blockers,
        );

        self::assertNotNull($decision);
        self::assertSame('risk_acceptance', $decision['type']);
        self::assertSame(1, $decision['subject']['contract_revision'] ?? null);
        self::assertSame($blockers, $decision['subject']['blockers'] ?? null);
    }

    public function testSupersessionProjectsCurrentIntentInsteadOfAConfirmationLabel(): void
    {
        $blockers = [[
            'code' => 'execution_contract.blocked',
            'owner' => 'agent-loop',
            'message' => 'The approved scope cannot satisfy the execution contract.',
        ]];

        $decision = (new WorkflowHumanDecisionProjector($this->root))->project(
            'DECISION-3',
            'revise the approved Contract for DECISION-3; persist the complete revised intent with workflow plan --supersede; explicit approval remains required',
            RunPolicyEvaluation::KIND_DECISION_REQUIRED,
            $blockers,
        );

        self::assertNotNull($decision);
        self::assertSame('contract_supersession', $decision['type']);
        self::assertSame(1, $decision['subject']['current_contract_revision'] ?? null);
        self::assertSame(['src/Workflow'], $decision['subject']['scope'] ?? null);
        self::assertSame($blockers, $decision['subject']['blockers'] ?? null);
    }

    public function testNonHumanActionHasNoHumanDecisionProjection(): void
    {
        $decision = (new WorkflowHumanDecisionProjector($this->root))->project(
            'DECISION-3',
            'agent-loop workflow contract DECISION-3 --status ready --from <l1.md> --by <actor>',
            RunPolicyEvaluation::KIND_COMMAND_TEMPLATE,
        );

        self::assertNull($decision);
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
