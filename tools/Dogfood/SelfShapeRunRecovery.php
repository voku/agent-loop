<?php

declare(strict_types=1);

namespace voku\AgentLoop\Dogfood;

use RuntimeException;
use voku\AgentSession\Session;

final class SelfShapeRunRecovery
{
    /** @param list<Session> $sessions */
    public function sessionToDrop(array $sessions, string $taskId, string $planner): ?string
    {
        $active = array_values(array_filter(
            $sessions,
            static fn (Session $session): bool => $session->taskId === $taskId && !$session->status->isClosed(),
        ));

        if (count($active) > 1) {
            throw new RuntimeException('Self-shape recovery found multiple active Sessions for ' . $taskId . '.');
        }
        if ($active === []) {
            return null;
        }

        $session = $active[0];
        if ($session->claimedBy !== $planner) {
            throw new RuntimeException(sprintf(
                'Refusing to drop active self-shape Session %s claimed by %s; expected %s.',
                $session->id,
                $session->claimedBy ?? 'nobody',
                $planner,
            ));
        }

        return $session->id;
    }
}
