<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\PackageResources;

/**
 * The shipped Make include is the host-facing surface for asset installation
 * and reusable lifecycle entrypoints. A host that includes it must never call
 * an `init` subcommand this package does not implement, and must not lose a
 * declared target or its host-runner boundary on a refactor.
 *
 * @internal
 */
final class MakeIncludeContractTest extends TestCase
{
    private const string INCLUDE_PATH = __DIR__ . '/../' . PackageResources::MAKE_INCLUDE;

    public function testIncludeIsShippedAndReadable(): void
    {
        self::assertFileExists(self::INCLUDE_PATH);
        self::assertNotSame('', trim((string) file_get_contents(self::INCLUDE_PATH)));
    }

    public function testEveryDeclaredTargetIsPresent(): void
    {
        $content = $this->includeContent();

        $expectedTargets = [
            'agent_init_doctor',
            'agent_init_install_plan',
            'agent_init_status',
            'agent_init_host_status',
            'agent_init_tools',
            'validate_agent_skills',
            'validate_agent_subagents',
            'validate_codex_hooks',
            'validate_claude_hooks',
            'validate_agent_assets',
            'install_codex_skills',
            'install_claude_skills',
            'install_copilot_skills',
            'install_antigravity_skills',
            'install_cursor_skills',
            'install_agent_skills',
            'install_codex_agents',
            'install_copilot_agents',
            'install_antigravity_agents',
            'install_cursor_agents',
            'install_agent_subagents',
            'install_codex_hooks',
            'install_claude_hooks',
            'install_agent_hooks',
            'install_agent_assets',
            'install_githooks',
            'install_githooks_dry',
            'agent_workflow_plan',
            'agent_workflow_approve',
            'agent_workflow_enter',
            'agent_workflow_finish',
            'agent_workflow_quick',
            'agent_workflow_repair',
            'agent_workflow_pipeline',
            'agent_workflow_contract',
            'agent_workflow_execution_profile',
            'agent_workflow_attention',
            'agent_workflow_status',
            'agent_workflow_manifest',
            'agent_workflow_context',
            'agent_workflow_report',
            'agent_workflow_transparency',
            'agent_workflow_review',
            'agent_workflow_reflect',
            'agent_workflow_handoff',
            'agent_workflow_close',
        ];

        foreach ($expectedTargets as $target) {
            self::assertStringContainsString($target . ':', $content, 'Missing Make target: ' . $target);
        }
    }

    public function testEveryInvokedInitSubcommandExists(): void
    {
        $implemented = $this->implementedInitSubcommands();
        self::assertNotSame([], $implemented);

        $invoked = [];
        foreach (explode("\n", $this->includeContent()) as $line) {
            if (preg_match('~\$\(AGENT_LOOP_INIT\)\s+([a-z-]+)~', $line, $matches) === 1) {
                $invoked[$matches[1]] = $matches[1];
            }
        }

        self::assertNotSame([], $invoked);
        foreach ($invoked as $subcommand) {
            self::assertContains($subcommand, $implemented, 'Make include calls unknown init subcommand: ' . $subcommand);
        }
    }

    public function testHostOverridableVariablesUseSoftAssignment(): void
    {
        $content = $this->includeContent();

        foreach (['AGENT_LOOP_BIN', 'AGENT_LOOP_CONFIG', 'AGENT_LOOP_SYNC_FLAGS', 'AGENT_LOOP_DEFAULT_ACTOR', 'AGENT_LOOP_DEFAULT_VALIDATION', 'AGENT_LOOP_QUOTE'] as $variable) {
            self::assertMatchesRegularExpression(
                '~^' . preg_quote($variable, '~') . '\s+\?=~m',
                $content,
                $variable . ' must stay host-overridable',
            );
        }
    }

