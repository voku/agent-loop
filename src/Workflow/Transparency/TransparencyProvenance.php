<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow\Transparency;

/**
 * Which kind of truth an item is.
 *
 * The projection refuses to collapse these into one generic status. A host that
 * cannot tell an approved boundary from a worktree observation will eventually
 * render one as the other.
 */
enum TransparencyProvenance: string
{
    /** The approved Contract. Binding. */
    case WORKFLOW_AUTHORITY = 'workflow_authority';

    /** Durable owner records: validation, verification, acknowledgement. */
    case OWNER_EVIDENCE = 'owner_evidence';

    /** Current Git state. Never proof that work is correct or complete. */
    case REPOSITORY_OBSERVATION = 'repository_observation';

    /** How context was assembled, not what was implemented. */
    case CONTEXT_CONSTRUCTION = 'context_construction';

    /** Findings from the exact persisted review report. Not human judgment. */
    case REVIEW_EVIDENCE = 'review_evidence';

    /** A boundary or defer a human actually recorded. */
    case DELIBERATE_BOUNDARY = 'deliberate_boundary';
}
