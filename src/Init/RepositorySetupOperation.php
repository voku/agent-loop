<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

/**
 * A repository-owned setup operation a host may legally offer right now.
 *
 * Hosts render these identifiers; they do not infer them from combinations of
 * status fields. That inference is exactly where a UI starts owning setup
 * semantics, and where an "Install" button appears for a repository that would
 * refuse the install.
 *
 * HOST_USER_ACTION and REVIEW_CONFLICT are not repository operations at all:
 * they mark situations only a person can resolve, so a UI can show them
 * without offering a button that would lie.
 */
enum RepositorySetupOperation: string
{
    case INSTALL_ASSETS = 'install_assets';
    case UPDATE_ASSETS = 'update_assets';
    case REMOVE_ASSETS = 'remove_assets';
    case SYNC_POLICY = 'sync_policy';
    case SYNC_GIT_INTEGRATION = 'sync_git_integration';
    case REVIEW_CONFLICT = 'review_conflict';
    case HOST_USER_ACTION = 'host_user_action';

    /** Whether invoking this operation writes to the repository. */
    public function mutatesRepository(): bool
    {
        return match ($this) {
            self::INSTALL_ASSETS,
            self::UPDATE_ASSETS,
            self::REMOVE_ASSETS,
            self::SYNC_POLICY,
            self::SYNC_GIT_INTEGRATION => true,
            self::REVIEW_CONFLICT, self::HOST_USER_ACTION => false,
        };
    }

    /** Whether a host must confirm an owner-issued plan identity before invoking. */
    public function requiresPlanConfirmation(): bool
    {
        return $this === self::REMOVE_ASSETS;
    }
}
