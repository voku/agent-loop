<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Domain\CardId;
use voku\AgentKanban\Repository\MarkdownCardRepository;
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
        $configPath = is_file($boardRoot . '/kanban.config.json')
            ? $boardRoot . '/kanban.config.json'
            : $boardRoot . '/todo/kanban.config.json';
        if (!is_file($configPath)) {
            return null;
        }

        $normalizedTaskId = strtoupper(trim($taskId));
        if (str_contains($taskId, "\0") || preg_match(self::CARD_ID_PATTERN, $normalizedTaskId) !== 1) {
            // Local/ad-hoc task IDs are valid workflow tasks but have no card
            // identity in the typed board contract.
            return null;
        }
        $cardId = CardId::fromString($normalizedTaskId);

        $repository = new MarkdownCardRepository(
            $boardRoot,
            BoardConfig::fromJsonFile($configPath),
        );
        if (!$repository->exists($cardId)) {
            return null;
        }
        $card = $repository->load($cardId);

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
