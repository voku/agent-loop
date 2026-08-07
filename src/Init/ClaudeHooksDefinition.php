<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

final readonly class ClaudeHooksDefinition
{
    private const string CLIENT_DIRECTORY = '.claude';

    private function __construct(private HooksDefinition $definition)
    {
    }

    public static function fromRoot(string $hooksRoot): self
    {
        return new self(HooksDefinition::fromRoot($hooksRoot, self::CLIENT_DIRECTORY));
    }

    /** @return list<string> */
    public function scriptNames(): array
    {
        return $this->definition->scriptNames();
    }

    /**
     * Claude Code registers hooks inside `settings.json`, so the bundle's `hooks`
     * object is merged into that file instead of being copied as `hooks.json`.
     *
     * @return array<string, mixed>
     */
    public function hooksObject(): array
    {
        return $this->definition->hooksObject();
    }

    /**
     * Claude Code has no required hook event: a bundle may register only
     * `PreToolUse` guardrails without a session-context hook.
     *
     * @return list<string>
     */
    public static function validationErrors(string $hooksRoot): array
    {
        return HooksDefinition::validationErrors($hooksRoot, self::CLIENT_DIRECTORY);
    }
}
