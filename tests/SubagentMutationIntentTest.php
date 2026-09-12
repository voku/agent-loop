<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Init\SubagentDefinition;

final class SubagentMutationIntentTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirectories as $directory) {
            foreach (scandir($directory) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                unlink($directory . '/' . $entry);
            }
            rmdir($directory);
        }
    }

    public function testFirstPartyReadOnlyRolesProjectCodexSandboxWithoutClaimingItForOtherHosts(): void
    {
        $root = dirname(__DIR__) . '/resources/subagents';
        foreach ([
            'agent-loop-investigator',
            'agent-loop-solution-triager',
            'agent-loop-blindspot-reviewer',
            'agent-loop-code-reviewer',
        ] as $name) {
            $definition = SubagentDefinition::fromCanonicalFile($root . '/' . $name . '.md');

            $codex = $definition->renderForClient('codex');
            self::assertStringContainsString('sandbox_mode = "read-only"', $codex, $name);
            self::assertStringNotContainsString('model =', $codex, $name);

            foreach (['claude', 'opencode', 'copilot', 'gemini', 'antigravity'] as $host) {
                self::assertStringNotContainsString(
                    'sandbox_mode',
                    $definition->renderForClient($host),
                    $name . ' must not invent a sandbox field for ' . $host,
                );
            }
        }
    }

    public function testWritableRoleDoesNotReceiveReadOnlySandbox(): void
    {
        $definition = SubagentDefinition::fromCanonicalFile(
            dirname(__DIR__) . '/resources/subagents/agent-loop-surgical-builder.md',
        );

        self::assertStringNotContainsString('sandbox_mode', $definition->renderForClient('codex'));
    }

    public function testMissingMutationIntentPreservesLegacyWritableBehavior(): void
    {
        $path = $this->writeDefinition(
            "---\nname: legacy-role\ndescription: Legacy custom role.\n---\n\nMay edit when asked.\n",
            'legacy-role.md',
        );

        self::assertSame([], SubagentDefinition::validationErrors($path));
        self::assertStringNotContainsString(
            'sandbox_mode',
            SubagentDefinition::fromCanonicalFile($path)->renderForClient('codex'),
        );
    }

    public function testUnknownMutationIntentFailsValidationInsteadOfBecomingWritable(): void
    {
        $path = $this->writeDefinition(
            "---\nname: invalid-role\ndescription: Invalid custom role.\nmutation: sometimes\n---\n\nPrompt.\n",
            'invalid-role.md',
        );

        self::assertSame(
            ["Invalid 'mutation' in frontmatter; expected 'read-only' or 'writable'"],
            SubagentDefinition::validationErrors($path),
        );
    }

    private function writeDefinition(string $content, string $filename): string
    {
        $directory = sys_get_temp_dir() . '/agent-loop-subagent-intent-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0o775, true));
        $this->tempDirectories[] = $directory;
        $path = $directory . '/' . $filename;
        file_put_contents($path, $content);

        return $path;
    }
}
