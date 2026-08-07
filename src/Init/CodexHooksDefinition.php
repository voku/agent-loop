<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

final readonly class CodexHooksDefinition
{
    private const string CLIENT_DIRECTORY = '.codex';

    /** @var list<string> */
    private const array REQUIRED_EVENTS = ['SessionStart', 'SubagentStart', 'PreToolUse'];

    private function __construct(private HooksDefinition $definition)
    {
    }

    public static function fromRoot(string $hooksRoot): self
    {
        return new self(HooksDefinition::fromRoot($hooksRoot, self::CLIENT_DIRECTORY, self::REQUIRED_EVENTS));
    }

    /** @return list<string> */
    public function scriptNames(): array
    {
        return $this->definition->scriptNames();
    }

    public function hooksJsonContent(): string
    {
        return $this->definition->hooksJsonContent();
    }

    /** @return list<string> */
    public static function validationErrors(string $hooksRoot): array
    {
        return HooksDefinition::validationErrors($hooksRoot, self::CLIENT_DIRECTORY, self::REQUIRED_EVENTS);
    }
}
