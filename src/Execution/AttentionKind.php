<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

enum AttentionKind: string
{
    case APPROVAL_REQUIRED = 'approval_required';
    case CLARIFICATION_REQUIRED = 'clarification_required';
    case CONTRACT_CHANGED = 'contract_changed';
    case REVIEW_CHANGES_REQUIRED = 'review_changes_required';
    case VALIDATION_CONFLICT = 'validation_conflict';
    case RUNNER_FAILED = 'runner_failed';
}
