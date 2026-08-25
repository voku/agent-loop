<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

/**
 * What a typed repository-setup diagnostic is about.
 *
 * Hosts group and filter on this instead of matching substrings in a rendered
 * message, so a wording change stays a wording change.
 */
enum RepositorySetupDiagnosticKind: string
{
    case PHP_RUNTIME = 'php_runtime';
    case COMPOSER = 'composer';
    case GIT = 'git';
    case GIT_INTEGRATION = 'git_integration';
    case MAKE = 'make';
    case SOURCE_ASSETS = 'source_assets';
    case HOST_RUNTIME = 'host_runtime';
    case HOST_CAPABILITY = 'host_capability';
    case MANAGED_ASSET_DRIFT = 'managed_asset_drift';
    case OPTIONAL_HOOKS = 'optional_hooks';
    case INTEGRATION_CONFLICT = 'integration_conflict';
    case WORKFLOW_BOUNDARY = 'workflow_boundary';
}
