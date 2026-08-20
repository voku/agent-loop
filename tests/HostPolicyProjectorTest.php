<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Init\HostPolicyProjector;

final class HostPolicyProjectorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-host-policy-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root, 0o775, true) && !is_dir($this->root)) {
            throw new RuntimeException('Unable to create host-policy fixture root.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testClaudeMergePreservesProjectSettingsAndUsesHardDenyWithoutWritingAutoMode(): void
    {
        mkdir($this->root . '/.claude', 0o775, true);
        file_put_contents($this->root . '/.claude/settings.json', json_encode([
            'hooks' => ['SessionStart' => []],
            'permissions' => [
                'allow' => ['Read(*)'],
                'deny' => ['Bash(git push *)'],
                'ask' => ['Bash(existing *)'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $projector = new HostPolicyProjector($this->root);
        $first = $projector->sync('claude');
        $second = $projector->sync('claude');

        self::assertTrue($first['changed']);
        self::assertFalse($second['changed']);
        self::assertSame('ready', $projector->inspect('claude')['status']);

        $settings = $this->decodeJson($this->root . '/.claude/settings.json');
        self::assertSame(['SessionStart' => []], $settings['hooks'] ?? null);
        self::assertSame(['Read(*)'], $settings['permissions']['allow'] ?? null);
        self::assertSame(['Bash(existing *)'], $settings['permissions']['ask'] ?? null);
        self::assertContains('Bash(git push *)', $settings['permissions']['deny'] ?? []);
        self::assertContains('Bash(gh pr create *)', $settings['permissions']['deny'] ?? []);
        self::assertContains('Bash(gh pr merge *)', $settings['permissions']['deny'] ?? []);
        self::assertArrayNotHasKey('autoMode', $settings);
    }

    public function testClaudeConflictingAskRequiresReviewedForceBeforeBecomingDeny(): void
    {
        mkdir($this->root . '/.claude', 0o775, true);
        file_put_contents($this->root . '/.claude/settings.json', json_encode([
            'permissions' => [
                'ask' => ['Bash(git push *)'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $projector = new HostPolicyProjector($this->root);
        self::assertSame('conflict', $projector->inspect('claude')['status']);

        try {
            $projector->sync('claude');
            self::fail('Expected conflicting Claude permission to fail closed.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('use --force only after reviewing', $exception->getMessage());
        }

        self::assertTrue($projector->sync('claude', false, true)['changed']);
        self::assertSame('ready', $projector->inspect('claude')['status']);

        $settings = $this->decodeJson($this->root . '/.claude/settings.json');
        self::assertNotContains('Bash(git push *)', $settings['permissions']['ask'] ?? []);
        self::assertContains('Bash(git push *)', $settings['permissions']['deny'] ?? []);
    }

    public function testClaudeRejectsNonStringPermissionEntriesAtTheJsonBoundary(): void
    {
        mkdir($this->root . '/.claude', 0o775, true);
        file_put_contents($this->root . '/.claude/settings.json', json_encode([
            'permissions' => [
                'allow' => ['Read(*)', 42],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $projector = new HostPolicyProjector($this->root);
        $status = $projector->inspect('claude');
        self::assertSame('conflict', $status['status']);
        self::assertStringContainsString('must contain only non-empty strings', $status['detail']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must contain only non-empty strings');
        $projector->sync('claude');
    }

    public function testCodexUsesDedicatedManagedRuleFileAndFailsClosedOnConflict(): void
    {
        $projector = new HostPolicyProjector($this->root);

        $first = $projector->sync('codex');
        self::assertTrue($first['changed']);
        self::assertSame('ready', $projector->inspect('codex')['status']);
        self::assertFalse($projector->sync('codex')['changed']);

        $path = $this->root . '/.codex/rules/agent-loop.rules';
        $content = file_get_contents($path);
        self::assertIsString($content);
        self::assertStringContainsString('pattern = ["git", "push"]', $content);
        self::assertStringContainsString('decision = "prompt"', $content);

        file_put_contents($path, "# project-owned\n");
        self::assertSame('conflict', $projector->inspect('codex')['status']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('use --force only after reviewing the diff');
        $projector->sync('codex');
    }

    public function testOpenCodeMergePreservesUnrelatedConfigurationAndUsesDenyForAutoModeSafety(): void
    {
        file_put_contents($this->root . '/opencode.json', json_encode([
            '$schema' => 'https://opencode.ai/config.json',
            'model' => 'example/provider-model',
            'permission' => [
                'read' => 'allow',
                'bash' => [
                    'composer test' => 'allow',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $projector = new HostPolicyProjector($this->root);
        self::assertTrue($projector->sync('opencode')['changed']);
        self::assertSame('ready', $projector->inspect('opencode')['status']);
        self::assertFalse($projector->sync('opencode')['changed']);

        $config = $this->decodeJson($this->root . '/opencode.json');
        self::assertSame('example/provider-model', $config['model'] ?? null);
        self::assertSame('allow', $config['permission']['read'] ?? null);
        self::assertSame('allow', $config['permission']['bash']['composer test'] ?? null);
        self::assertSame('deny', $config['permission']['bash']['git push *'] ?? null);
        self::assertSame('deny', $config['permission']['bash']['gh pr create *'] ?? null);
        self::assertSame('deny', $config['permission']['bash']['gh pr merge *'] ?? null);
    }

    public function testOpenCodeMalformedExistingDecisionFailsClosedWithoutStringCasting(): void
    {
        file_put_contents($this->root . '/opencode.json', json_encode([
            'permission' => [
                'bash' => [
                    'git push *' => ['unexpected'],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $projector = new HostPolicyProjector($this->root);
        self::assertSame('conflict', $projector->inspect('opencode')['status']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('with decision type/value array/<non-scalar>');
        $projector->sync('opencode');
    }

    public function testClaudeDenyRuleAlsoListedAsAllowIsReportedAndCleanedRatherThanAcceptedAsReady(): void
    {
        mkdir($this->root . '/.claude', 0o775, true);
        file_put_contents($this->root . '/.claude/settings.json', json_encode([
            'permissions' => [
                'allow' => ['Bash(git push *)', 'Read(*)'],
                'ask' => [],
                'deny' => ['Bash(git push *)', 'Bash(gh pr create *)', 'Bash(gh pr merge *)'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $projector = new HostPolicyProjector($this->root);

        // Every required rule is already denied, so a deny-first check would
        // call this ready and leave the contradicting allow entry in place.
        self::assertSame('conflict', $projector->inspect('claude')['status']);

        try {
            $projector->sync('claude');
            self::fail('Synchronisation must not silently keep a permissive entry for a deny boundary.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('use --force only after reviewing', $exception->getMessage());
        }

        self::assertTrue($projector->sync('claude', false, true)['changed']);

        $settings = $this->decodeJson($this->root . '/.claude/settings.json');
        self::assertSame(['Read(*)'], $settings['permissions']['allow'] ?? null);
        self::assertContains('Bash(git push *)', $settings['permissions']['deny'] ?? []);
        self::assertSame('ready', $projector->inspect('claude')['status']);
    }

    public function testJsonArraysAreRefusedWhereAnObjectIsRequiredInsteadOfBeingRewritten(): void
    {
        $projector = new HostPolicyProjector($this->root);
        $path = $this->root . '/opencode.json';

        foreach (
            [
                '[1,2,3]' => 'JSON configuration must contain an object',
                '{"permission":["git push *"]}' => 'permission is not a JSON object',
                '{"permission":{"bash":["git push *"]}}' => 'permission.bash is not a JSON object',
                // An empty JSON array is still an array. Normalising it into an
                // object would rewrite the document into a different JSON type.
                '[]' => 'JSON configuration must contain an object',
                '{"permission":[]}' => 'permission is not a JSON object',
                '{"permission":{"bash":[]}}' => 'permission.bash is not a JSON object',
            ] as $document => $expected
        ) {
            file_put_contents($path, $document);

            $inspection = $projector->inspect('opencode');

            // Reporting these as missing would advertise a repair command that
            // deterministically refuses the same state.
            self::assertSame('conflict', $inspection['status'], $document);
            self::assertStringContainsString($expected, $inspection['detail'], $document);

            try {
                $projector->sync('opencode');
                self::fail('Synchronisation must refuse a JSON array where an object is required: ' . $document);
            } catch (InvalidArgumentException) {
                // expected
            }

            self::assertSame($document, file_get_contents($path), 'refused input must stay untouched');
        }
    }

    public function testEmptyClaudePermissionArrayIsRefusedRatherThanRewrittenAsAnObject(): void
    {
        $projector = new HostPolicyProjector($this->root);
        $path = $this->root . '/.claude/settings.json';
        mkdir(dirname($path), 0o777, true);

        foreach (['[]', '{"permissions":[]}'] as $document) {
            file_put_contents($path, $document);

            $inspection = $projector->inspect('claude');
            self::assertSame('conflict', $inspection['status'], $document);

            try {
                $projector->sync('claude');
                self::fail('Synchronisation must refuse a JSON array where an object is required: ' . $document);
            } catch (InvalidArgumentException) {
                // expected
            }

            self::assertSame($document, file_get_contents($path), 'refused input must stay untouched');
        }
    }

    public function testEmptyJsonObjectsStayRepairableSoTheBoundaryDoesNotOvercorrect(): void
    {
        $projector = new HostPolicyProjector($this->root);
        $claudePath = $this->root . '/.claude/settings.json';
        mkdir(dirname($claudePath), 0o777, true);

        file_put_contents($claudePath, '{"permissions":{}}');
        self::assertSame('missing', $projector->inspect('claude')['status']);
        self::assertTrue($projector->sync('claude')['changed']);
        self::assertSame('ready', $projector->inspect('claude')['status']);

        file_put_contents($this->root . '/opencode.json', '{"permission":{"bash":{}}}');
        self::assertSame('missing', $projector->inspect('opencode')['status']);
        self::assertTrue($projector->sync('opencode')['changed']);
        self::assertSame('ready', $projector->inspect('opencode')['status']);
    }

    public function testUnrelatedEmptyObjectsKeepTheirJsonTypeAcrossSynchronisation(): void
    {
        $path = $this->root . '/.claude/settings.json';
        mkdir(dirname($path), 0o777, true);
        file_put_contents($path, '{"env":{},"hooks":{"PreToolUse":[]},"permissions":{}}');

        (new HostPolicyProjector($this->root))->sync('claude');

        $raw = file_get_contents($path);
        self::assertIsString($raw);
        // Associative decoding would round-trip project-owned {} back out as [].
        self::assertStringContainsString('"env": {}', $raw);
        self::assertStringContainsString('"PreToolUse": []', $raw);
    }

    public function testOpenCodeJsoncIsReportedAsManualInsteadOfDestroyingComments(): void
    {
        file_put_contents($this->root . '/opencode.jsonc', "{\n  // project comment\n  \"permission\": {}\n}\n");
        $projector = new HostPolicyProjector($this->root);

        $status = $projector->inspect('opencode');
        self::assertSame('manual', $status['status']);
        self::assertStringContainsString('refuses to rewrite comment-bearing JSONC', $status['detail']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('refusing to rewrite comment-bearing JSONC');
        $projector->sync('opencode');
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $path): array
    {
        $content = file_get_contents($path);
        self::assertIsString($content);
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
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
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
