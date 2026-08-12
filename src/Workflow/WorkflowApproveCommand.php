<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use voku\AgentLoop\PathResolver;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\CanonicalJson;
use voku\AgentLoop\Run\GovernedRun;
use voku\AgentLoop\Run\GovernedRunStore;
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

            $learningRoot = WorkflowLearningRoot::resolve($this->rootPath, $options['learningRoot']);
            $session = $this->prepareSession($contract);
            $run = (new GovernedRunStore($this->rootPath))->prepare($contract, $session, $learningRoot);
            $recallInput = $this->writeGovernedRecallInput($run, $contract);
            echo "[OK] workflow approve: governed Run {$run->runId} prepared for Contract revision {$contract->revision}\n";
            echo "[OK] workflow approve: working Session {$session->id} attached to governed Run {$run->runId}\n";
            echo "[OK] workflow approve: governed Run bound to durable Learning root {$run->learningRoot}\n";

            $manifestPath = (new RunManifestTransitionWriter($this->rootPath))->write($taskId->value);
            echo "[OK] workflow approve: approved-state Run projection refreshed at {$manifestPath}\n";

            $recallArgs = [
                'compile', '--root', $learningRoot,
                '--task', $taskId->value,
                '--task-brief', $recallInput,
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

            $layout = new ProjectLayout($this->rootPath);
            $mapIndex = $layout->mapIndex();
            if (is_file($mapIndex)) {
                $recallArgs[] = '--map-index';
                $recallArgs[] = $mapIndex;
                $recallArgs[] = '--map-root';
                $recallArgs[] = $this->rootPath;

                $mapSearchIndex = $layout->mapSearchIndex();
                if (is_file($mapSearchIndex)) {
                    $recallArgs[] = '--map-search-index';
                    $recallArgs[] = $mapSearchIndex;
                }
            }
            $exit = ($this->recallRunner)($recallArgs);
            if ($exit !== 0) {
                fwrite(
                    STDERR,
                    "[FAIL] workflow approve: Contract and governed Run remain resumable, but recall compilation failed. Rerun the same workflow approve command after fixing compiler input.\n",
                );

                return $exit;
            }

            $manifestPath = (new RunManifestTransitionWriter($this->rootPath))->write($taskId->value);
            echo "[OK] workflow approve: Contract approved and governed Recall compiled for {$taskId->value}\n";
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
            (new ProjectLayout($this->rootPath))->sessionsRoot(),
            $contract->taskId,
            null,
            $contract->plannedBy,
            $contract->baseCommit,
        );
    }

    private function writeGovernedRecallInput(GovernedRun $run, TaskContract $contract): string
    {
        $path = dirname($run->path) . '/recall-input.json';
        $input = [
            'schema_version' => '1.0',
            'kind' => 'governed_recall_input',
            'run_id' => $run->runId,
            'contract' => [
                'path' => '../../contracts/' . $contract->taskId . '/contract.json',
                'sha256' => $run->contractSource['sha256'],
                'revision' => $contract->revision,
            ],
        ];
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, CanonicalJson::pretty($input)) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to persist governed Recall input: ' . $path);
        }

        return $path;
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
        $root = (new ProjectLayout($this->rootPath))->sessionsRoot();
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
