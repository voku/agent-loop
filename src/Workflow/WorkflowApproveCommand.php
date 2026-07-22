<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use RuntimeException;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStore;

final readonly class WorkflowApproveCommand
{
    /** @param callable(list<string>): int $sessionRunner @param callable(list<string>): int $recallRunner */
    public function __construct(private string $rootPath, private mixed $sessionRunner, private mixed $recallRunner)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        try {
            $taskId = new WorkflowTaskId($args[0] ?? '');
            $options = $this->parse(array_slice($args, 1));
        } catch (InvalidArgumentException $e) {
            fwrite(STDERR, '[FAIL] workflow approve: ' . $e->getMessage() . "\n");

            return 1;
        }

        $exit = ($this->sessionRunner)(['brief', 'approve', $taskId->value, '--by', $options['by']]);
        if ($exit !== 0) {
            return $exit;
        }

        try {
            $session = $this->activeSession($taskId->value);
            $briefPath = $session->path . '/work-brief.json';
            if (!is_file($briefPath)) {
                throw new RuntimeException('Approved session has no work-brief.json: ' . $session->id);
            }
            $learningRoot = WorkflowLearningRoot::resolve($this->rootPath, $options['learningRoot']);
            $recallArgs = [
                'compile', '--root', $learningRoot,
                '--task', $taskId->value,
                '--task-brief', $briefPath,
            ];
            $documentManifest = rtrim($learningRoot, '/') . '/recall-documents.json';
            if (is_file($documentManifest)) {
                $recallArgs[] = '--document-manifest';
                $recallArgs[] = $documentManifest;
            }
            $kanbanContext = (new WorkflowKanbanContextWriter($this->rootPath))->write($taskId->value, $session);
            if ($kanbanContext !== null) {
                $recallArgs[] = '--kanban-context';
                $recallArgs[] = $kanbanContext;
            }
            $mapIndex = rtrim($this->rootPath, '/') . '/.agent-map/php-symbols.json';
            if (is_file($mapIndex)) {
                $recallArgs[] = '--map-index';
                $recallArgs[] = $mapIndex;
                $recallArgs[] = '--map-root';
                $recallArgs[] = $this->rootPath;
            }
            $exit = ($this->recallRunner)($recallArgs);
        } catch (RuntimeException $exception) {
            fwrite(\STDERR, '[FAIL] workflow approve: ' . $exception->getMessage() . "\n");

            return 1;
        }
        if ($exit === 0) {
            echo "[OK] workflow approve: work brief approved and recall compiled for {$taskId->value}\n";
        }

        return $exit;
    }

    /**
     * @param list<string> $tokens
     * @return array{by: string, learningRoot: string|null}
     */
    private function parse(array $tokens): array
    {
        $by = null;
        $learningRoot = null;
        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if (!in_array($token, ['--by', '--learning-root', '--root'], true) || !isset($tokens[$index + 1])) {
                throw new InvalidArgumentException('--by is required.');
            }
            $value = trim($tokens[++$index]);
            if ($value === '') {
                throw new InvalidArgumentException($token . ' requires a non-empty value.');
            }
            if ($token === '--by') {
                $by = $value;
            } else {
                $learningRoot = $value;
            }
        }
        if ($by === null) {
            throw new InvalidArgumentException('--by is required.');
        }

        return ['by' => $by, 'learningRoot' => $learningRoot];
    }

    private function activeSession(string $taskId): Session
    {
        $root = rtrim($this->rootPath, '/') . '/session_plan';
        $sessions = is_dir($root) ? array_values(array_filter(
            (new SessionStore())->all($root),
            static fn (Session $session): bool => $session->taskId === $taskId && !$session->status->isClosed(),
        )) : [];
        if (count($sessions) !== 1) {
            throw new RuntimeException("Expected exactly one active session for {$taskId} after approval.");
        }

        return $sessions[0];
    }
}
