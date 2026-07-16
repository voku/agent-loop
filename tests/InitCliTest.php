<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Dispatcher;

/**
 * @internal
 */
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

    public function testInitHelpExitsZero(): void
    {
        $result = $this->dispatch(['agent-loop', 'init', 'help']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('agent-loop init doctor', $result['output']);
        self::assertStringContainsString('Print reviewed setup commands for ripgrep, RTK, and Caveman.', $result['output']);
    }

    public function testInitLongHelpExitsZero(): void
    {
        $result = $this->dispatch(['agent-loop', 'init', '--help']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('agent-loop init install-plan', $result['output']);
    }

    public function testInitShortHelpExitsZero(): void
    {
        $result = $this->dispatch(['agent-loop', 'init', '-h']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('sync-hooks', $result['output']);
    }

    public function testUnknownInitCommandExitsOne(): void
    {
        $result = $this->dispatch(['agent-loop', 'init', 'unknown']);

        self::assertSame(1, $result['exit']);
    }

    public function testInitDoctorExitsZero(): void
    {
        $result = $this->dispatch(['agent-loop', 'init', 'doctor']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('agent-loop init doctor', $result['output']);
    }

    public function testInitStatusExitsZero(): void
    {
        $result = $this->dispatch(['agent-loop', 'init', 'status']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('agent-loop init status', $result['output']);
    }

    public function testInitHelpListsStatus(): void
    {
        $result = $this->dispatch(['agent-loop', 'init', 'help']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('init status', $result['output']);
    }

    public function testInitValidateWithoutKindExitsOne(): void
    {
        $result = $this->dispatch(['agent-loop', 'init', 'validate']);

        self::assertSame(1, $result['exit']);
    }

    public function testInitValidateSubagentsIsReserved(): void
    {
        mkdir($this->root . '/docs/agents/subagents', 0o775, true);
        file_put_contents($this->root . '/docs/agents/subagents/reviewer.md', "---\nname: reviewer\ndescription: Review things\n---\n\n# Reviewer\n");

        $result = $this->dispatch(['agent-loop', 'init', 'validate', '--kind=subagents']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('[OK] validate subagents: 1 subagent file(s) valid', $result['output']);
    }

    public function testInitValidateHooksSucceeds(): void
    {
        mkdir($this->root . '/docs/agents/codex-hooks/hooks', 0o775, true);
        file_put_contents($this->root . '/docs/agents/codex-hooks/hooks.json', json_encode([
            'hooks' => [
                'SessionStart' => [[
                    'hooks' => [[
                        'type' => 'command',
                        'command' => 'php $(git rev-parse --show-toplevel)/.codex/hooks/session_context.php',
                    ]],
                ]],
                'SubagentStart' => [[
                    'hooks' => [[
                        'type' => 'command',
                        'command' => 'php $(git rev-parse --show-toplevel)/.codex/hooks/subagent_context.php',
                    ]],
                ]],
                'PreToolUse' => [[
                    'hooks' => [[
                        'type' => 'command',
                        'command' => 'php $(git rev-parse --show-toplevel)/.codex/hooks/pre_tool_use_policy.php',
                    ]],
                ]],
            ],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
        file_put_contents($this->root . '/docs/agents/codex-hooks/hooks/session_context.php', "<?php\nreturn;\n");
        file_put_contents($this->root . '/docs/agents/codex-hooks/hooks/subagent_context.php', "<?php\nreturn;\n");
        file_put_contents($this->root . '/docs/agents/codex-hooks/hooks/pre_tool_use_policy.php', "<?php\nreturn;\n");

        $result = $this->dispatch(['agent-loop', 'init', 'validate', '--kind=hooks']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('[OK] validate hooks: hooks.json and 3 hook file(s) valid', $result['output']);
    }

    public function testInitValidateAllExitsZeroWhenAllAssetKindsAreValid(): void
    {
        mkdir($this->root . '/docs/agents/skills/demo-skill', 0o775, true);
        mkdir($this->root . '/docs/agents/subagents', 0o775, true);
        mkdir($this->root . '/docs/agents/codex-hooks/hooks', 0o775, true);
        file_put_contents($this->root . '/docs/agents/skills/demo-skill/SKILL.md', "# Demo\n");
        file_put_contents($this->root . '/docs/agents/subagents/reviewer.md', "---\nname: reviewer\ndescription: Review things\n---\n\n# Reviewer\n");
        file_put_contents($this->root . '/docs/agents/codex-hooks/hooks.json', json_encode([
            'hooks' => [
                'SessionStart' => [[
                    'hooks' => [[
                        'type' => 'command',
                        'command' => 'php $(git rev-parse --show-toplevel)/.codex/hooks/session_context.php',
                    ]],
                ]],
                'SubagentStart' => [[
                    'hooks' => [[
                        'type' => 'command',
                        'command' => 'php $(git rev-parse --show-toplevel)/.codex/hooks/subagent_context.php',
                    ]],
                ]],
                'PreToolUse' => [[
                    'hooks' => [[
                        'type' => 'command',
                        'command' => 'php $(git rev-parse --show-toplevel)/.codex/hooks/pre_tool_use_policy.php',
                    ]],
                ]],
            ],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
        file_put_contents($this->root . '/docs/agents/codex-hooks/hooks/session_context.php', "<?php\nreturn;\n");
        file_put_contents($this->root . '/docs/agents/codex-hooks/hooks/subagent_context.php', "<?php\nreturn;\n");
        file_put_contents($this->root . '/docs/agents/codex-hooks/hooks/pre_tool_use_policy.php', "<?php\nreturn;\n");

        $result = $this->dispatch(['agent-loop', 'init', 'validate', '--kind=all']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('[OK] validate skills: 1 skill file(s) valid', $result['output']);
        self::assertStringContainsString('[OK] validate subagents: 1 subagent file(s) valid', $result['output']);
        self::assertStringContainsString('[OK] validate hooks: hooks.json and 3 hook file(s) valid', $result['output']);
    }

    public function testSyncSkillsExitsZero(): void
    {
        mkdir($this->root . '/docs/agents/skills/demo-skill', 0o775, true);
        file_put_contents($this->root . '/docs/agents/skills/demo-skill/SKILL.md', "# Demo\n");

        $result = $this->dispatch(['agent-loop', 'init', 'sync-skills', '--agent=codex']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('[OK] sync skills: synced 1 skill file(s) for codex', $result['output']);
    }

    public function testSyncSubagentsExitsZero(): void
    {
        mkdir($this->root . '/docs/agents/subagents', 0o775, true);
        file_put_contents($this->root . '/docs/agents/subagents/reviewer.md', "---\nname: reviewer\ndescription: Review things\n---\n\n# Reviewer\n");

        $result = $this->dispatch(['agent-loop', 'init', 'sync-subagents', '--agent=copilot']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('[OK] sync subagents: synced 1 subagent file(s) for copilot', $result['output']);
    }

    public function testSyncHooksExitsZero(): void
    {
        mkdir($this->root . '/docs/agents/codex-hooks/hooks', 0o775, true);
        file_put_contents($this->root . '/docs/agents/codex-hooks/hooks.json', json_encode([
            'hooks' => [
                'SessionStart' => [[
                    'hooks' => [[
                        'type' => 'command',
                        'command' => 'php $(git rev-parse --show-toplevel)/.codex/hooks/session_context.php',
                    ]],
                ]],
                'SubagentStart' => [[
                    'hooks' => [[
                        'type' => 'command',
                        'command' => 'php $(git rev-parse --show-toplevel)/.codex/hooks/subagent_context.php',
                    ]],
                ]],
                'PreToolUse' => [[
                    'hooks' => [[
                        'type' => 'command',
                        'command' => 'php $(git rev-parse --show-toplevel)/.codex/hooks/pre_tool_use_policy.php',
                    ]],
                ]],
            ],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
        file_put_contents($this->root . '/docs/agents/codex-hooks/hooks/session_context.php', "<?php\nreturn;\n");
        file_put_contents($this->root . '/docs/agents/codex-hooks/hooks/subagent_context.php', "<?php\nreturn;\n");
        file_put_contents($this->root . '/docs/agents/codex-hooks/hooks/pre_tool_use_policy.php', "<?php\nreturn;\n");

        $result = $this->dispatch(['agent-loop', 'init', 'sync-hooks', '--agent=codex']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('[OK] sync hooks: synced 3 hook file(s) into', $result['output']);
    }

    public function testScaffoldCreatesAFirstTaskThatCanBePlannedAndInspected(): void
    {
        file_put_contents($this->root . '/composer.json', "{\"name\": \"demo/project\"}\n");

        $result = $this->dispatch(['agent-loop', 'init', 'scaffold']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertFileExists($this->root . '/.agent-loop/init.json');
        self::assertFileExists($this->root . '/todo/board.md');
        self::assertFileExists($this->root . '/todo/cards/DEMO-1.md');
        self::assertFileExists($this->root . '/tasks/DEMO-1.md');
        self::assertDirectoryExists($this->root . '/session_plan');
        self::assertDirectoryExists($this->root . '/infra/doc/agent-learning/findings');
        self::assertStringContainsString('board card show DEMO-1', $result['output']);

        $show = $this->dispatch(['agent-loop', 'board', 'card', 'show', 'DEMO-1']);
        self::assertSame(0, $show['exit'], $show['output']);
        self::assertStringContainsString('DEMO-1: Add a small validated change', $show['output']);

        $plan = $this->dispatch([
            'agent-loop', 'workflow', 'plan', 'DEMO-1', '--by', 'tester',
            '--file', 'composer.json', '--goal', 'Add a small validated change.', '--validation', 'composer test',
        ]);
        self::assertSame(0, $plan['exit'], $plan['output']);

        $approve = $this->dispatch(['agent-loop', 'workflow', 'approve', 'DEMO-1', '--by', 'tester']);
        self::assertSame(0, $approve['exit'], $approve['output']);

        $context = $this->dispatch(['agent-loop', 'workflow', 'context', 'DEMO-1']);
        self::assertSame(0, $context['exit'], $context['output']);
        self::assertStringContainsString('Add a small validated change.', $context['output']);

        $verify = $this->dispatch(['agent-loop', 'verify']);
        self::assertSame(0, $verify['exit'], $verify['output']);

        $validation = $this->dispatch([
            'agent-loop', 'session', 'validation', 'record', 'DEMO-1', '--brief-revision', '1',
            '--command', 'composer test', '--status', 'passed', '--exit-code', '0', '--duration-ms', '0', '--by', 'tester',
            '--root', $this->root . '/session_plan',
        ]);
        self::assertSame(0, $validation['exit'], $validation['output']);

        $review = $this->dispatch(['agent-loop', 'review', 'blindspots', 'DEMO-1']);
        self::assertSame(0, $review['exit'], $review['output']);
        self::assertFileExists($this->root . '/.agent-recall/reviews/DEMO-1.blindspots.json');

        $checkpoint = $this->dispatch(['agent-loop', 'session', 'checkpoint', 'DEMO-1', '--title', 'Review', '--body', 'review blindspots DEMO-1 was checked.', '--root', $this->root . '/session_plan']);
        self::assertSame(0, $checkpoint['exit'], $checkpoint['output']);

        $review = $this->dispatch(['agent-loop', 'review', 'blindspots', 'DEMO-1']);
        self::assertSame(0, $review['exit'], $review['output']);
        $reportJson = file_get_contents($this->root . '/.agent-recall/reviews/DEMO-1.blindspots.json');
        self::assertNotFalse($reportJson);
        $report = json_decode($reportJson, true, 512, JSON_THROW_ON_ERROR);
        $findingIds = array_column($report['findings'], 'id');
        self::assertNotContains(
            'missing_review_checkpoint',
            $findingIds,
            'Expected the recorded checkpoint to satisfy the review-checkpoint marker. Findings: ' . $reportJson,
        );

        $learning = $this->dispatch(['agent-loop', 'session', 'learning', 'decide', 'DEMO-1', '--status', 'no_durable_learning', '--by', 'tester', '--root', $this->root . '/session_plan']);
        self::assertSame(0, $learning['exit'], $learning['output']);

        $close = $this->dispatch(['agent-loop', 'workflow', 'close', 'DEMO-1', '--status', 'done']);
        self::assertSame(0, $close['exit'], $close['output']);
    }

    public function testScaffoldDryRunDoesNotWrite(): void
    {
        $result = $this->dispatch(['agent-loop', 'init', 'scaffold', '--dry-run']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('[DRY-RUN] would create .agent-loop/', $result['output']);
        self::assertDirectoryDoesNotExist($this->root . '/.agent-loop');
    }

    public function testScaffoldDoesNotOverwriteExistingFiles(): void
    {
        mkdir($this->root . '/.agent-loop', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/init.json', "{\"version\": 99}\n");

        $result = $this->dispatch(['agent-loop', 'init', 'scaffold']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame("{\"version\": 99}\n", file_get_contents($this->root . '/.agent-loop/init.json'));
        self::assertStringContainsString('[SKIP] .agent-loop/init.json already exists', $result['output']);
    }

    public function testScaffoldRejectsUnsupportedOptions(): void
    {
        $result = $this->dispatch(['agent-loop', 'init', 'scaffold', '--profile=wsl2']);

        self::assertSame(1, $result['exit']);
    }

    public function testUnknownAgentExitsOne(): void
    {
        $result = $this->dispatch(['agent-loop', 'init', 'install-plan', '--profile=wsl2', '--agent=nope']);

        self::assertSame(1, $result['exit']);
    }

    public function testGeminiAliasPrintsWarningAndResolvesToAntigravity(): void
    {
        $result = $this->dispatch(['agent-loop', 'init', 'install-plan', '--profile=wsl2', '--agent=gemini']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('[WARN] Agent "gemini" is treated as a legacy Google coding-agent alias.', $result['output']);
        self::assertStringContainsString('[INFO] Using canonical agent "antigravity".', $result['output']);
        self::assertStringContainsString('Agent: antigravity', $result['output']);
    }

    /**
     * @param list<string> $argv
     *
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
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
