<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use voku\AgentKanban\Domain\CardId;
use voku\AgentKanban\Exception\ConfigurationException;
use voku\AgentKanban\Repository\BoardContextResolver;
use voku\AgentLoop\PathResolver;
use voku\AgentLoop\ProjectLayout;
use voku\AgentRecallCompiler\KanbanContextProjection;

/**
 * Adapt the board owner's typed card into Recall's bounded in-memory projection.
 *
 * Loop does not persist this derived projection into Session-owned storage.
 */
final readonly class WorkflowKanbanContextProjector
{
    private const string CARD_ID_PATTERN = '/^[A-Z][A-Z0-9]*-[1-9][0-9]*$/';

    public function __construct(private string $rootPath)
    {
    }

    public function project(string $taskId): ?KanbanContextProjection
    {
        $boardRoot = (new ProjectLayout($this->rootPath))->boardRoot();
        try {
            $board = (new BoardContextResolver())->resolve($boardRoot);
        } catch (ConfigurationException) {
            return null;
        }

        $normalizedTaskId = strtoupper(trim($taskId));
        if (str_contains($taskId, "\0") || preg_match(self::CARD_ID_PATTERN, $normalizedTaskId) !== 1) {
            // Local/ad-hoc task IDs are valid workflow tasks but have no card
            // identity in the typed board contract.
            return null;
        }
        $cardId = CardId::fromString($normalizedTaskId);

        if (!$board->repository->exists($cardId)) {
            return null;
        }
        $card = $board->repository->load($cardId);

        return new KanbanContextProjection(
            taskId: $taskId,
            sourcePath: PathResolver::relativeTo($this->rootPath, $card->sourceFile),
            sourceRevision: $card->revision->toString(),
            title: $card->title,
            lane: $card->lane->toString(),
            status: $card->status->toString(),
            priority: $card->priority,
            nextAction: $card->nextAction,
        );
    }
}
