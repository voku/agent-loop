<?php

declare(strict_types=1);

namespace voku\AgentLoop\Run;

final readonly class GovernedRun
{
    /**
     * @param array{path: string, sha256: string} $contractSource
     * @param string                              $learningRoot the durable Learning repository this Run is governed
     *                                                          against, stored root-relative where possible so the
     *                                                          Run stays portable and self-describing
     */
    public function __construct(
        public string $runId,
        public string $taskId,
        public int $contractRevision,
        public array $contractSource,
        public string $sessionId,
        public string $learningRoot,
        public string $preparedAt,
        public string $path,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => '1.0',
            'kind' => 'governed_run',
            'run_id' => $this->runId,
            'task_id' => $this->taskId,
            'contract_revision' => $this->contractRevision,
            'contract_source' => $this->contractSource,
            'session_id' => $this->sessionId,
            'learning_root' => $this->learningRoot,
            'prepared_at' => $this->preparedAt,
        ];
    }
}
