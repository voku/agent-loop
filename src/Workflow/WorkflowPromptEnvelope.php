<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use voku\AgentLoop\Run\CanonicalJson;

/**
 * Immutable, digest-bearing workflow-owned prompt projection.
 *
 * Nested provenance values are snapshotted and restricted to JSON-compatible
 * scalars/arrays so the serialized payload cannot drift after digest creation.
 */
final readonly class WorkflowPromptEnvelope
{
    public const string MODE_CONTINUE = 'continue';
    public const string MODE_START = 'start';
    public const string SCHEMA_VERSION = '1.0';

    /** @var array<string, array<string, mixed>> */
    public array $references;

    /** @var list<array{code: string, owner: string, message: string}> */
    public array $disagreements;

    public string $digest;

    /**
     * @param string $mode Runtime input is validated below; keep the public guard real instead of narrowing it away in PHPDoc.
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
        public ?int $contractRevision = null,
        public ?string $recallCompilationId = null,
        public ?string $recallBundleSha256 = null,
        array $references = [],
        array $disagreements = [],
    ) {
        if (!in_array($mode, [self::MODE_START, self::MODE_CONTINUE], true)) {
            throw new InvalidArgumentException('unsupported workflow prompt envelope mode: ' . $mode);
        }
        if (trim($content) === '') {
            throw new InvalidArgumentException('workflow prompt envelope content must not be empty');
        }

        $this->references = self::snapshotArray($references);
        $this->disagreements = self::snapshotArray($disagreements);
        $this->digest = hash('sha256', CanonicalJson::pretty($this->payload()));
    }

    /**
     * Return the canonical host-facing envelope including its provenance digest.
     *
     * @return array<string, mixed>
     */
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
            'contract_revision' => $this->contractRevision,
            'recall_compilation_id' => $this->recallCompilationId,
            'recall_bundle_sha256' => $this->recallBundleSha256,
            'references' => $this->references,
            'disagreements' => $this->disagreements,
        ];
    }

    /**
     * @template T of array<array-key, mixed>
     * @param T $value
     * @return T
     */
    private static function snapshotArray(array $value): array
    {
        $snapshot = $value;
        foreach ($snapshot as $key => $item) {
            if (is_array($item)) {
                $snapshot[$key] = self::snapshotArray($item);
                continue;
            }
            if ($item === null || is_bool($item) || is_int($item) || is_float($item) || is_string($item)) {
                continue;
            }

            throw new InvalidArgumentException('workflow prompt envelope provenance must contain JSON-compatible scalar/array values only');
        }

        return $snapshot;
    }
}
