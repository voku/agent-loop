<?php

declare(strict_types=1);

namespace voku\AgentLoop\AgentGuidance;

use InvalidArgumentException;

/** Behavioral preference for one host session; it never conveys authority. */
final readonly class InteractionPreference
{
    /** @param array<int|string, string> $taskOverrides */
    public function __construct(
        public string $sessionId,
        public string $defaultMode,
        public array $taskOverrides = [],
    ) {
        if (trim($sessionId) === '') {
            throw new InvalidArgumentException('A host session identity is required.');
        }
        self::validateMode($defaultMode);
        foreach ($taskOverrides as $taskId => $mode) {
            if (trim((string) $taskId) === '') {
                throw new InvalidArgumentException('A task override requires a task identity.');
            }
            self::validateMode($mode);
        }
    }

    public function modeFor(?string $taskId): string
    {
        return $taskId !== null && $taskId !== ''
            ? ($this->taskOverrides[$taskId] ?? $this->defaultMode)
            : $this->defaultMode;
    }

    private static function validateMode(string $mode): void
    {
        if (!in_array($mode, ['collaborative', 'autonomous'], true)) {
            throw new InvalidArgumentException('Interaction mode must be collaborative or autonomous.');
        }
    }
}
