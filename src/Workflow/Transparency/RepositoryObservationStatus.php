<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow\Transparency;

/**
 * Why repository observation is or is not available.
 *
 * "Unavailable" is a fact worth reporting. A projection that answered "no
 * changed files" for a missing base commit would let an unobservable task look
 * like a finished one.
 */
enum RepositoryObservationStatus: string
{
    case OBSERVED = 'observed';
    case NO_CONTRACT = 'no_contract';
    case NO_BASE_COMMIT = 'no_base_commit';
    case PROJECT_ROOT_UNAVAILABLE = 'project_root_unavailable';
    case NOT_A_GIT_WORK_TREE = 'not_a_git_work_tree';
    case BASE_COMMIT_UNKNOWN = 'base_commit_unknown';
    case GIT_FAILED = 'git_failed';

    public function isObserved(): bool
    {
        return $this === self::OBSERVED;
    }
}
