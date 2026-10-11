<?php

declare(strict_types=1);

namespace voku\AgentLoop\Dogfood;

use JsonException;
use RuntimeException;

/** Read-only GitHub REST requests via gh with credentials from GH_TOKEN. */
final readonly class SelfShapeMaintenanceGitHubCli implements SelfShapeMaintenanceGitHubApi
{
    public function __construct(private ProcessRunner $runner)
    {
    }

    public function request(string $endpoint, bool $paginate = false): mixed
    {
        $args = $paginate
            ? ['gh', 'api', '--paginate', '--slurp', $endpoint]
            : ['gh', 'api', $endpoint];
        $result = $this->runner->run($args);
        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('Unable to independently verify GitHub API evidence (' . $endpoint . '): '
                . trim($result['stderr']));
        }

        try {
            return json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Malformed GitHub API response (' . $endpoint . ').', 0, $exception);
        }
    }
}
