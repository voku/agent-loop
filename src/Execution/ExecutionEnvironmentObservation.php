<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use InvalidArgumentException;

final readonly class ExecutionEnvironmentObservation
{
    private const int MAX_TOOLS = 16;

    /**
     * @param list<ExecutionEnvironmentTool> $tools
     */
    public function __construct(
        public string $taskId,
        public string $runId,
        public int $contractRevision,
        public string $executionPlanDigest,
        public string $stageId,
        public int $attempt,
        public string $candidateRevision,
        public string $hostId,
        public array $tools = [],
        public ?bool $networkAvailable = null,
        public ?bool $remoteWriteAvailable = null,
    ) {
        if ($this->taskId === '' || $this->runId === '' || $this->stageId === '' || $this->candidateRevision === '') {
            throw new InvalidArgumentException('Execution environment observation binding values must be non-empty.');
        }
        if ($this->contractRevision < 1 || $this->attempt < 1) {
            throw new InvalidArgumentException('Execution environment observation revisions and attempts must be positive.');
        }
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $this->executionPlanDigest) !== 1) {
            throw new InvalidArgumentException('Execution environment observation requires a canonical execution plan digest.');
        }
        if (preg_match('/^[a-z][a-z0-9._-]{0,63}$/', $this->hostId) !== 1) {
            throw new InvalidArgumentException('Execution environment host id must match [a-z][a-z0-9._-]{0,63}.');
        }
        if (count($this->tools) > self::MAX_TOOLS) {
            throw new InvalidArgumentException('Execution environment observation exceeds the bounded tool limit.');
        }

        $seen = [];
        foreach ($this->tools as $tool) {
            if (isset($seen[$tool->id])) {
                throw new InvalidArgumentException('Execution environment observation contains duplicate tool ids.');
            }
            $seen[$tool->id] = true;
        }
    }

    /**
     * @return array{
     *     task_id: string,
     *     run_id: string,
     *     contract_revision: int,
     *     execution_plan_digest: string,
     *     stage_id: string,
     *     attempt: int,
     *     candidate_revision: string,
     *     host_id: string,
     *     tools: list<array{id: string, available: bool, version: string|null}>,
     *     network_available: bool|null,
     *     remote_write_available: bool|null
     * }
     */
    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'run_id' => $this->runId,
            'contract_revision' => $this->contractRevision,
            'execution_plan_digest' => $this->executionPlanDigest,
            'stage_id' => $this->stageId,
            'attempt' => $this->attempt,
            'candidate_revision' => $this->candidateRevision,
            'host_id' => $this->hostId,
            'tools' => array_map(
                static fn (ExecutionEnvironmentTool $tool): array => $tool->toArray(),
                $this->tools,
            ),
            'network_available' => $this->networkAvailable,
            'remote_write_available' => $this->remoteWriteAvailable,
        ];
    }

    /** @return non-empty-string */
    public function digest(): string
    {
        $json = json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return 'sha256:' . hash('sha256', $json);
    }
}
