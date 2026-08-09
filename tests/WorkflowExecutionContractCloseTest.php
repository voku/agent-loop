<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\WorkflowCli;
use voku\AgentSession\SessionStore;

final class WorkflowExecutionContractCloseTest extends TestCase
{
    public function testSuccessfulCloseCannotBypassMissingL2ContractWithAcceptedRisk(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-contract-close-' . bin2hex(random_bytes(6));

        try {
            $sessionsRoot = $root . '/session_plan';
            $session = (new SessionStore())->create($sessionsRoot, 'CLOSE-L2', by: 'lars');
            file_put_contents($session->path . '/work-brief.json', json_encode([
                'schema_version' => '1.0',
                'task_id' => 'CLOSE-L2',
                'goal' => 'Finish only through the approved L2 contract.',
                'scope' => ['src/Parser.php'],
                'non_goals' => [],
                'validation' => ['composer ci'],
                'tags' => [],
                'behavior_anchors' => [],
                'operating_prompt_manifest' => 'skills/operational-prompting/operating-prompts.json',
                'operating_prompts' => [[
                    'id' => 'coverage-mutation',
                    'arguments' => [
                        'minimum_percentage_points' => 10,
                        'mutation_command' => 'vendor/bin/infection',
                    ],
                ]],
                'status' => 'approved',
                'revision' => 1,
                'created_at' => '2026-08-09T00:00:00+00:00',
                'updated_at' => '2026-08-09T00:00:00+00:00',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            file_put_contents($session->path . '/approval.json', json_encode([
                'schema_version' => '1.0',
                'task_id' => 'CLOSE-L2',
                'work_brief_revision' => 1,
                'approved_by' => 'lars',
                'approved_at' => '2026-08-09T00:00:01+00:00',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            $recall = $root . '/recall/CLOSE-L2';
            mkdir($recall, 0777, true);
            file_put_contents($recall . '/facts.json', json_encode([
                'schema_version' => '1.0',
                'bundle_sha256' => str_repeat('a', 64),
                'facts' => [[
                    'id' => 'operating-prompt.coverage-mutation',
                    'type' => 'operating_prompt',
                    'authority' => 'approved_session_brief',
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

            $exit = (new WorkflowCli($root, static fn (array $args): int => 0))->run([
                'close',
                'CLOSE-L2',
                '--status',
                'done',
                '--accept-risk',
                'Ignore every other missing close gate.',
                '--accept-risk-by',
                'lars',
            ]);

            self::assertSame(1, $exit);
            self::assertFalse((new SessionStore())->load($sessionsRoot, $session->id)->status->isClosed());
        } finally {
            $this->removeDirectory($root);
        }
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
