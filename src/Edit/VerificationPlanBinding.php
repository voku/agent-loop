<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

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
        } catch (RuntimeException) {
            return self::absent();
        }
        if ($plan === null) {
            return self::absent();
        }

        return new self($plan->sha256, $plan->probeIds, $plan->checklistIds);
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
