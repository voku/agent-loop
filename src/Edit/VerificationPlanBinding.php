<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

use JsonException;

/**
 * The result file's binding to one exact `verification-plan.json`.
 *
 * The hash is taken over the plan file's bytes, matching what `voku/agent-recall-compiler` records
 * as `verification_plan_sha256` in `meta.json`. That is what lets a verifier reject an answer sheet
 * written against a plan that has since been recompiled, instead of grading yesterday's questions.
 *
 * A compilation that resolved no map target emits no plan; that is a legitimate state, not an
 * error, so the binding is simply empty and the answer slots with it.
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

        $planFile = rtrim($recallDirectory, '/') . '/verification-plan.json';
        if (!is_file($planFile)) {
            return self::absent();
        }

        $raw = file_get_contents($planFile);
        if (!is_string($raw)) {
            return self::absent();
        }

        try {
            $plan = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::absent();
        }
        if (!is_array($plan)) {
            return self::absent();
        }

        return new self(
            'sha256:' . hash('sha256', $raw),
            self::ids($plan['knowledge_probes'] ?? null),
            self::ids($plan['checklist'] ?? null),
        );
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
     * @return list<string>
     */
    private static function ids(mixed $entries): array
    {
        if (!is_array($entries)) {
            return [];
        }

        $ids = [];
        foreach ($entries as $entry) {
            if (is_array($entry) && is_string($entry['id'] ?? null) && $entry['id'] !== '') {
                $ids[] = $entry['id'];
            }
        }

        return $ids;
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
