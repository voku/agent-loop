<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use Closure;
use RuntimeException;
use voku\AgentLoop\PathResolver;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\RecallOutputRoot;
use voku\AgentLoop\Run\CanonicalJson;
use voku\AgentLoop\Run\GovernedRun;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunManifestTransitionWriter;
use Throwable;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Inspect\MapReadiness;
use voku\AgentMap\Inspect\MapReadinessInspector;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentRecallCompiler\CompileRequest;
use voku\AgentRecallCompiler\CompileResult;
use voku\AgentRecallCompiler\Output\CompiledRecallOutputSuperseder;
use voku\AgentRecallCompiler\RecallCompiler;
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
    private Closure $recallCompiler;

    /** @param null|callable(CompileRequest): CompileResult $recallCompiler */
    public function __construct(private string $rootPath, ?callable $recallCompiler = null)
    {
        $this->recallCompiler = $recallCompiler === null
            ? (new RecallCompiler())->compile(...)
            : Closure::fromCallable($recallCompiler);
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
            $this->rebuildMap($contract, $readiness);
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
     * Automatic preparation proves only the already-approved, existing PHP
     * Contract scope plus any stale map entries that need refreshing. It never
     * performs an unbounded repository-wide discovery or carries unrelated entries
     * forward from an unreadable snapshot. Explicit Map commands remain the front
     * door for intentionally broader discovery.
     */
    private function rebuildMap(TaskContract $contract, MapReadiness $readiness): void
    {
        $scope = $this->existingPhpScope($contract);
        $stale = array_column($readiness->staleEntries, 'path');
        $rebuildPaths = array_values(array_unique([...$scope, ...$stale]));
        if ($rebuildPaths === []) {
            return;
        }

        $artifacts = MapArtifactPaths::forProject(
            $this->rootPath,
            (new ProjectLayout($this->rootPath))->mapRoot(),
        );
        $indexPath = $artifacts->indexJson();

        $existing = null;
        if (is_file($indexPath)) {
            try {
                $existing = (new IndexReader())->read($indexPath);
            } catch (Throwable) {
                // An unreadable snapshot is not authority to keep; rebuild the scope alone.
                $existing = null;
            }
        }

        if ($existing === null) {
            $builder = new AgentMapBuilder(semanticAnalyzer: new StructuralOnlySemanticAnalyzer(), artifacts: $artifacts);
            (new IndexWriter())->write($builder->build($this->rootPath, $rebuildPaths, []), $indexPath);

            return;
        }

        // Patch the Contract scope into the shared index with a builder of the
        // same backend, never replace it. A scope-sized build written over the
        // shared index made every later map consumer - queries, planners, Recall
        // evidence - see only the handful of files this one Contract touched, and
        // one scoped file outside the indexed paths was enough to trigger it on a
        // full semantic index. When no automatic builder reproduces the recorded
        // backend, the index is left untouched and the owner's explicit build is
        // the repair.
        $builder = $this->builderForBackend($existing->backend, $artifacts);
        if ($builder === null) {
            throw new RuntimeException(sprintf(
                'Contract scope %s is not indexed in %s, and its backend "%s" cannot be patched automatically; the index was left untouched. Rebuild it with agent-map build covering that scope.',
                implode(', ', $rebuildPaths),
                $indexPath,
                $existing->backend,
            ));
        }

        $rebuiltIndex = $builder->build($this->rootPath, $rebuildPaths, [], null, null, $existing);
        (new IndexWriter())->write(
            $rebuiltIndex,
            $indexPath,
        );

        $searchDb = $artifacts->searchDatabase();
        if (is_file($searchDb) && \voku\AgentMap\Search\SearchIndexStore::supportsFts5()) {
            try {
                $store = new \voku\AgentMap\Search\SearchIndexStore($searchDb);
                $extractor = new \voku\AgentMap\Search\ChunkExtractor();
                $chunks = $extractor->extract($rebuiltIndex, $rebuildPaths);
                $store->replaceChunks($chunks, $rebuildPaths);
                $store->setMeta('map_snapshot', $rebuiltIndex->fingerprint === null ? 'sha256:none' : $rebuiltIndex->fingerprint->sourceDigest);
                $store->setMeta('chunk_policy_version', (string) \voku\AgentMap\Search\ChunkPolicy::VERSION);
            } catch (Throwable) {
                // Search index refresh is best-effort and never blocks discovery.
            }
        }
    }

    /** The automatic builder whose backend matches a recorded index, or null when none does. */
    private function builderForBackend(string $backend, MapArtifactPaths $artifacts): ?AgentMapBuilder
    {
        foreach ([
            new AgentMapBuilder(semanticAnalyzer: new StructuralOnlySemanticAnalyzer(), artifacts: $artifacts),
            new AgentMapBuilder(artifacts: $artifacts),
        ] as $candidate) {
            if ($candidate->backend() === $backend) {
                return $candidate;
            }
        }

        return null;
    }

    public function prepare(
        TaskContract $contract,
        MapReadiness $mapReadiness,
    ): WorkflowRunPreparationResult {
        if ($contract->status !== TaskContract::APPROVED) {
            throw new RuntimeException('Governed Run preparation requires an approved Contract.');
        }

        $learningRoot = (new ProjectLayout($this->rootPath))->learningRoot();
        $session = $this->prepareSession($contract);
        $run = (new GovernedRunStore($this->rootPath))->prepare($contract, $session, $learningRoot);
        $recallOutputDirectory = RecallOutputRoot::resolve($this->rootPath) . '/' . $contract->taskId;

        // Recall is derived state. Supersede any previous/partial task-local
        // output only after the exact governed Run/Session can be established,
        // and before projecting or compiling the new governed context.
        (new CompiledRecallOutputSuperseder())->archiveIfPresent($recallOutputDirectory);

        $recallInput = $this->writeGovernedRecallInput($run, $contract);
        $preparedManifestPath = (new RunManifestTransitionWriter($this->rootPath))->write($contract->taskId);

        $operatingPromptManifest = $this->operatingPromptManifest($contract);
        $operatingPromptManifests = $operatingPromptManifest === null ? [] : [$operatingPromptManifest];

        $layout = new ProjectLayout($this->rootPath);
        $documentManifests = $layout->recallDocumentManifests();
        $kanbanContext = (new WorkflowKanbanContextProjector($this->rootPath))->project($contract->taskId);

        $mapIndex = null;
        $mapRoot = null;
        $mapSearchIndex = null;
        $searchWarning = null;
        if ($mapReadiness->mapState === 'ready') {
            $mapIndex = $mapReadiness->mapPath;
            $mapRoot = $this->rootPath;

            if ($mapReadiness->rankedSearchReady()) {
                $mapSearchIndex = $mapReadiness->searchPath;
            } else {
                $searchWarning = 'agent-map Search is ' . $mapReadiness->searchState
                    . ' at ' . $layout->display($mapReadiness->searchPath)
                    . '; Recall compiles without ranked map evidence. Run `agent-loop map search-index build` or refresh it first.';
            }
        }

        ($this->recallCompiler)(new CompileRequest(
            learningRoot: $learningRoot,
            taskBrief: $recallInput,
            outputDirectory: $recallOutputDirectory,
            operatingPromptManifests: $operatingPromptManifests,
            documentManifests: $documentManifests,
            kanbanContextProjection: $kanbanContext,
            mapIndex: $mapIndex,
            mapRoot: $mapRoot,
            mapSearchIndex: $mapSearchIndex,
        ));

        $compiledManifestPath = (new RunManifestTransitionWriter($this->rootPath))->write($contract->taskId);

        return new WorkflowRunPreparationResult(
            $run,
            $session,
            $learningRoot,
            $preparedManifestPath,
            $compiledManifestPath,
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
        $existingPhpScope = $this->existingPhpScope($contract);
        if ($existingPhpScope === []) {
            return null;
        }
        // Every refusal below names the index agent-map judged. A repository can
        // hold more than one - a repository-local `.agent-map/` beside the
        // governed `.agent-loop/map/` - and a message that reports only which
        // files are stale leaves a host refreshing the other one and seeing no
        // change. The path is the owner's own answer, reported unchanged.
        $index = ' (index: ' . $readiness->mapPath . ')';
        if ($readiness->mapState === 'missing') {
            return new DiscoveryRepair(
                'Existing PHP scope requires agent-map discovery before governed preparation: '
                . implode(', ', $existingPhpScope) . $index . '.',
                'agent-loop map build --paths=src,tests',
            );
        }
        if ($readiness->mapState === 'invalid') {
            return new DiscoveryRepair(
                'Existing PHP scope requires a readable agent-map snapshot before governed preparation: '
                . ($readiness->mapFailure ?? 'agent-map reported an invalid snapshot') . $index . '.',
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
                . ')' . $index . '.',
                'agent-loop map refresh',
            );
        }

        $map = $readiness->currentMap();
        if ($map === null) {
            return new DiscoveryRepair(
                'agent-map reported a ready snapshot without a readable current map' . $index . '.',
                'agent-loop map build --paths=src,tests',
            );
        }

        $missingScope = [];
        foreach ($existingPhpScope as $relative) {
            if ($map->file($relative) === null) {
                $missingScope[] = $relative;
            }
        }
        if ($missingScope !== []) {
            return new DiscoveryRepair(
                'Existing PHP scope is not covered by a fresh agent-map snapshot before governed preparation (scope not indexed: '
                . implode(', ', $missingScope) . ')' . $index . '.',
                'agent-loop map refresh',
            );
        }

        return null;
    }

    /** @return list<string> */
    private function existingPhpScope(TaskContract $contract): array
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

        return array_keys($existingPhpScope);
    }

    private function mapReadiness(): MapReadiness
    {
        $layout = new ProjectLayout($this->rootPath);

        return (new MapReadinessInspector())->inspect(
            MapArtifactPaths::forProject($this->rootPath, $layout->mapRoot()),
            false,
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
                // A Run binds to exactly one Session id, and the branch below already recovers a Session
                // that was pruned away entirely. A Session that is merely closed carries strictly more
                // information than a pruned one, so refusing it here would seal the Run: work that
                // legitimately continues after a finish - a follow-up change demanded by the closing
                // Run's own review gate, for example - could reuse neither the bound Session nor a
                // freshly started one. SessionStore::reopen() keeps that narrow: only a Session closed
                // as done reopens, and it refuses while another open Session exists for the task.
                return $sessions->reopen(
                    $sessions->load($sessionsRoot, $boundRun->sessionId),
                    sprintf('Governed Run %s continues after its Session was closed.', $boundRun->runId),
                );
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
