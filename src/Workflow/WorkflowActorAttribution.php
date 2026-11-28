<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use RuntimeException;

/** Validates actor labels before a workflow artifact persists them as attribution. */
final class WorkflowActorAttribution
{
    /**
     * The only authority_evidence status any input path can produce. The
     * recorder named the decision-maker; nothing authenticated either of them.
     * A `verified` status is reserved for a later trusted issuance path.
     */
    public const string SELF_ASSERTED = 'self_asserted';

    public static function normalize(string $actor, string $action): string
    {
        $actor = trim($actor);
        if ($actor === '') {
            throw new RuntimeException('A non-empty actor is required to ' . $action . '.');
        }
        if (str_starts_with($actor, 'standing:')) {
            throw new RuntimeException('The standing: actor prefix is reserved and cannot ' . $action . '.');
        }

        return $actor;
    }

    /** Validate an optional `--recorded-by` value; null means no separate recorder is declared. */
    public static function recorder(?string $recordedBy): ?string
    {
        return $recordedBy === null ? null : self::normalize($recordedBy, 'record a decision');
    }

    /**
     * Additive record fields for a declared recorder; empty when none is
     * declared, so records written without one stay byte-identical.
     *
     * @return array{}|array{recorded_by: string, authority_evidence: array{status: string}}
     */
    public static function recorderFields(?string $recordedBy): array
    {
        return $recordedBy === null ? [] : [
            'recorded_by' => $recordedBy,
            'authority_evidence' => ['status' => self::SELF_ASSERTED],
        ];
    }

    /**
     * Read the recorder fields from a persisted decision record, failing closed
     * on anything this slice cannot have written.
     *
     * @param array<array-key, mixed> $data
     */
    public static function recorderFromRecord(array $data, string $path): ?string
    {
        $hasRecorder = array_key_exists('recorded_by', $data);
        $hasEvidence = array_key_exists('authority_evidence', $data);
        if (!$hasRecorder && !$hasEvidence) {
            return null;
        }
        if (!$hasRecorder || !$hasEvidence) {
            throw new RuntimeException('Decision record has recorded_by and authority_evidence only together in ' . $path . '.');
        }
        $evidence = $data['authority_evidence'];
        if (!is_array($evidence) || array_keys($evidence) !== ['status'] || $evidence['status'] !== self::SELF_ASSERTED) {
            throw new RuntimeException('Decision record has an unsupported authority_evidence in ' . $path . '; only self_asserted can be recorded.');
        }
        $recordedBy = $data['recorded_by'];
        if (!is_string($recordedBy) || $recordedBy !== trim($recordedBy)) {
            throw new RuntimeException('Decision record has a malformed recorded_by in ' . $path . '.');
        }
        try {
            return self::normalize($recordedBy, 'record a decision');
        } catch (RuntimeException $exception) {
            throw new RuntimeException('Decision record has a malformed recorded_by in ' . $path . ': ' . $exception->getMessage(), 0, $exception);
        }
    }
}
