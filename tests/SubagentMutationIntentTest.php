<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Init\SubagentDefinition;

final class SubagentMutationIntentTest extends TestCase
{
    public function testFirstPartyReadOnlyIntentProjectsOnlyToCodexSandbox(): void
    {
        $root = dirname(__DIR__) . '/resources/subagents';
        foreach ([
            'agent-loop-investigator',
            'agent-loop-solution-triager',
            'agent-loop-blindspot-reviewer',
            'agent-loop-code-reviewer',
        ] as $name) {
            $path = $root . '/' . $name . '.md';
            self::assertSame([], SubagentDefinition::validationErrors($path));

            $definition = SubagentDefinition::fromCanonicalFile($path);
            self::assertStringContainsString('sandbox_mode = "read-only"', $definition->renderForClient('codex'));
            foreach (['copilot', 'claude', 'opencode', 'gemini', 'antigravity'] as $client) {
                self::assertStringNotContainsString('sandbox_mode', $definition->renderForClient($client));
            }
        }
    }

    public function testWritableFirstPartyRoleDoesNotReceiveReadOnlySandbox(): void
    {
        $path = dirname(__DIR__) . '/resources/subagents/agent-loop-surgical-builder.md';
        self::assertSame([], SubagentDefinition::validationErrors($path));

        $rendered = SubagentDefinition::fromCanonicalFile($path)->renderForClient('codex');

        self::assertStringNotContainsString('sandbox_mode', $rendered);
        self::assertStringContainsString('developer_instructions = ', $rendered);
    }

    public function testInvalidExplicitMutationIntentFailsClosed(): void
    {
        $path = $this->fixture("---\nname: fixture\ndescription: Fixture role.\nmutation: maybe\n---\n\nInspect only.\n");

        self::assertSame(
            ["Invalid 'mutation' in frontmatter; expected read-only or write"],
            SubagentDefinition::validationErrors($path),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid 'mutation' in frontmatter; expected read-only or write");
        SubagentDefinition::fromCanonicalFile($path);
    }

    public function testAbsentMutationIntentKeepsRepositoryOwnedRoleCompatibleWithoutEnforcementClaim(): void
    {
        $path = $this->fixture("---\nname: fixture\ndescription: Fixture role.\n---\n\nInspect the repository.\n");

        self::assertSame([], SubagentDefinition::validationErrors($path));
        self::assertStringNotContainsString(
            'sandbox_mode',
            SubagentDefinition::fromCanonicalFile($path)->renderForClient('codex'),
        );
    }

    private function fixture(string $content): string
    {
        $directory = sys_get_temp_dir() . '/agent-loop-subagent-mutation-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0o775, true));
        $path = $directory . '/fixture.md';
        self::assertIsInt(file_put_contents($path, $content));
        $this->registerCleanup($directory);

        return $path;
    }

    private function registerCleanup(string $directory): void
    {
        register_shutdown_function(static function () use ($directory): void {
            if (!is_dir($directory)) {
                return;
            }
            foreach (scandir($directory) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $directory . '/' . $entry;
                is_dir($path) ? rmdir($path) : unlink($path);
            }
            rmdir($directory);
        });
    }
}
