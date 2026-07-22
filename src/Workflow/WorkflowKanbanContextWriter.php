<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use RuntimeException;
use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Domain\CardId;
use voku\AgentKanban\Exception\ValidationException;
use voku\AgentKanban\Repository\MarkdownCardRepository;
use voku\AgentSession\Session;

/**
 * Board owns parsing and card policy. Workflow only writes a small, stable
 * projection beside the approved session brief for recall to consume.
 */
final readonly class WorkflowKanbanContextWriter
{
    public function __construct(private string $rootPath)
    {
    }

    public function write(string $taskId, Session $session): ?string
    {
        $configPath = rtrim($this->rootPath, '/') . '/todo/kanban.config.json';
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
            $this->rootPath,
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
                'path' => $this->relativeToRoot($card->sourceFile),
                'revision' => $card->revision->toString(),
            ],
            'card' => [
                'title' => $card->title,
                'lane' => $card->lane->toString(),
                'status' => $card->status->toString(),
                'priority' => $card->priority,
                'summary' => $this->excerpt($card->summary, 1200),
                'next_action' => $this->excerpt($card->nextAction, 1200),
                'validation' => $this->excerpt($card->validation, 1200),
                'task_brief' => $this->excerpt($card->taskBrief, 3000),
                'handoff_notes' => $this->excerpt($card->handoffNotes, 3000),
            ],
        ];
        $json = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $path = $session->path . '/kanban-context.json';
        if (file_put_contents($path, $json) === false) {
            throw new RuntimeException('Could not write kanban context: ' . $path);
        }

        return $path;
    }

    private function relativeToRoot(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $this->rootPath), '/');
        $normalized = str_replace('\\', '/', $path);
        if (str_starts_with($normalized, $root . '/')) {
            return substr($normalized, strlen($root) + 1);
        }

        return $normalized;
    }

    private function excerpt(string $value, int $maxChars): string
    {
        $normalized = trim(str_replace(["\r\n", "\r"], "\n", $value));
        if (mb_strlen($normalized, 'UTF-8') <= $maxChars) {
            return $normalized;
        }

        return rtrim(mb_strcut($normalized, 0, $maxChars, 'UTF-8')) . "\n[truncated by workflow projection]";
    }
}
