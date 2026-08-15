<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use ItpContext\Attribute\Rule;
use RuntimeException;
use Throwable;
use voku\AgentLoop\Context\ArchitectureRules;
use voku\AgentLoop\Init\RepositoryActivation;
use voku\AgentLoop\PathResolver;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\RecallOutputRoot;
use voku\AgentLoop\Run\CanonicalJson;
use voku\AgentLoop\Run\GovernedRun;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunManifestTransitionWriter;
use voku\AgentMap\Inspect\MapReadiness;
use voku\AgentMap\Inspect\MapReadinessInspector;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStore;

#[Rule(ArchitectureRules::TypedPackageApisInsideWorkflow)]
#[Rule(ArchitectureRules::EvidenceIsNotAuthority)]
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
            $mapReadiness = $this->mapReadiness();
            $this->assertDiscoveryReady($contract, $mapReadiness);
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

            $learningRoot = (new ProjectLayout($this->rootPath))->learningRoot();
            $session = $this->prepareSession($contract);
            $run = (new GovernedRunStore($this->rootPath))->prepare($contract, $session, $learningRoot);
            $recallInput = $this->writeGovernedRecallInput($run, $contract);
            echo "[OK] workflow approve: governed Run {$run->runId} prepared for Contract revision {$contract->revision}\n";
            echo "[OK] workflow approve: working Session {$session->id} attached to governed Run {$run->runId}\n";
            echo '[OK] workflow approve: governed Run bound to durable Learning root '
                . PathResolver::relativeTo($this->rootPath, WorkflowLearningRoot::forRun($this->rootPath, $run)) . "\n";

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
            $layout = new ProjectLayout($this->rootPath);
            $documentManifests = $layout->recallDocumentManifests();
            $learningDocumentManifest = rtrim($learningRoot, '/') . '/recall-documents.json';
            if (is_file($learningDocumentManifest) && !in_array($learningDocumentManifest, $documentManifests, true)) {
                $documentManifests[] = $learningDocumentManifest;
            }
            foreach ($documentManifests as $documentManifest) {
                $recallArgs[] = '--document-manifest';
                $recallArgs[] = $documentManifest;
            }
            $kanbanContext = (new WorkflowKanbanContextWriter($this->rootPath))->write($taskId->value, $session);
            if ($kanbanContext !== null) {
                $recallArgs[] = '--kanban-context';
                $recallArgs[] = $kanbanContext;
            }

            if ($mapReadiness->mapState === 'ready') {
                $recallArgs[] = '--map-index';
                $recallArgs[] = $mapReadiness->mapPath;
                $recallArgs[] = '--map-root';
                $recallArgs[] = $this->rootPath;

                if ($mapReadiness->rankedSearchReady()) {
                    $recallArgs[] = '--map-search-index';
                    $recallArgs[] = $mapReadiness->searchPath;
                } else {
                    // Recall still compiles, but with no ranked facts - so the
                    // governed context carries only the symbols already named in
                    // the approved scope. Said out loud, because a silently
                    // narrower context looks exactly like a correct one.
                    echo '[WARN] workflow approve: agent-map Search is ' . $mapReadiness->searchState
                        . ' at ' . $layout->display($mapReadiness->searchPath)
                        . '; Recall compiles without ranked map evidence. Run `agent-loop map search-index build` or refresh it first.' . "\n";
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
            $this->printRecallHandoff($taskId->value);

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

    private function assertDiscoveryReady(TaskContract $contract, MapReadiness $readiness): void
    {
        $projectRoot = realpath($this->rootPath);
        if (!is_string($projectRoot)) {
            throw new RuntimeException('Project root cannot be resolved for discovery readiness: ' . $this->rootPath);
        }
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');

        $existingPhpScope = [];
        foreach ($contract->scope as $scopePath) {
            $absolute = realpath(PathResolver::join($projectRoot, $scopePath));
            if (!is_string($absolute)) {
                continue;
            }
            $absolute = str_replace('\\', '/', $absolute);
            if (!str_starts_with($absolute, $projectRoot . '/')) {
                continue;
            }
            $relative = substr($absolute, strlen($projectRoot) + 1);
            if ($relative === '' || !str_ends_with(strtolower($relative), '.php') || !is_file($absolute)) {
                continue;
            }
            $existingPhpScope[$relative] = true;
        }

        if ($existingPhpScope === []) {
            return;
        }

        if ($readiness->mapState === 'missing') {
            throw new RuntimeException(
                'Existing PHP scope requires agent-map discovery before approval: '
                . implode(', ', array_keys($existingPhpScope))
                . '. Run `agent-loop map build --paths=src,tests` (or the project-appropriate paths) first.',
            );
        }
        if ($readiness->mapState === 'invalid') {
            throw new RuntimeException(
                'Existing PHP scope requires a readable agent-map snapshot before approval: '
                . ($readiness->mapFailure ?? 'agent-map reported an invalid snapshot'),
            );
        }
        if ($readiness->mapState === 'stale') {
            $stale = array_column($readiness->staleEntries, 'path');
            $visible = array_slice($stale, 0, 5);
            throw new RuntimeException(
                'Existing PHP scope is not covered by a fresh agent-map snapshot before approval (stale map entries: '
                . implode(', ', $visible)
                . (count($stale) > count($visible) ? sprintf(' (+%d more)', count($stale) - count($visible)) : '')
                . '). Run `agent-loop map refresh` or rebuild the map first.',
            );
        }

        $map = $readiness->currentMap();
        if ($map === null) {
            throw new RuntimeException('agent-map reported a ready snapshot without a readable current map.');
        }

        $missingScope = [];
        foreach (array_keys($existingPhpScope) as $relative) {
            if ($map->file($relative) === null) {
                $missingScope[] = $relative;
            }
        }
        if ($missingScope === []) {
            return;
        }

        throw new RuntimeException(
            'Existing PHP scope is not covered by a fresh agent-map snapshot before approval (scope not indexed: '
            . implode(', ', $missingScope)
            . '). Run `agent-loop map refresh` or rebuild the map first.',
        );
    }

    private function mapReadiness(): MapReadiness
    {
        $layout = new ProjectLayout($this->rootPath);

        return (new MapReadinessInspector())->inspect(
            MapArtifactPaths::forProject($this->rootPath, $layout->mapRoot()),
        );
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
            sprintf('%s-r%d-%s', $contract->taskId, $contract->revision, bin2hex(random_bytes(4))),
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

    private function printRecallHandoff(string $taskId): void
    {
        $cli = (new RepositoryActivation($this->rootPath))->cliPath();
        $systemPath = RecallOutputRoot::resolve($this->rootPath) . '/' . $taskId . '/system.md';
        $displaySystemPath = RecallOutputRoot::relativeTo($this->rootPath, $systemPath);

        echo "[NEXT] {$cli} workflow context {$taskId} --max-lines 120 --max-bytes 12000\n";
        echo "[NEXT] Read {$displaySystemPath} before planning or modifying code.\n";
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
     * @return array{by: string}
     */
    private function parse(array $tokens): array
    {
        $by = null;
        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if ($token !== '--by') {
                throw new InvalidArgumentException('Unknown option: ' . $token);
            }
            if (!isset($tokens[$index + 1]) || str_starts_with($tokens[$index + 1], '--')) {
                throw new InvalidArgumentException('--by requires a value.');
            }
            $value = trim($tokens[++$index]);
            if ($value === '') {
                throw new InvalidArgumentException($token . ' requires a non-empty value.');
            }
            $by = $value;
        }
        if ($by === null) {
            throw new InvalidArgumentException('--by is required.');
        }

        return ['by' => $by];
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
