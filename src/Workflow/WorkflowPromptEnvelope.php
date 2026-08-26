<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use voku\AgentLoop\Run\CanonicalJson;

final readonly class WorkflowPromptEnvelope
{
    public const string MODE_CONTINUE = 'continue';
    public const string MODE_START = 'start';
    public const string SCHEMA_VERSION = '1.0';

    public string $digest;

    /**
     * @param self::MODE_* $mode
     * @param array<string, array<string, mixed>> $references
     * @param list<array{code: string, owner: string, message: string}> $disagreements
     */
    public function __construct(
        public string $mode,
        public string $taskId,
        public string $content,
        public bool $mutationAllowed,
        public ?string $runId,
        public ?string $state,
        public ?string $nextAction,
        public ?string $nextActionKind,
        public array $references = [],
        public array $disagreements = [],
    ) {
        if (!in_array($mode, [self::MODE_START, self::MODE_CONTINUE], true)) {
            throw new InvalidArgumentException('unsupported workflow prompt envelope mode: ' . $mode);
        }
        if (trim($content) === '') {
            throw new InvalidArgumentException('workflow prompt envelope content must not be empty');
        }

        $this->digest = hash('sha256', CanonicalJson::pretty($this->payload()));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->payload() + ['digest' => 'sha256:' . $this->digest];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => $this->mode,
            'task_id' => $this->taskId,
            'content' => $this->content,
            'mutation_allowed' => $this->mutationAllowed,
            'run_id' => $this->runId,
            'state' => $this->state,
            'next_action' => $this->nextAction,
            'next_action_kind' => $this->nextActionKind,
            'references' => $this->references,
            'disagreements' => $this->disagreements,
        ];
    }
}
