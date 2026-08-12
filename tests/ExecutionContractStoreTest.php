<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Workflow\ExecutionContractStatus;
use voku\AgentLoop\Workflow\ExecutionContractStore;
use voku\AgentLoop\Workflow\TaskContractStore;

final class ExecutionContractStoreTest extends TestCase
{
    public function testL2TaskIsMissingUntilFiveSectionContractIsBound(): void
    {
        $root = $this->root('missing');

        try {
            $this->fixture($root, 'TASK-1', level: 2);
            $store = new ExecutionContractStore($root);

            self::assertSame('missing', $store->inspect('TASK-1')['state']);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('execution contract is missing');
            $store->assertReadyForMutation('TASK-1');
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testReadyContractIsBoundToCurrentContractRecallAndPromptSemantics(): void
    {
        $root = $this->root('ready');

        try {
            $this->fixture($root, 'TASK-2', level: 2);
            $store = new ExecutionContractStore($root);
            $path = $store->writeReady('TASK-2', 'lars', $this->contract());

            self::assertSame('.agent-loop/recall/TASK-2/execution-contract.json', $path);
            self::assertSame('ready', $store->inspect('TASK-2')['state']);
            $store->assertReadyForMutation('TASK-2');

            $metadata = json_decode(
                (string) file_get_contents($root . '/.agent-loop/recall/TASK-2/execution-contract.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            self::assertSame(1, $metadata['contract_revision']);
            self::assertArrayNotHasKey('work_brief_revision', $metadata);
            self::assertSame(str_repeat('a', 64), $metadata['recall_bundle_sha256']);
            self::assertSame(['coverage-mutation'], $metadata['prompt_ids']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $metadata['prompt_policy_sha256']);
            self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', $metadata['content_sha256']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testChangedRecallMakesExistingContractStale(): void
    {
        $root = $this->root('stale');

        try {
            $this->fixture($root, 'TASK-3', level: 2);
            $store = new ExecutionContractStore($root);
            $store->writeReady('TASK-3', 'lars', $this->contract());

            $factsPath = $root . '/.agent-loop/recall/TASK-3/facts.json';
            $facts = json_decode((string) file_get_contents($factsPath), true, 512, JSON_THROW_ON_ERROR);
            $facts['bundle_sha256'] = str_repeat('b', 64);
            file_put_contents($factsPath, json_encode($facts, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            self::assertSame('stale', $store->inspect('TASK-3')['state']);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('execution contract is stale');
            $store->assertReadyForMutation('TASK-3');
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testBlockedAndRejectedAreExplicitEvidenceBackedStates(): void
    {
        $root = $this->root('blocked');

        try {
            $this->fixture($root, 'TASK-4', level: 2);
            $store = new ExecutionContractStore($root);
            $store->writeStopped(
                'TASK-4',
                ExecutionContractStatus::BLOCKED,
                'lars',
                'Required mutation command is unavailable.',
                ['composer.json has no mutation script', 'infection config is absent'],
                'Verification',
                'Revise the durable Contract to remove or replace the mutation requirement.',
            );

            $blocked = $store->inspect('TASK-4');
            self::assertSame('blocked', $blocked['state']);
            self::assertSame('Verification', $blocked['affected_constraint']);
            self::assertSame(
                ['composer.json has no mutation script', 'infection config is absent'],
                $blocked['evidence'],
            );

            $this->expectException(RuntimeException::class);
            $store->assertReadyForMutation('TASK-4');
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testL1OnlyPromptDoesNotRequireGeneratedExecutionContract(): void
    {
        $root = $this->root('l1');

        try {
            $this->fixture($root, 'TASK-5', level: 1);
            $store = new ExecutionContractStore($root);

            self::assertSame('not_required', $store->inspect('TASK-5')['state']);
            $store->assertReadyForMutation('TASK-5');
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testReadyContractRejectsWrongSectionOrder(): void
    {
        $root = $this->root('shape');

        try {
            $this->fixture($root, 'TASK-6', level: 2);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('exactly these ordered H2 sections');
            (new ExecutionContractStore($root))->writeReady(
                'TASK-6',
                'lars',
                "## Goal\nDo it.\n\n## Verification\nRun tests.\n\n## Context\nUse repo evidence.\n\n## Constraints\nKeep scope.\n\n## Done When\nTests pass.\n",
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function fixture(string $root, string $taskId, int $level): void
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
        mkdir($recall, 0777, true);
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
                    'level' => $level,
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
Use src/Parser.php and the existing parser tests from recall evidence.

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
        return sys_get_temp_dir() . '/agent-loop-execution-contract-' . $suffix . '-' . bin2hex(random_bytes(6));
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
