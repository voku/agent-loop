<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Dispatcher;

final class InitCliTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-init-cli-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testInitHelpAndDoctorExposeSupportedSetupSurface(): void
    {
        $help = $this->dispatch(['agent-loop', 'init', 'help']);
        self::assertSame(0, $help['exit']);
        self::assertStringContainsString('agent-loop init doctor', $help['output']);
        self::assertStringContainsString('agent-loop init install-plan', $help['output']);
        self::assertStringContainsString('agent-loop init install-assets', $help['output']);
        self::assertStringContainsString('init status', $help['output']);
        self::assertStringNotContainsString('rtk', strtolower($help['output']));

        $doctor = $this->dispatch(['agent-loop', 'init', 'doctor']);
        self::assertSame(0, $doctor['exit']);
        self::assertStringContainsString('agent-loop init doctor', $doctor['output']);

        $status = $this->dispatch(['agent-loop', 'init', 'status']);
        self::assertSame(0, $status['exit']);
        self::assertStringContainsString('agent-loop init status', $status['output']);
    }

    public function testInitValidationCoversSkillsSubagentsAndHooks(): void
    {
        mkdir($this->root . '/docs/agents/skills/demo-skill', 0o775, true);
        mkdir($this->root . '/docs/agents/subagents', 0o775, true);
        mkdir($this->root . '/docs/agents/codex-hooks/hooks', 0o775, true);
        file_put_contents($this->root . '/docs/agents/skills/demo-skill/SKILL.md', "# Demo\n");
        file_put_contents(
            $this->root . '/docs/agents/subagents/reviewer.md',
            "---\nname: reviewer\ndescription: Review things\n---\n\n# Reviewer\n",
        );
        file_put_contents($this->root . '/docs/agents/codex-hooks/hooks.json', json_encode([
            'hooks' => [
                'SessionStart' => [['hooks' => [[
                    'type' => 'command',
                    'command' => 'php $(git rev-parse --show-toplevel)/.codex/hooks/session_context.php',
                ]]]],
                'SubagentStart' => [['hooks' => [[
                    'type' => 'command',
                    'command' => 'php $(git rev-parse --show-toplevel)/.codex/hooks/subagent_context.php',
                ]]]],
                'PreToolUse' => [['hooks' => [[
                    'type' => 'command',
                    'command' => 'php $(git rev-parse --show-toplevel)/.codex/hooks/pre_tool_use_policy.php',
                ]]]],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        file_put_contents($this->root . '/docs/agents/codex-hooks/hooks/session_context.php', "<?php\nreturn;\n");
        file_put_contents($this->root . '/docs/agents/codex-hooks/hooks/subagent_context.php', "<?php\nreturn;\n");
        file_put_contents($this->root . '/docs/agents/codex-hooks/hooks/pre_tool_use_policy.php', "<?php\nreturn;\n");

        $result = $this->dispatch(['agent-loop', 'init', 'validate', '--kind=all']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('[OK] validate skills: 1 skill file(s) valid', $result['output']);
        self::assertStringContainsString('[OK] validate subagents: 1 subagent file(s) valid', $result['output']);
        self::assertStringContainsString('[OK] validate hooks: hooks.json and 3 hook file(s) valid', $result['output']);
    }

    public function testSyncProjectsPackageOwnedAssetsWithoutInventingUnsupportedCapabilities(): void
    {
        mkdir($this->root . '/docs/agents/skills/demo-skill', 0o775, true);
        mkdir($this->root . '/docs/agents/subagents', 0o775, true);
        file_put_contents($this->root . '/docs/agents/skills/demo-skill/SKILL.md', "# Demo\n");
        file_put_contents(
            $this->root . '/docs/agents/subagents/reviewer.md',
            "---\nname: reviewer\ndescription: Review things\n---\n\n# Reviewer\n",
        );

        $skills = $this->dispatch(['agent-loop', 'init', 'sync-skills', '--agent=codex']);
        self::assertSame(0, $skills['exit'], $skills['output']);
        self::assertStringContainsString('synced 1 skill file(s) for codex', $skills['output']);

        $subagents = $this->dispatch(['agent-loop', 'init', 'sync-subagents', '--agent=copilot']);
        self::assertSame(0, $subagents['exit'], $subagents['output']);
        self::assertStringContainsString('synced 1 subagent file(s) for copilot', $subagents['output']);
    }

    public function testScaffoldCreatesFirstTaskAndCompletesGovernedWorkflow(): void
    {
        file_put_contents($this->root . '/composer.json', "{\"name\": \"demo/project\"}\n");

        $scaffold = $this->dispatch(['agent-loop', 'init', 'scaffold']);
        self::assertSame(0, $scaffold['exit'], $scaffold['output']);
        self::assertFileExists($this->root . '/.agent-loop/init.json');
        self::assertFileExists($this->root . '/.agent-loop/todo/cards/DEMO-1.md');
        self::assertFileExists($this->root . '/.agent-loop/tasks/DEMO-1.md');
        self::assertDirectoryExists($this->root . '/.agent-loop/sessions');
        self::assertDirectoryExists($this->root . '/.agent-loop/learning/findings');
        self::assertSame(
            "# Board Metadata\n\n- **Project prefix:** DEMO\n",
            file_get_contents($this->root . '/.agent-loop/todo/board.md'),
        );

        $plan = $this->dispatch([
            'agent-loop', 'workflow', 'plan', 'DEMO-1', '--by', 'tester',
            '--file', 'composer.json',
            '--goal', 'Add a small validated change.',
            '--validation', 'composer test',
        ]);
        self::assertSame(0, $plan['exit'], $plan['output']);

        $approve = $this->dispatch(['agent-loop', 'workflow', 'approve', 'DEMO-1', '--by', 'tester']);
        self::assertSame(0, $approve['exit'], $approve['output']);
        self::assertFileExists($this->root . '/.agent-loop/contracts/DEMO-1/contract.json');
        self::assertFileExists($this->root . '/.agent-loop/runs/DEMO-1/run.json');

        $context = $this->dispatch(['agent-loop', 'workflow', 'context', 'DEMO-1']);
        self::assertSame(0, $context['exit'], $context['output']);
        self::assertStringContainsString('Add a small validated change.', $context['output']);
        self::assertStringContainsString('Contract: revision 1', $context['output']);

        $validation = $this->dispatch([
            'agent-loop', 'session', 'validation', 'record', 'DEMO-1',
            '--contract-revision', '1',
            '--command', 'composer test',
            '--status', 'passed',
            '--exit-code', '0',
            '--duration-ms', '0',
            '--by', 'tester',
        ]);
        self::assertSame(0, $validation['exit'], $validation['output']);

        $review = $this->dispatch(['agent-loop', 'review', 'blindspots', 'DEMO-1']);
        self::assertSame(0, $review['exit'], $review['output']);
        self::assertFileExists($this->root . '/.agent-loop/recall/DEMO-1/reviews/DEMO-1.blindspots.json');

        $checkpoint = $this->dispatch([
            'agent-loop', 'session', 'checkpoint', 'DEMO-1',
            '--title', 'Review',
            '--body', 'review blindspots DEMO-1 was checked.',
        ]);
        self::assertSame(0, $checkpoint['exit'], $checkpoint['output']);
        self::assertSame(0, $this->dispatch(['agent-loop', 'review', 'blindspots', 'DEMO-1'])['exit']);

        $learning = $this->dispatch([
            'agent-loop', 'workflow', 'learn', 'DEMO-1',
            '--status', 'no_durable_learning',
            '--by', 'tester',
            '--reason', 'The scaffold proof produced no reusable guidance.',
        ]);
        self::assertSame(0, $learning['exit'], $learning['output']);

        $verify = $this->dispatch(['agent-loop', 'verify', '--task-id=DEMO-1']);
        self::assertSame(0, $verify['exit'], $verify['output']);

        $close = $this->dispatch(['agent-loop', 'workflow', 'close', 'DEMO-1', '--status', 'done']);
        self::assertSame(0, $close['exit'], $close['output']);
        self::assertFileExists($this->root . '/.agent-loop/runs/DEMO-1/verification.json');
    }

    public function testScaffoldDryRunDoesNotWriteAndExistingFilesAreNotOverwritten(): void
    {
        $dry = $this->dispatch(['agent-loop', 'init', 'scaffold', '--dry-run']);
        self::assertSame(0, $dry['exit']);
        self::assertStringContainsString('[DRY-RUN] would create .agent-loop/', $dry['output']);
        self::assertDirectoryDoesNotExist($this->root . '/.agent-loop');

        mkdir($this->root . '/.agent-loop', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/init.json', "{\"version\": 99}\n");
        $scaffold = $this->dispatch(['agent-loop', 'init', 'scaffold']);
        self::assertSame(0, $scaffold['exit'], $scaffold['output']);
        self::assertSame("{\"version\": 99}\n", file_get_contents($this->root . '/.agent-loop/init.json'));
        self::assertStringContainsString('[SKIP] .agent-loop/init.json already exists', $scaffold['output']);
    }

    public function testUnknownCommandsAndUnsupportedOptionsFail(): void
    {
        self::assertSame(1, $this->dispatch(['agent-loop', 'init', 'unknown'])['exit']);
        self::assertSame(1, $this->dispatch(['agent-loop', 'init', 'validate'])['exit']);
        self::assertSame(1, $this->dispatch(['agent-loop', 'init', 'scaffold', '--profile=wsl2'])['exit']);
        self::assertSame(1, $this->dispatch(['agent-loop', 'init', 'install-plan', '--profile=wsl2', '--agent=nope'])['exit']);
    }

    /**

     * @param list<string> $argv

     * @return array{exit: int, output: string}

     */
    private function dispatch(array $argv): array
    {
        $dispatcher = new Dispatcher($this->root);

        ob_start();
        $exit = $dispatcher->run($argv);
        $output = (string) ob_get_clean();

        return ['exit' => $exit, 'output' => $output];
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
