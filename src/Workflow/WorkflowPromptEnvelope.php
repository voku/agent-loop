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
    public const string SCHEMA_VERSION = '1.1';

    /** @var array{kind: 'checkpoint', id: string, title: string}|null */
    public ?array $continuityAnchor;

    /** @var array<string, array<string, mixed>> */
    public array $references;

    /** @var list<array{code: string, owner: string, message: string}> */
    public array $disagreements;

    public string $digest;

    /**
     * @param string $mode Runtime input is validated below; keep the public guard real instead of narrowing it away in PHPDoc.
     * @param array<string, mixed>|null $continuityAnchor Runtime input is validated below before it becomes the narrow public checkpoint shape.
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
        public ?string $goal = null,
        ?array $continuityAnchor = null,
        array $references = [],
        array $disagreements = [],
    ) {
        if (!in_array($mode, [self::MODE_START, self::MODE_CONTINUE], true)) {
            throw new InvalidArgumentException('unsupported workflow prompt envelope mode: ' . $mode);
        }
        if (trim($content) === '') {
            throw new InvalidArgumentException('workflow prompt envelope content must not be empty');
        }
        if ($goal !== null && trim($goal) === '') {
            throw new InvalidArgumentException('workflow prompt envelope goal must be non-empty when present');
        }

        $this->continuityAnchor = self::snapshotContinuityAnchor($continuityAnchor);
        $this->references = self::snapshotReferences($references);
        $this->disagreements = self::snapshotDisagreements($disagreements);
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
            'goal' => $this->goal,
            'continuity_anchor' => $this->continuityAnchor,
            'references' => $this->references,
            'disagreements' => $this->disagreements,
        ];
    }

    /**
     * @param array<string, mixed>|null $continuityAnchor
     * @return array{kind: 'checkpoint', id: string, title: string}|null
     */
    private static function snapshotContinuityAnchor(?array $continuityAnchor): ?array
    {
        if ($continuityAnchor === null) {
            return null;
        }
        if (
            ($continuityAnchor['kind'] ?? null) !== 'checkpoint'
            || !isset($continuityAnchor['id'], $continuityAnchor['title'])
            || !is_string($continuityAnchor['id'])
            || !is_string($continuityAnchor['title'])
            || trim($continuityAnchor['id']) === ''
            || trim($continuityAnchor['title']) === ''
        ) {
            throw new InvalidArgumentException('workflow prompt envelope continuity anchor must be a non-empty checkpoint id/title');
        }

        return [
            'kind' => 'checkpoint',
            'id' => $continuityAnchor['id'],
            'title' => $continuityAnchor['title'],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $references
     * @return array<string, array<string, mixed>>
     */
    private static function snapshotReferences(array $references): array
    {
        $snapshot = [];
        foreach ($references as $key => $reference) {
            $snapshot[$key] = self::snapshotJsonArray($reference);
        }

        return $snapshot;
    }

    /**
     * @param list<array{code: string, owner: string, message: string}> $disagreements
     * @return list<array{code: string, owner: string, message: string}>
     */
    private static function snapshotDisagreements(array $disagreements): array
    {
        $snapshot = [];
        foreach ($disagreements as $disagreement) {
            $snapshot[] = [
                'code' => $disagreement['code'],
                'owner' => $disagreement['owner'],
                'message' => $disagreement['message'],
            ];
        }

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private static function snapshotJsonArray(array $value): array
    {
        $snapshot = [];
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $snapshot[$key] = self::snapshotNestedArray($item);
                continue;
            }
            if ($item === null || is_bool($item) || is_int($item) || is_float($item) || is_string($item)) {
                $snapshot[$key] = $item;
                continue;
            }

            throw new InvalidArgumentException('workflow prompt envelope provenance must contain JSON-compatible scalar/array values only');
        }

        return $snapshot;
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function snapshotNestedArray(array $value): array
    {
        $snapshot = [];
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $snapshot[$key] = self::snapshotNestedArray($item);
                continue;
            }
            if ($item === null || is_bool($item) || is_int($item) || is_float($item) || is_string($item)) {
                $snapshot[$key] = $item;
                continue;
            }

            throw new InvalidArgumentException('workflow prompt envelope provenance must contain JSON-compatible scalar/array values only');
        }

        return $snapshot;
    }
}
