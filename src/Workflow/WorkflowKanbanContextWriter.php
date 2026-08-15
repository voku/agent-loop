<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use RuntimeException;
use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Domain\CardId;
use voku\AgentKanban\Exception\ValidationException;
use voku\AgentKanban\Repository\MarkdownCardRepository;
use voku\AgentLoop\PathResolver;
use voku\AgentLoop\ProjectLayout;
use voku\AgentSession\Session;

/**
 * Board owns parsing and card policy. Workflow only writes the fields Recall
 * actually consumes, preserving the complete value of every selected field.
 */
final readonly class WorkflowKanbanContextWriter
{
    public function __construct(private string $rootPath)
    {
    }

    public function write(string $taskId, Session $session): ?string
    {
        $boardRoot = (new ProjectLayout($this->rootPath))->boardRoot();
        $configPath = $boardRoot . '/todo/kanban.config.json';
        if (!is_file($configPath)) {
            return null;
        }
        try {
            $cardId = CardId::fromString($taskId);
        } catch (ValidationException) {
            // Local/ad-hoc task IDs are valid workflow tasks but have no card
            // identity in the typed board contract.
            return null;
        }

        $repository = new MarkdownCardRepository(
            $boardRoot,
            BoardConfig::fromJsonFile($configPath),
        );
        if (!$repository->exists($cardId)) {
            return null;
        }
        $card = $repository->load($cardId);
        $context = [
            'schema_version' => '1.0',
            'task_id' => $taskId,
            'source' => [
                'path' => PathResolver::relativeTo($this->rootPath, $card->sourceFile),
                'revision' => $card->revision->toString(),
            ],
            'card' => [
                'title' => $card->title,
                'lane' => $card->lane->toString(),
                'status' => $card->status->toString(),
                'priority' => $card->priority,
                'next_action' => $card->nextAction,
            ],
        ];
        $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $path = $session->path . '/kanban-context.json';
        if (file_put_contents($path, $json) === false) {
            throw new RuntimeException('Could not write kanban context: ' . $path);
        }

        return $path;
    }
}