    public function testWorkflowTargetsUseTheExplicitHostRunner(): void
    {
        $content = $this->includeContent();
        self::assertStringContainsString('ifndef AGENT_LOOP_RUN', $content);
        self::assertStringContainsString('$(1)', $content);

        foreach ([
            'agent_workflow_plan',
            'agent_workflow_approve',
            'agent_workflow_enter',
            'agent_workflow_finish',
            'agent_workflow_quick',
            'agent_workflow_repair',
            'agent_workflow_pipeline',
            'agent_workflow_contract',
            'agent_workflow_execution_profile',
            'agent_workflow_attention',
            'agent_workflow_status',
            'agent_workflow_manifest',
            'agent_workflow_context',
            'agent_workflow_report',
            'agent_workflow_transparency',
            'agent_workflow_review',
            'agent_workflow_reflect',
            'agent_workflow_handoff',
            'agent_workflow_close',
        ] as $target) {
            $pattern = '~^' . preg_quote($target, '~') . ":\\n(?<recipe>(?:\\t[^\\n]*(?:\\n|$))+)~m";
            self::assertSame(1, preg_match($pattern, $content, $matches), 'Missing recipe: ' . $target);
            self::assertStringContainsString('$(call AGENT_LOOP_RUN,', $matches['recipe'], $target . ' must preserve the host runtime boundary');
        }
    }

    public function testHostRunnerCanReplaceDirectWorkflowExecution(): void
    {
        $makefile = tempnam(sys_get_temp_dir(), 'agent-loop-make-');
        self::assertIsString($makefile);

        $includePath = realpath(self::INCLUDE_PATH);
        self::assertIsString($includePath);
        $fixture = <<<'MAKE'
define AGENT_LOOP_RUN
	@printf 'command=%%s\n' '$(1)'
	@printf 'target=%%s\n' '$(2)'
endef

include %s
MAKE;

        try {
            self::assertNotFalse(file_put_contents($makefile, sprintf($fixture, $includePath)));

            $result = $this->runProcess([
                'make',
                '--no-print-directory',
                '--file',
                $makefile,
                'agent_workflow_enter',
                'TASK=TASK-123',
            ]);

            self::assertSame(0, $result['exit'], $result['stderr']);
            self::assertStringContainsString('command=vendor/bin/agent-loop enter "TASK-123" --format=json', $result['stdout']);
            self::assertStringContainsString('target=agent_workflow_enter', $result['stdout']);
        } finally {
            unlink($makefile);
        }
    }

    public function testDefaultRunnerPreservesApostrophesInHumanGoal(): void
    {
        $makefile = tempnam(sys_get_temp_dir(), 'agent-loop-make-');
        self::assertIsString($makefile);

        $includePath = realpath(self::INCLUDE_PATH);
        self::assertIsString($includePath);
        $fixture = <<<'MAKE'
AGENT_LOOP_BIN := printf '%%s\n'

include %s
MAKE;

        try {
            self::assertNotFalse(file_put_contents($makefile, sprintf($fixture, $includePath)));

            $result = $this->runProcess([
                'make',
                '--no-print-directory',
                '--file',
                $makefile,
                'agent_workflow_quick',
                'TASK=TASK-123',
                "GOAL=Confirm Bob's approval",
                'FILE=src/Foo.php',
            ]);

            self::assertSame(0, $result['exit'], $result['stderr']);
            self::assertStringContainsString("'Confirm Bob'\"'\"'s approval'", $result['stdout']);
            self::assertStringEndsWith("quick\nTASK-123\nConfirm Bob's approval\n--file=src/Foo.php\n", $result['stdout']);
        } finally {
            unlink($makefile);
        }
    }

    private function includeContent(): string
    {
        $content = file_get_contents(self::INCLUDE_PATH);
        self::assertIsString($content);

        return $content;
    }

    /**
     * @param list<string> $command
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runProcess(array $command): array
    {
        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        self::assertIsResource($process);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        self::assertIsString($stdout);
        self::assertIsString($stderr);

        return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * @return list<string>
     */
    private function implementedInitSubcommands(): array
    {
        $cli = file_get_contents(__DIR__ . '/../src/Init/InitCli.php');
        self::assertIsString($cli);

        preg_match_all("~^\\s+'([a-z-]+)' => \\(new Init~m", $cli, $matches);

        return array_values(array_unique($matches[1]));
    }
}
