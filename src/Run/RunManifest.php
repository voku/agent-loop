<?php

declare(strict_types=1);

namespace voku\AgentLoop\Run;

/**
 * A task-scoped projection of the owning package artifacts that form one run.
 *
 * The manifest is deliberately not authoritative. Every reference points back
 * to the package artifact that owns the state, and consumers must re-project or
 * verify those references before treating the stored snapshot as current.
 */
final readonly class RunManifest
{
    public const string SCHEMA_VERSION = '1.0';

    /**
     * @param array<string, array<string, mixed>> $references
     * @param list<array{code: string, owner: string, message: string}> $disagreements
     */
    public function __construct(
        public string $taskId,
        public string $runId,
        public string $mode,
        public string $state,
        public array $references,
        public array $disagreements,
        public string $nextAction,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task_id' => $this->taskId,
            'run_id' => $this->runId,
            'mode' => $this->mode,
            'state' => $this->state,
            'references' => $this->references,
            'disagreements' => $this->disagreements,
            'next_action' => $this->nextAction,
        ];
    }

    public function toJson(): string
    {
        return CanonicalJson::pretty($this->toArray());
    }
}
