<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;
use voku\AgentLoop\PathResolver;
use voku\AgentLoop\Run\RunManifestTransitionWriter;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStore;
use voku\AgentSession\WorkBriefStatus;
use voku\AgentSession\WorkBriefStore;

final readonly class WorkflowApproveCommand
{
    /** @param callable(list<string>): int $recallRunner */
    public function __construct(private string $rootPath, private mixed $recallRunner)
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

        try {
            $session = $this->activeSession($taskId->value);
            $briefs = new WorkBriefStore();
            $brief = $briefs->find($session);
            if ($brief === null) {
                throw new RuntimeException('Active session has no work brief: ' . $session->id);
            }
            $approval = $briefs->approval($session);
            $alreadyApproved = $brief->status === WorkBriefStatus::APPROVED
                && $approval !== null
                && $approval->workBriefRevision === $brief->revision;

            if (!$alreadyApproved) {
                $briefs->approve($session, $options['by']);
                echo "[OK] workflow approve: work brief revision approved for {$taskId->value}\n";

                $archive = (new WorkflowRecallOutputSuperseder($this->rootPath))->archiveIfPresent($taskId->value);
                if ($archive !== null) {
                    echo "[OK] workflow approve: superseded recall output archived at {$archive}\n";
                }
            } else {
                echo "[OK] workflow approve: current work brief revision was already approved; resuming recall compilation\n";
            }

            $manifestPath = (new RunManifestTransitionWriter($this->rootPath))->write($taskId->value);
            echo "[OK] workflow approve: approved-state manifest refreshed at {$manifestPath}\n";

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
            $operatingPromptManifest = $this->operatingPromptManifest($briefPath);
            if ($operatingPromptManifest !== null) {
                $recallArgs[] = '--operating-prompt-manifest';
                $recallArgs[] = $operatingPromptManifest;
            }
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

                // The derived search index is a cache: a repository that never built one gets the
                // same briefing as before, and one that did gets ranked candidates for a brief that
                // names no exact target yet.
                $mapSearchIndex = rtrim($this->rootPath, '/') . '/.agent-map/search.sqlite';
                if (is_file($mapSearchIndex)) {
                    $recallArgs[] = '--map-search-index';
                    $recallArgs[] = $mapSearchIndex;
                }
            }
            $exit = ($this->recallRunner)($recallArgs);
            if ($exit !== 0) {
                fwrite(
                    STDERR,
                    "[FAIL] workflow approve: work brief remains approved, but recall compilation failed. Rerun the same workflow approve command after fixing the compiler input.\n",
                );

                return $exit;
            }

            $manifestPath = (new RunManifestTransitionWriter($this->rootPath))->write($taskId->value);
            echo "[OK] workflow approve: work brief approved and recall compiled for {$taskId->value}\n";
            echo "[OK] workflow approve: compiled-state manifest refreshed at {$manifestPath}\n";

            return 0;
        } catch (RuntimeException $exception) {
            fwrite(
                STDERR,
                '[FAIL] workflow approve: ' . $exception->getMessage()
                . "\n[ACTION REQUIRED] Inspect agent-loop workflow status {$taskId->value} --format=json and rerun workflow approve after repair.\n",
            );

            return 1;
        } catch (Throwable $exception) {
            fwrite(
                STDERR,
                '[FAIL] workflow approve: state may have changed, but run-manifest refresh or preparation failed: '
                . $exception->getMessage()
                . "\n[ACTION REQUIRED] Inspect agent-loop workflow status {$taskId->value} --format=json and rerun workflow approve after repair.\n",
            );

            return 1;
        }
    }

    /**
     * Return the explicitly approved prompt manifest, resolved relative to the
     * project root. The work brief owns selection policy; the compiler owns the
     * manifest semantics.
     */
    private function operatingPromptManifest(string $briefPath): ?string
    {
        $contents = file_get_contents($briefPath);
        if ($contents === false) {
            throw new RuntimeException('Unable to read approved work brief: ' . $briefPath);
        }
        try {
            $brief = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid approved work-brief JSON: ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($brief)) {
            throw new RuntimeException('Approved work brief must decode to an object.');
        }

        $prompts = $brief['operating_prompts'] ?? [];
        if (!is_array($prompts)) {
            throw new RuntimeException('Approved work brief operating_prompts must be a list.');
        }
        if ($prompts === []) {
            return null;
        }

        $manifest = $brief['operating_prompt_manifest'] ?? null;
        if (!is_string($manifest) || trim($manifest) === '') {
            throw new RuntimeException('Approved operating prompts require operating_prompt_manifest.');
        }

        $resolved = PathResolver::join($this->rootPath, trim($manifest));
        if (!is_file($resolved)) {
            throw new RuntimeException('Approved operating prompt manifest not found: ' . $resolved);
        }

        return $resolved;
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
            throw new RuntimeException("Expected exactly one active session for {$taskId}.");
        }

        return $sessions[0];
    }
}
