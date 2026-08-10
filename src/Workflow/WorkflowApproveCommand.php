<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use voku\AgentLoop\PathResolver;
use voku\AgentLoop\Run\CanonicalJson;
use voku\AgentLoop\Run\RunManifestTransitionWriter;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStore;

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
            $contracts = new TaskContractStore($this->rootPath);
            $contract = $contracts->load($taskId->value);
            $alreadyApproved = $contract->status === TaskContract::APPROVED;

            if (!$alreadyApproved) {
                $contract = $contracts->approve($taskId->value, $options['by']);
                echo "[OK] workflow approve: Contract revision {$contract->revision} approved for {$taskId->value}\n";

                $archive = (new WorkflowRecallOutputSuperseder($this->rootPath))->archiveIfPresent($taskId->value);
                if ($archive !== null) {
                    echo "[OK] workflow approve: superseded recall output archived at {$archive}\n";
                }
            } else {
                echo "[OK] workflow approve: current Contract revision was already approved; resuming Run preparation\n";
            }

            $session = $this->prepareSession($contract);
            $this->writeSessionContractSnapshot($session, $contract);
            echo "[OK] workflow approve: working Session {$session->id} prepared for approved Contract revision {$contract->revision}\n";

            $manifestPath = (new RunManifestTransitionWriter($this->rootPath))->write($taskId->value);
            echo "[OK] workflow approve: approved-state Run projection refreshed at {$manifestPath}\n";

            $learningRoot = WorkflowLearningRoot::resolve($this->rootPath, $options['learningRoot']);
            $recallArgs = [
                'compile', '--root', $learningRoot,
                '--task', $taskId->value,
                '--task-brief', $contract->path,
            ];
            $operatingPromptManifest = $this->operatingPromptManifest($contract);
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
                    "[FAIL] workflow approve: Contract remains approved and Session remains resumable, but recall compilation failed. Rerun the same workflow approve command after fixing compiler input.\n",
                );

                return $exit;
            }

            $manifestPath = (new RunManifestTransitionWriter($this->rootPath))->write($taskId->value);
            echo "[OK] workflow approve: Contract approved and recall compiled for {$taskId->value}\n";
            echo "[OK] workflow approve: compiled-state Run projection refreshed at {$manifestPath}\n";

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
                '[FAIL] workflow approve: durable Contract may be approved, but Run preparation failed: '
                . $exception->getMessage()
                . "\n[ACTION REQUIRED] Inspect agent-loop workflow status {$taskId->value} --format=json and rerun workflow approve after repair.\n",
            );

            return 1;
        }
    }

    private function prepareSession(TaskContract $contract): Session
    {
        $existing = $this->activeSession($contract->taskId);
        if ($existing !== null) {
            return $existing;
        }

        return (new SessionStore())->create(
            rtrim($this->rootPath, '/') . '/session_plan',
            $contract->taskId,
            null,
            $contract->plannedBy,
            $contract->baseCommit,
        );
    }

    private function writeSessionContractSnapshot(Session $session, TaskContract $contract): void
    {
        if ($contract->status !== TaskContract::APPROVED || $contract->approvedBy === null || $contract->approvedAt === null) {
            throw new RuntimeException('Session preparation requires an approved durable Contract.');
        }
        $contractHash = hash_file('sha256', $contract->path);
        if ($contractHash === false) {
            throw new RuntimeException('Unable to hash approved Contract: ' . $contract->path);
        }
        $source = [
            'path' => $contract->path,
            'sha256' => $contractHash,
            'authority' => 'task_contract',
        ];
        $brief = [
            'schema_version' => '1.0',
            'task_id' => $contract->taskId,
            'goal' => $contract->goal,
            'scope' => $contract->scope,
            'non_goals' => $contract->nonGoals,
            'validation' => $contract->validation,
            'tags' => $contract->tags,
            'behavior_anchors' => $contract->behaviorAnchors,
            'operating_prompt_manifest' => $contract->operatingPromptManifest,
            'operating_prompts' => $contract->operatingPrompts,
            'status' => 'approved',
            'revision' => $contract->revision,
            'created_at' => $contract->createdAt,
            'updated_at' => $contract->updatedAt,
            'derived_from_contract' => $source,
        ];
        $approval = [
            'schema_version' => '1.0',
            'task_id' => $contract->taskId,
            'work_brief_revision' => $contract->revision,
            'approved_by' => $contract->approvedBy,
            'approved_at' => $contract->approvedAt,
            'derived_from_contract' => $source,
        ];

        $this->atomicWrite($session->path . '/work-brief.json', CanonicalJson::pretty($brief));
        $this->atomicWrite($session->path . '/approval.json', CanonicalJson::pretty($approval));
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $contents) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to write derived Session Contract snapshot: ' . $path);
        }
    }

    private function operatingPromptManifest(TaskContract $contract): ?string
    {
        if ($contract->operatingPrompts === []) {
            return null;
        }
        $manifest = $contract->operatingPromptManifest;
        if ($manifest === null) {
            throw new RuntimeException('Approved operating prompts require operating_prompt_manifest.');
        }

        $resolved = PathResolver::join($this->rootPath, $manifest);
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

    private function activeSession(string $taskId): ?Session
    {
        $root = rtrim($this->rootPath, '/') . '/session_plan';
        if (!is_dir($root)) {
            return null;
        }
        $sessions = array_values(array_filter(
            (new SessionStore())->all($root),
            static fn (Session $session): bool => $session->taskId === $taskId && !$session->status->isClosed(),
        ));
        if (count($sessions) > 1) {
            throw new RuntimeException("Multiple active Sessions found for {$taskId}.");
        }

        return $sessions[0] ?? null;
    }
}
