<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

use JsonException;
use RuntimeException;
use voku\AgentRecallCompiler\Verification\VerificationPlanReader;

/**
 * The result file's binding to one exact `verification-plan.json`.
 *
 * The hash is taken over the plan file's bytes, matching what `voku/agent-recall-compiler` records
 * as `verification_plan_sha256` in `meta.json`. That is what lets a verifier reject an answer sheet
 * written against a plan that has since been recompiled, instead of grading yesterday's questions.
 *
 * The plan is read through the Recall owner's `VerificationPlanReader`. A compilation that resolved no
 * map target emits no plan; that is a legitimate state, so the binding is empty and the answer slots
 * with it. A plan the owner reader rejects (unreachable, malformed, or without the writer's shape) also
 * yields an empty binding here, because this runs after the edit was applied and must not fail it.
 * That is not acceptance: the binding then carries no plan hash, so `edit verify` (whose
 * `VerificationBundle` reads the same plan strictly) refuses the result instead of grading it.
 */
final readonly class VerificationPlanBinding
{
    /**
     * @param list<string> $probeIds
     * @param list<string> $checklistIds
     */
    private function __construct(
        public ?string $planSha256,
        public array $probeIds,
        public array $checklistIds,
        public ?string $error = null,
    ) {
    }

    public static function absent(): self
    {
        return new self(null, [], []);
    }

    public static function fromRecallDirectory(?string $recallDirectory): self
    {
        if ($recallDirectory === null || $recallDirectory === '') {
            return self::absent();
        }

        try {
            $plan = (new VerificationPlanReader())->read($recallDirectory);
        } catch (RuntimeException $exception) {
            return new self(null, [], [], self::safeReaderError($exception));
        }
        if ($plan === null) {
            return self::absent();
        }

        return new self($plan->sha256, $plan->probeIds, $plan->checklistIds);
    }

    /** Reader messages can include plan values and paths; persist only fixed diagnostics. */
    private static function safeReaderError(RuntimeException $exception): string
    {
        $message = $exception->getMessage();
        $fixed = [
            'Verification plan is not a regular file:' => 'Verification plan is not a regular file',
            'Verification plan is not readable:' => 'Verification plan is not readable',
            'Unable to read verification plan:' => 'Unable to read verification plan',
            'Verification plan JSON must decode to an object:' => 'Verification plan JSON must decode to an object',
            'Verification plan analysis_fingerprint must be an object or null:' => 'Verification plan analysis_fingerprint must be an object or null',
            'Verification plan required_evidence_types must be a non-empty JSON array:' => 'Verification plan required_evidence_types must be a non-empty JSON array',
            'Verification plan required_evidence_types must contain only non-empty strings:' => 'Verification plan required_evidence_types must contain only non-empty strings',
            'Verification plan generator must be an object:' => 'Verification plan generator must be an object',
            'Cannot establish verification plan absence: output directory does not exist or cannot be reached:' => 'Cannot establish verification plan absence: output directory does not exist or cannot be reached',
            'Cannot establish verification plan absence: output directory cannot be listed:' => 'Cannot establish verification plan absence: output directory cannot be listed',
            'Verification plan is a symbolic link whose target cannot be reached:' => 'Verification plan is a symbolic link whose target cannot be reached',
            'Verification plan exists but cannot be reached:' => 'Verification plan exists but cannot be reached',
        ];
        foreach ($fixed as $prefix => $diagnostic) {
            if (str_starts_with($message, $prefix)) {
                return $diagnostic;
            }
        }
        foreach (['map_digest', 'task_id', 'target'] as $field) {
            $prefix = 'Verification plan ' . $field . ' must be a non-empty string:';
            if (str_starts_with($message, $prefix)) {
                return rtrim($prefix, ':');
            }
        }
        foreach (['name', 'version', 'seed_sha256'] as $field) {
            $prefix = 'Verification plan generator.' . $field . ' must be a string:';
            if (str_starts_with($message, $prefix)) {
                return rtrim($prefix, ':');
            }
        }
        foreach (['knowledge_probes', 'checklist', 'objective_gates', 'omitted_probe_candidates'] as $field) {
            if (str_starts_with($message, 'Verification plan ' . $field . ' must be a JSON array:')) {
                return 'Verification plan ' . $field . ' must be a JSON array';
            }
            if (str_starts_with($message, 'Verification plan ' . $field . ' has duplicate id ')) {
                return 'Verification plan ' . $field . ' has a duplicate id';
            }
            if (preg_match('/^Verification plan ' . preg_quote($field, '/') . '\\[[0-9]+\\] must be an object with a non-empty string id:/', $message) === 1) {
                return 'Verification plan ' . $field . ' contains an entry without a non-empty string id';
            }
            if (preg_match('/^Verification plan ' . preg_quote($field, '/') . '\\[[0-9]+\\] must be an object:/', $message) === 1) {
                return 'Verification plan ' . $field . ' contains a non-object entry';
            }
        }
        if (str_starts_with($message, 'Unsupported verification plan schema_version ')) {
            return 'Unsupported verification plan schema version';
        }
        if (str_starts_with($message, 'Invalid verification plan JSON ')) {
            $cause = $exception->getPrevious();
            $reason = $cause instanceof JsonException ? match ($cause->getCode()) {
                JSON_ERROR_DEPTH => 'maximum stack depth exceeded',
                JSON_ERROR_STATE_MISMATCH => 'invalid or malformed JSON state',
                JSON_ERROR_CTRL_CHAR => 'unexpected control character',
                JSON_ERROR_SYNTAX => 'syntax error',
                JSON_ERROR_UTF8 => 'malformed UTF-8',
                JSON_ERROR_UTF16 => 'malformed UTF-16',
                default => 'invalid JSON',
            } : 'invalid JSON';

            return 'Invalid verification plan JSON: ' . $reason;
        }

        return 'Verification plan is unreadable or invalid';
    }

    /** @return array<string, list<string>> */
    public function emptyProbeAnswers(): array
    {
        return self::slots($this->probeIds);
    }

    /** @return array<string, list<string>> */
    public function emptyChecklistEvidence(): array
    {
        return self::slots($this->checklistIds);
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, list<string>>
     */
    private static function slots(array $ids): array
    {
        $slots = [];
        foreach ($ids as $id) {
            $slots[$id] = [];
        }
        ksort($slots, SORT_STRING);

        return $slots;
    }
}
