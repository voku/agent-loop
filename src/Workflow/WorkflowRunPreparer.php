<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use Closure;
use RuntimeException;
use voku\AgentLoop\PathResolver;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\CanonicalJson;
use voku\AgentLoop\Run\GovernedRun;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunManifestTransitionWriter;
use Throwable;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Inspect\MapReadiness;
use voku\AgentMap\Inspect\MapReadinessInspector;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStatus;
use voku\AgentSession\SessionStore;

/**
 * Deterministically prepares an already-approved Contract for governed work.
 *
 * Approval remains outside this class. Repeated calls reuse the durable Run and
 * active Session when possible and rehydrate the Run-bound Session after pruning.
 */
final readonly class WorkflowRunPreparer
{
    public function __construct(private string $rootPath)
    {
    }

    public function discoveryReadiness(TaskContract $contract): MapReadiness
    {
        $readiness = $this->mapReadiness();
        $this->assertDiscoveryReady($contract, $readiness);

        return $readiness;
    }

    /**
     * Bring Map discovery up to date for this Contract instead of asking the
     * host to do it first.
     *
     * Map readiness is deterministic: the repair never needs a human decision,
     * so requiring the host to run `agent-map build` before the lifecycle was
     * choreography, not governance. Reconciliation stays task-driven - a
     * Contract with no existing PHP scope still builds nothing - and stays
     * idempotent, because a ready snapshot is left untouched.
     *
     * The ranked Search index is deliberately not built here. It is optional
     * capability, and making it a silent precondition would turn an optional
     * owner feature into a mandatory preparation gate.
     */
    public function reconcileDiscovery(TaskContract $contract): MapReadiness
    {
        $readiness = $this->mapReadiness();
        if ($this->discoveryBlocker($contract, $readiness) === null) {
            return $readiness;
        }

        try {
            $this->rebuildMap($readiness);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'agent-map could not prepare discovery for this Contract: ' . $exception->getMessage(),
                0,
                $exception,
            );
        }

        // Fail closed: reconciliation may not invent readiness it did not reach.
        $readiness = $this->mapReadiness();
        $this->assertDiscoveryReady($contract, $readiness);

        return $readiness;
    }

    /**
     * Rebuild through the Map owner's own API.
     *
     * Path selection stays with agent-map: a stale snapshot is refreshed from
     * the entries it already records, and an absent one is built from the
     * project root, the same default `agent-loop edit` uses. agent-loop does
     * not carry a second source-selection policy.
     */
    private function rebuildMap(MapReadiness $readiness): void
    {
        $index = MapArtifactPaths::forProject($this->rootPath, (new ProjectLayout($this->rootPath))->mapRoot())->indexJson();
        $builder = new AgentMapBuilder();

        if ($readiness->mapState === 'stale' && is_file($index)) {
            $previous = (new IndexReader())->read($index);
            $changed = [];
            foreach ($previous->staleEntries() as $entry) {
                if ($entry['reason'] !== 'missing') {
                    $changed[] = $entry['path'];
                }
            }
            if ($changed !== []) {
                (new IndexWriter())->write(
                    $builder->build($this->rootPath, $changed, [], null, null, $previous),
                    $index,
                );

                return;
            }
        }

        (new IndexWriter())->write($builder->build($this->rootPath, ['.'], [], null, null, null), $index);
    }

    /** @param callable(list<string>): int $recallRunner */
    public function prepare(
        TaskContract $contract,
        MapReadiness $mapReadiness,
        callable $recallRunner,
    ): WorkflowRunPreparationResult {
        if ($contract->status !== TaskContract::APPROVED) {
            throw new RuntimeException('Governed Run preparation requires an approved Contract.');
        }

        $runner = Closure::fromCallable($recallRunner);
        $learningRoot = (new ProjectLayout($this->rootPath))->learningRoot();
        $session = $this->prepareSession($contract);
        $run = (new GovernedRunStore($this->rootPath))->prepare($contract, $session, $learningRoot);

        // Recall is derived state. Supersede any previous/partial task-local
        // output only after the exact governed Run/Session can be established,
        // and before projecting or compiling the new governed context.
        (new WorkflowRecallOutputSuperseder($this->rootPath))->archiveIfPresent($contract->taskId);

        $recallInput = $this->writeGovernedRecallInput($run, $contract);
        $preparedManifestPath = (new RunManifestTransitionWriter($this->rootPath))->write($contract->taskId);

        $recallArgs = [
            'compile', '--root', $learningRoot,
            '--task', $contract->taskId,
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

        $kanbanContext = (new WorkflowKanbanContextWriter($this->rootPath))->write($contract->taskId, $session);
        if ($kanbanContext !== null) {
            $recallArgs[] = '--kanban-context';
            $recallArgs[] = $kanbanContext;
        }

        $searchWarning = null;
        if ($mapReadiness->mapState === 'ready') {
            $recallArgs[] = '--map-index';
            $recallArgs[] = $mapReadiness->mapPath;
            $recallArgs[] = '--map-root';
            $recallArgs[] = $this->rootPath;

            if ($mapReadiness->rankedSearchReady()) {
                $recallArgs[] = '--map-search-index';
                $recallArgs[] = $mapReadiness->searchPath;
            } else {
                $searchWarning = 'agent-map Search is ' . $mapReadiness->searchState
                    . ' at ' . $layout->display($mapReadiness->searchPath)
                    . '; Recall compiles without ranked map evidence. Run `agent-loop map search-index build` or refresh it first.';
            }
        }

        $exit = $runner($recallArgs);
        if ($exit !== 0) {
            return new WorkflowRunPreparationResult(
                $run,
                $session,
                $learningRoot,
                $preparedManifestPath,
                null,
                $exit,
                $searchWarning,
            );
        }

        $compiledManifestPath = (new RunManifestTransitionWriter($this->rootPath))->write($contract->taskId);

        return new WorkflowRunPreparationResult(
            $run,
            $session,
            $learningRoot,
            $preparedManifestPath,
            $compiledManifestPath,
            0,
            $searchWarning,
        );
    }

    private function assertDiscoveryReady(TaskContract $contract, MapReadiness $readiness): void
    {
        $blocker = $this->discoveryBlocker($contract, $readiness);
        if ($blocker !== null) {
            throw new RuntimeException($blocker->reason . ' ' . $blocker->nextAction);
        }
    }

    private function discoveryBlocker(TaskContract $contract, MapReadiness $readiness): ?DiscoveryRepair
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
            return null;
        }
        if ($readiness->mapState === 'missing') {
            return new DiscoveryRepair(
                'Existing PHP scope requires agent-map discovery before governed preparation: '
                . implode(', ', array_keys($existingPhpScope)) . '.',
                'agent-loop map build --paths=src,tests',
            );
        }
        if ($readiness->mapState === 'invalid') {
            return new DiscoveryRepair(
                'Existing PHP scope requires a readable agent-map snapshot before governed preparation: '
                . ($readiness->mapFailure ?? 'agent-map reported an invalid snapshot') . '.',
                'agent-loop map build --paths=src,tests',
            );
        }
        if ($readiness->mapState === 'stale') {
            $stale = array_column($readiness->staleEntries, 'path');
            $visible = array_slice($stale, 0, 5);
            return new DiscoveryRepair(
                'Existing PHP scope is not covered by a fresh agent-map snapshot before governed preparation (stale map entries: '
                . implode(', ', $visible)
                . (count($stale) > count($visible) ? sprintf(' (+%d more)', count($stale) - count($visible)) : '')
                . ').',
                'agent-loop map refresh',
            );
        }

        $map = $readiness->currentMap();
        if ($map === null) {
            return new DiscoveryRepair(
                'agent-map reported a ready snapshot without a readable current map.',
                'agent-loop map build --paths=src,tests',
            );
        }

        $missingScope = [];
        foreach (array_keys($existingPhpScope) as $relative) {
            if ($map->file($relative) === null) {
                $missingScope[] = $relative;
            }
        }
        if ($missingScope !== []) {
            return new DiscoveryRepair(
                'Existing PHP scope is not covered by a fresh agent-map snapshot before governed preparation (scope not indexed: '
                . implode(', ', $missingScope) . ').',
                'agent-loop map refresh',
            );
        }

        return null;
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
        $sessionsRoot = (new ProjectLayout($this->rootPath))->sessionsRoot();
        $sessions = new SessionStore();
        $runs = new GovernedRunStore($this->rootPath);
        $active = $this->activeSession($contract->taskId);
        $boundRun = $runs->findForContract($contract);

        if ($boundRun !== null) {
            if ($active !== null) {
                if ($active->id !== $boundRun->sessionId) {
                    throw new RuntimeException(sprintf(
                        'Governed Run %s is bound to Session %s, but a different active Session %s exists for task %s.',
                        $boundRun->runId,
                        $boundRun->sessionId,
                        $active->id,
                        $contract->taskId,
                    ));
                }

                return $active;
            }

            if ($sessions->exists($sessionsRoot, $boundRun->sessionId)) {
                throw new RuntimeException(sprintf(
                    'Governed Run %s is bound to Session %s, but that Session exists and is not active.',
                    $boundRun->runId,
                    $boundRun->sessionId,
                ));
            }

            return $sessions->rehydrate(
                $sessionsRoot,
                $boundRun->sessionId,
                $contract->taskId,
                $contract->plannedBy,
                $contract->baseCommit,
            );
        }

        $currentRun = $runs->find($contract->taskId);
        if ($currentRun !== null) {
            if ($currentRun->contractRevision >= $contract->revision) {
                throw new RuntimeException(sprintf(
                    'Governed Run %s is bound to Contract revision %d and cannot be reconciled to approved revision %d.',
                    $currentRun->runId,
                    $currentRun->contractRevision,
                    $contract->revision,
                ));
            }

            if ($active !== null) {
                if ($active->id !== $currentRun->sessionId) {
                    throw new RuntimeException(sprintf(
                        'Governed Run %s is bound to Session %s, but a different active Session %s exists for task %s.',
                        $currentRun->runId,
                        $currentRun->sessionId,
                        $active->id,
                        $contract->taskId,
                    ));
                }
                $sessions->setStatus($active, SessionStatus::DROPPED);
                $active = null;
            } elseif ($sessions->exists($sessionsRoot, $currentRun->sessionId)) {
                $superseded = $sessions->load($sessionsRoot, $currentRun->sessionId);
                if (!$superseded->status->isClosed()) {
                    throw new RuntimeException(sprintf(
                        'Governed Run %s cannot be superseded while Session %s is %s.',
                        $currentRun->runId,
                        $superseded->id,
                        $superseded->status->value,
                    ));
                }
            }
        }

        if ($active !== null) {
            return $active;
        }

        return $sessions->create(
            $sessionsRoot,
            $contract->taskId,
            sprintf('%s-r%d-%s', $contract->taskId, $contract->revision, bin2hex(random_bytes(4))),
            $contract->plannedBy,
            $contract->baseCommit,
        );
    }

    private function writeGovernedRecallInput(GovernedRun $run, TaskContract $contract): string
    {
        $directory = dirname($run->path);
        $this->writeRunContractSnapshot($directory . '/contract.json', $run, $contract);

        $path = $directory . '/recall-input.json';
        $input = [
            'schema_version' => '1.0',
            'kind' => 'governed_recall_input',
            'run_id' => $run->runId,
            'contract' => [
                'path' => 'contract.json',
                'sha256' => $run->contractSource['sha256'],
                'revision' => $contract->revision,
            ],
        ];
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, CanonicalJson::pretty($input)) === false || !rename($tmp, $path)) {
            $cleanupFailed = is_file($tmp) && !unlink($tmp);
            throw new RuntimeException(
                'Unable to persist governed Recall input: ' . $path
                . ($cleanupFailed ? ' (temporary file left behind: ' . $tmp . ')' : ''),
            );
        }

        return $path;
    }

    private function writeRunContractSnapshot(string $path, GovernedRun $run, TaskContract $contract): void
    {
        $contents = file_get_contents($contract->path);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read approved Contract for governed Run snapshot: ' . $contract->path);
        }

        $digest = 'sha256:' . hash('sha256', $contents);
        if (!hash_equals($run->contractSource['sha256'], $digest)) {
            throw new RuntimeException('Approved Contract digest changed before governed Run snapshot could be persisted.');
        }

        if (is_file($path)) {
            $existing = file_get_contents($path);
            if (!is_string($existing)) {
                throw new RuntimeException('Unable to read governed Run Contract snapshot: ' . $path);
            }
            if (!hash_equals($contents, $existing)) {
                throw new RuntimeException('Governed Run Contract snapshot does not match the approved Contract source: ' . $path);
            }

            return;
        }

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $contents) === false || !rename($tmp, $path)) {
            $cleanupFailed = is_file($tmp) && !unlink($tmp);
            throw new RuntimeException(
                'Unable to persist governed Run Contract snapshot: ' . $path
                . ($cleanupFailed ? ' (temporary file left behind: ' . $tmp . ')' : ''),
            );
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
