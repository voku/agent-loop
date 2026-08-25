<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow\Transparency;

/**
 * The distinctions a host must be able to render without re-deriving them.
 */
enum TransparencyCategory: string
{
    case CHANGED_IN_SCOPE = 'changed_in_scope';
    case CHANGED_OUTSIDE_SCOPE = 'changed_outside_scope';
    case CONTRACT_NON_GOAL = 'contract_non_goal';
    case CONTEXT_SKIPPED = 'context_skipped';
    case CONTEXT_OMITTED = 'context_omitted';
    case REVIEW_FINDING = 'review_finding';
    case BLOCKED = 'blocked';
    case FUTURE_WORK_DEFERRED = 'future_work_deferred';
    case UNKNOWN = 'unknown';

    public function provenance(): TransparencyProvenance
    {
        return match ($this) {
            self::CHANGED_IN_SCOPE, self::CHANGED_OUTSIDE_SCOPE => TransparencyProvenance::REPOSITORY_OBSERVATION,
            self::CONTRACT_NON_GOAL => TransparencyProvenance::WORKFLOW_AUTHORITY,
            self::CONTEXT_SKIPPED, self::CONTEXT_OMITTED => TransparencyProvenance::CONTEXT_CONSTRUCTION,
            self::REVIEW_FINDING => TransparencyProvenance::REVIEW_EVIDENCE,
            self::BLOCKED => TransparencyProvenance::OWNER_EVIDENCE,
            self::FUTURE_WORK_DEFERRED => TransparencyProvenance::DELIBERATE_BOUNDARY,
            self::UNKNOWN => TransparencyProvenance::CONTEXT_CONSTRUCTION,
        };
    }
}
