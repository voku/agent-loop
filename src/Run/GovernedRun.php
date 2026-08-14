<?php

declare(strict_types=1);

namespace voku\AgentLoop\Run;

use voku\AgentLoop\PathResolver;
use voku\AgentLoop\ProjectLayout;

final readonly class GovernedRun
{
    /**
     * @param array{path: string, sha256: string} $contractSource
     * @param string|null $learningRoot project-relative location of the durable Learning repository this Run is
     *                                   governed against, or null when that repository lives outside the project and
     *                                   its location is therefore owned by project configuration.
     *
     *                                   Never an absolute path. A checkout sits at a different absolute path on every
     *                                   machine, so baking one in would make a completed Run unexplainable after a
     *                                   clone — durable truth that stops being true when the directory moves.
     */
    public function __construct(
        public string $runId,
        public string $taskId,
        public int $contractRevision,
        public array $contractSource,
        public string $sessionId,
        public ?string $learningRoot,
        public string $preparedAt,
        public string $path,
    ) {
    }

    /** Resolve the durable Learning repository this Run was prepared against. */
    public function learningRoot(string $rootPath): string
    {
        return $this->learningRoot === null
            ? (new ProjectLayout($rootPath))->learningRoot()
            : PathResolver::join($rootPath, $this->learningRoot);
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
