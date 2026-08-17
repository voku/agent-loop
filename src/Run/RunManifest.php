<?php

declare(strict_types=1);

namespace voku\AgentLoop\Run;

use LogicException;

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

    public string $state;

    public string $nextAction;

    public RunPolicyEvaluation $policy;

    /**
     * @param 'ephemeral'|'governed'|'planned'|'legacy_inferred' $mode
     * @param 'blocked'|'experiment'|'incomplete'|'ready_to_close'|'complete' $state
     * @param array<string, array<string, mixed>> $references
     * @param list<array{code: string, owner: string, message: string}> $disagreements
     */
    public function __construct(
        public string $taskId,
        public string $runId,
        public string $mode,
        string $state,
        public array $references,
        public array $disagreements,
        string $nextAction,
    ) {
        $this->policy = (new RunPolicyEvaluator())->evaluate($taskId, $mode, $references, $disagreements);
        if ($this->policy->state !== $state || $this->policy->nextAction !== $nextAction) {
            throw new LogicException(sprintf(
                'Lifecycle policy disagrees with legacy manifest derivation: state %s/%s, next action %s/%s.',
                $this->policy->state,
                $state,
                $this->policy->nextAction,
                $nextAction,
            ));
        }

        $this->state = $this->policy->state;
        $this->nextAction = $this->policy->nextAction;
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
