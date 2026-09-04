<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The shipped Make include is the host-facing surface for asset installation.
 * A host that includes it must never call an `init` subcommand this package
 * does not implement, and must not lose a client target on a refactor.
 *
 * @internal
 */
final class MakeIncludeContractTest extends TestCase
{
    private const string INCLUDE_PATH = __DIR__ . '/../resources/make/agent-loop.mk';

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
            'install_agent_skills',
            'install_codex_agents',
            'install_copilot_agents',
            'install_antigravity_agents',
            'install_agent_subagents',
            'install_codex_hooks',
            'install_claude_hooks',
            'install_agent_hooks',
            'install_agent_assets',
            'install_githooks',
            'install_githooks_dry',
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

        foreach (['AGENT_LOOP_BIN', 'AGENT_LOOP_CONFIG', 'AGENT_LOOP_SYNC_FLAGS'] as $variable) {
            self::assertMatchesRegularExpression(
                '~^' . preg_quote($variable, '~') . '\s+\?=~m',
                $content,
                $variable . ' must stay host-overridable',
            );
        }
    }

    private function includeContent(): string
    {
        $content = file_get_contents(self::INCLUDE_PATH);
        self::assertIsString($content);

        return $content;
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
