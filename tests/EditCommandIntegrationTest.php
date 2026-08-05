<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Edit\EditCommand;

final class EditCommandIntegrationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-edit-integration-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/tests', 0o775, true);
        file_put_contents($this->root . '/src/UserService.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Demo;

        final class UserService
        {
            public function save(bool $active): void
            {
                if (!$active) {
                    return;
                }
            }

            public function keep(bool $active): void
            {
                if (!$active) {
                    return;
                }
            }
        }
        PHP);
        file_put_contents($this->root . '/tests/UserServiceTest.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Demo\Tests;

        use Demo\UserService;

        final class UserServiceTest
        {
            public function testSave(): void
            {
                (new UserService())->save(true);
            }
        }
        PHP);
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testPreparesTargetAwareExecutionBundle(): void
    {
        $command = new EditCommand($this->root);

        ob_start();
        $exit = $command->run([
            'Demo\\UserService::save',
            '--task=EDIT-1',
            '--map-paths=src,tests',
            '--focus=$legacyUser->regionId',
            '--phpstan-memory-limit=512M',
            '--dry-run',
            '--',
            'Reject inactive users before persistence.',
        ]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('Edit execution bundle prepared:', $output);
        self::assertFileExists($this->root . '/.agent-map/php-symbols.json');
        self::assertFileExists($this->root . '/.agent-loop/edit/EDIT-1/prompt.md');
        self::assertFileExists($this->root . '/.agent-loop/edit/EDIT-1/execution.json');

        $prompt = file_get_contents($this->root . '/.agent-loop/edit/EDIT-1/prompt.md');
        self::assertIsString($prompt);
        self::assertStringContainsString('### Target: `Demo\\UserService::save`', $prompt);
        self::assertStringContainsString('public function save(bool $active): void', $prompt);
        self::assertStringContainsString('Reject inactive users before persistence.', $prompt);

        $executionJson = file_get_contents($this->root . '/.agent-loop/edit/EDIT-1/execution.json');
        self::assertIsString($executionJson);
        $execution = json_decode($executionJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($execution);
        self::assertSame('prepared', $execution['status']);
        self::assertSame('Demo\\UserService::save', $execution['target']);
        $requestJson = file_get_contents($this->root . '/.agent-loop/edit/EDIT-1/request.json');
        self::assertIsString($requestJson);
        $request = json_decode($requestJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($request);
        self::assertSame('512M', $request['phpstan_memory_limit']);
        self::assertSame(['$legacyUser->regionId'], $request['focus_terms']);
        foreach (['map_digest', 'map_index_sha256', 'recall_bundle_sha256', 'prompt_sha256'] as $digestField) {
            self::assertIsString($execution[$digestField]);
            self::assertMatchesRegularExpression('/\Asha256:[a-f0-9]{64}\z/', $execution[$digestField]);
        }

        // The answer sheet is part of the bundle, seeded from the plan the compiler just emitted.
        $resultJson = file_get_contents($this->root . '/.agent-loop/edit/EDIT-1/agent-result.json');
        self::assertIsString($resultJson);
        $result = json_decode($resultJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertSame('1.0', $result['schema_version']);
        self::assertSame('EDIT-1', $result['task_id']);
        self::assertSame('method:Demo\\UserService::save', $result['target']);
        self::assertSame('Demo\\UserService::save', $result['requested_target']);
        self::assertSame([], $result['commands'], 'a dry run executes nothing');
        self::assertIsArray($result['probe_answers']);
        self::assertIsArray($result['checklist_evidence']);
        self::assertStringEndsWith('/agent-result.json', (string) $execution['artifacts']['agent_result']);

        $recallDirectory = (string) $execution['artifacts']['recall'];
        if (is_file($recallDirectory . '/verification-plan.json')) {
            $planRaw = (string) file_get_contents($recallDirectory . '/verification-plan.json');
            self::assertSame('sha256:' . hash('sha256', $planRaw), $result['verification_plan_sha256']);

            $plan = json_decode($planRaw, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($plan);
            $probeIds = array_map(static fn (array $probe): string => (string) $probe['id'], $plan['knowledge_probes']);
            self::assertSame($probeIds, array_keys($result['probe_answers']), 'every probe gets a slot to answer in');
            foreach ($result['probe_answers'] as $answers) {
                self::assertSame([], $answers, 'slots are seeded empty; the agent fills them');
            }
        } else {
            self::assertNull($result['verification_plan_sha256']);
        }
    }

    public function testMechanicalRunnerReplacesOneTargetMethodLiteralAndLintsIt(): void
    {
        $command = new EditCommand($this->root);

        ob_start();
        $exit = $command->run([
            'Demo\\UserService::save',
            '--task=EDIT-MECHANICAL',
            '--map-paths=src,tests',
            '--runner=mechanical',
            '--replace-old=if (!$active)',
            '--replace-new=if ($active === false)',
            '--',
            'Replace the explicit boolean check without changing other code.',
        ]);
        ob_end_clean();

        self::assertSame(0, $exit);
        $source = file_get_contents($this->root . '/src/UserService.php');
        self::assertIsString($source);
        self::assertStringContainsString('if ($active === false)', $source);
        self::assertStringContainsString('public function keep(bool $active): void', $source);
        self::assertStringContainsString('if (!$active)', $source);

        $executionJson = file_get_contents($this->root . '/.agent-loop/edit/EDIT-MECHANICAL/execution.json');
        self::assertIsString($executionJson);
        $execution = json_decode($executionJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($execution);
        self::assertSame('runner_succeeded', $execution['status']);
        self::assertSame('mechanical', $execution['runner']['name']);
        self::assertSame(['old' => 'if (!$active)', 'new' => 'if ($active === false)'], $execution['runner']['replacement']);
        self::assertSame(0, $execution['runner']['model_input_tokens']);
        self::assertSame(0, $execution['runner']['model_tool_calls']);
        $runnerOutput = file_get_contents($execution['artifacts']['runner_stdout']);
        self::assertIsString($runnerOutput);
        self::assertStringContainsString('applied exactly once', $runnerOutput);
        self::assertStringContainsString('No syntax errors detected', $runnerOutput);
    }

    public function testAutoRunnerUsesMechanicalExecutionForAnExactReplacement(): void
    {
        $command = new EditCommand($this->root);

        ob_start();
        $exit = $command->run([
            'Demo\\UserService::save',
            '--task=EDIT-AUTO-MECHANICAL',
            '--map-paths=src,tests',
            '--runner=auto',
            '--replace-old=if (!$active)',
            '--replace-new=if ($active === false)',
            '--',
            'Replace the explicit boolean check without a model runner.',
        ]);
        ob_end_clean();

        self::assertSame(0, $exit);
        $execution = $this->execution('EDIT-AUTO-MECHANICAL');
        self::assertSame('runner_succeeded', $execution['status']);
        self::assertSame('mechanical', $execution['runner']['name']);
        self::assertSame(0, $execution['runner']['model_input_tokens']);
        self::assertSame(0, $execution['runner']['model_tool_calls']);
        self::assertSame('auto', $execution['routing']['requested_runner']);
        self::assertSame('mechanical', $execution['routing']['selected_runner']);
        self::assertFalse($execution['routing']['external_model_invoked']);
    }

    public function testAutoRunnerRequiresExplicitEscalationWithoutReplacementProof(): void
    {
        $command = new EditCommand($this->root);

        ob_start();
        $exit = $command->run([
            'Demo\\UserService::save',
            '--task=EDIT-AUTO-ESCALATE',
            '--map-paths=src,tests',
            '--runner=auto',
            '--',
            'Make this implementation more robust.',
        ]);
        ob_end_clean();

        self::assertSame(1, $exit);
        $execution = $this->execution('EDIT-AUTO-ESCALATE');
        self::assertSame('escalation_required', $execution['status']);
        self::assertSame('none', $execution['runner']['name']);
        self::assertSame(0, $execution['runner']['model_input_tokens']);
        self::assertSame(0, $execution['runner']['model_tool_calls']);
        self::assertSame('auto', $execution['routing']['requested_runner']);
        self::assertSame('none', $execution['routing']['selected_runner']);
        self::assertFalse($execution['routing']['external_model_invoked']);
        self::assertStringContainsString('if (!$active)', (string) file_get_contents($this->root . '/src/UserService.php'));
    }

    /** @return array<string, mixed> */
    private function execution(string $taskId): array
    {
        $json = file_get_contents($this->root . '/.agent-loop/edit/' . $taskId . '/execution.json');
        self::assertIsString($json);
        $execution = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($execution);

        return $execution;
    }
}
