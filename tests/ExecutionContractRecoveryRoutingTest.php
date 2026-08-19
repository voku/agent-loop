<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Run\RunPolicyEvaluator;
use voku\AgentLoop\Workflow\ExecutionContractStore;
use voku\AgentLoop\Workflow\TaskContractStore;

final class ExecutionContractRecoveryRoutingTest extends TestCase
{
    public function testInvalidExecutionContractRoutesToReconstructionAndConverges(): void
    {
        $root = $this->root('invalid');

        try {
            $this->fixture($root, 'F2-INVALID');
            $store = new ExecutionContractStore($root);
            $store->writeReady('F2-INVALID', 'lars', $this->contract());
            file_put_contents($root . '/.agent-loop/recall/F2-INVALID/execution-contract.json', '{');

            $reference = $store->inspect('F2-INVALID');
            self::assertSame('invalid', $reference['state']);

            $policy = $this->policy('F2-INVALID', $reference);
            self::assertSame('blocked', $policy->state);
            self::assertSame('decision_required', $policy->nextActionKind);
            self::assertSame(
                'agent-loop workflow contract F2-INVALID --status ready --from <l1.md> --by <actor>',
                $policy->nextAction,
            );
            self::assertStringNotContainsString('workflow status', $policy->nextAction);

            $store->writeReady('F2-INVALID', 'lars', $this->contract());
            self::assertSame('ready', $store->inspect('F2-INVALID')['state']);
            self::assertNotSame([$policy->nextAction, $policy->nextActionKind], $this->nextStep('F2-INVALID', $store));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testStaleExecutionContractRoutesToReconstructionAndConverges(): void
    {
        $root = $this->root('stale');

        try {
            $this->fixture($root, 'F2-STALE');
            $store = new ExecutionContractStore($root);
            $store->writeReady('F2-STALE', 'lars', $this->contract());

            $factsPath = $root . '/.agent-loop/recall/F2-STALE/facts.json';
            $facts = json_decode((string) file_get_contents($factsPath), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($facts);
            $facts['bundle_sha256'] = str_repeat('b', 64);
            file_put_contents($factsPath, json_encode($facts, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            $reference = $store->inspect('F2-STALE');
            self::assertSame('stale', $reference['state']);

            $policy = $this->policy('F2-STALE', $reference);
            self::assertSame('blocked', $policy->state);
            self::assertSame('decision_required', $policy->nextActionKind);
            self::assertSame(
                'agent-loop workflow contract F2-STALE --status ready --from <l1.md> --by <actor>',
                $policy->nextAction,
            );

            $store->writeReady('F2-STALE', 'lars', $this->contract());
            self::assertSame('ready', $store->inspect('F2-STALE')['state']);
            self::assertNotSame([$policy->nextAction, $policy->nextActionKind], $this->nextStep('F2-STALE', $store));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testBlockedExecutionContractIsNotPretendedToHaveTheSameRepair(): void
    {
        $policy = $this->policy('F2-BLOCKED', [
            'owner' => 'agent-loop',
            'state' => 'blocked',
            'reason' => 'Current approved policy cannot be satisfied.',
        ]);

        self::assertSame('blocked', $policy->state);
        self::assertSame('command', $policy->nextActionKind);
        self::assertSame('agent-loop workflow status F2-BLOCKED --format=json', $policy->nextAction);
    }

    /** @param array<string, mixed> $executionContract */
    private function policy(string $taskId, array $executionContract): \voku\AgentLoop\Run\RunPolicyEvaluation
    {
        return (new RunPolicyEvaluator())->evaluate(
            $taskId,
            'governed',
            [
                'session' => ['owner' => 'agent-session', 'state' => 'active'],
                'contract' => ['owner' => 'agent-loop', 'state' => 'approved'],
                'approval' => ['owner' => 'agent-loop', 'state' => 'current'],
                'recall' => ['owner' => 'agent-recall-compiler', 'state' => 'compiled'],
                'execution_contract' => $executionContract,
                'verification' => ['owner' => 'agent-loop', 'state' => 'pending_close'],
                'review' => ['owner' => 'agent-recall-compiler', 'state' => 'missing'],
                'learning' => ['owner' => 'agent-learning', 'state' => 'missing'],
            ],
            [],
        );
    }

    /** @return array{0: string, 1: string} */
    private function nextStep(string $taskId, ExecutionContractStore $store): array
    {
        $policy = $this->policy($taskId, $store->inspect($taskId));

        return [$policy->nextAction, $policy->nextActionKind];
    }

    private function fixture(string $root, string $taskId): void
    {
        $contracts = new TaskContractStore($root);
        $contracts->create(
            $taskId,
            'Harden the parser.',
            ['src/Parser.php'],
            [],
            ['composer ci'],
            'lars',
            tags: [],
            behaviorAnchors: [],
            operatingPromptManifest: 'skills/operational-prompting/operating-prompts.json',
            operatingPrompts: [[
                'id' => 'coverage-mutation',
                'arguments' => ['minimum_percentage_points' => 10, 'mutation_command' => 'vendor/bin/infection'],
            ]],
        );
        $contracts->approve($taskId, 'lars');

        $recall = $root . '/.agent-loop/recall/' . $taskId;
        mkdir($recall, 0o777, true);
        file_put_contents($recall . '/facts.json', json_encode([
            'schema_version' => '1.0',
            'bundle_sha256' => str_repeat('a', 64),
            'facts' => [[
                'id' => 'operating-prompt.coverage-mutation',
                'type' => 'operating_prompt',
                'authority' => 'approved_contract',
                'source_ref' => 'skills/operational-prompting/operating-prompts.json#coverage-mutation',
                'scope' => ['src/Parser.php'],
                'payload' => [
                    'prompt_id' => 'coverage-mutation',
                    'level' => 2,
                    'arguments' => [
                        'minimum_percentage_points' => 10,
                        'mutation_command' => 'vendor/bin/infection',
                    ],
                    'content' => 'Create a project-specific test-hardening prompt.',
                    'template_sha256' => str_repeat('c', 64),
                ],
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function contract(): string
    {
        return <<<'MD'
## Goal
Increase parser verification by the approved measurable floor.

## Context
Use src/Parser.php and the current Recall evidence.

## Constraints
Keep the public API unchanged and do not weaken assertions.

## Verification
Run `composer ci` and the repository-supported mutation command.

## Done When
All required checks pass and the approved coverage/mutation gates are satisfied.
MD;
    }

    private function root(string $suffix): string
    {
        return sys_get_temp_dir() . '/agent-loop-execution-contract-recovery-' . $suffix . '-' . bin2hex(random_bytes(6));
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }
}
