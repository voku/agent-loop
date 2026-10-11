<?php

declare(strict_types=1);

namespace voku\AgentLoop\Dogfood;

/**
 * Read-only GitHub API boundary. A trusted caller supplies the transport;
 * tests must not require network access or an arbitrary GitHub credential.
 */
interface SelfShapeMaintenanceGitHubApi
{
    /** @return mixed */
    public function request(string $endpoint, bool $paginate = false): mixed;
}
