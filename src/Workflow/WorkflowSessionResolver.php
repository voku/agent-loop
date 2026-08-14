<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use RuntimeException;
use voku\AgentLoop\ProjectLayout;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStore;

final readonly class WorkflowSessionResolver
{
    public function __construct(private string $rootPath)
    {
    }

    public function active(string $taskId): ?Session
    {
        $root = (new ProjectLayout($this->rootPath))->sessionsRoot();
        if (!is_dir($root)) {
            return null;
        }

        $sessions = array_values(array_filter(
            (new SessionStore())->all($root),
            static fn (Session $session): bool => $session->taskId === $taskId && !$session->status->isClosed(),
        ));
        if (count($sessions) > 1) {
            throw new RuntimeException('Multiple active Sessions exist for task ' . $taskId . '.');
        }

        return $sessions[0] ?? null;
    }

    public function prepare(TaskContract $contract): Session
    {
        $existing = $this->active($contract->taskId);
        if ($existing !== null) {
            return $existing;
        }

        return (new SessionStore())->create(
            (new ProjectLayout($this->rootPath))->sessionsRoot(),
            $contract->taskId,
            null,
            $contract->plannedBy,
            $contract->baseCommit,
        );
    }
}
