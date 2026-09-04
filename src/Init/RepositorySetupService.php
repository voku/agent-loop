<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\PackageResources;
use voku\AgentLoop\ProjectLayout;

/**
 * Typed owner boundary for repository/host setup state and safe mutations.
 *
 * CLI adapters and human-facing hosts consume this service instead of
 * reconstructing init semantics from rendered command output or manifests.
 */
final readonly class RepositorySetupService
{
    public function __construct(
        private string $rootPath,
        private ?HostRuntimeProbe $runtimeProbe = null,
    ) {
    }

    public function diagnostics(?AgentAssetSourcePaths $paths = null): RepositorySetupDiagnostics
    {
        return (new RepositorySetupDiagnosticsInspector($this->rootPath, $this->runtimeProbe))
            ->inspect($paths ?? $this->defaultSourcePaths());
    }

    /** @return list<ManagedAssetDriftProjection> */
    public function managedAssetDrift(?AgentAssetSourcePaths $paths = null): array
    {
        return (new ManagedAssetDriftProjector())->project(
            new ManagedAssetTargetCatalog($this->rootPath),
            $paths ?? $this->defaultSourcePaths(),
        );
    }

    /** Exactly what an install would change, before anything is written. */
    public function planInstall(string $agent, bool $withHooks = false, ?AgentAssetSourcePaths $paths = null): ManagedAssetChangePlan
    {
        $resolved = $paths ?? $this->defaultSourcePaths();
        $host = $this->requireSupportedHost($agent);
        if ($withHooks && !in_array($host, ['codex', 'claude'], true)) {
            throw new InvalidArgumentException('Executable host hooks are only supported for codex or claude.');
        }

        $drift = $this->managedAssetDrift($resolved);
        $assetPlan = (new ManagedAssetPlanner())->planInstall($host, $withHooks, $drift);
        $instructionPlan = (new RepositoryInstructionSynchronizer($this->rootPath))->plan($host);
        $operations = $assetPlan->operations;
        $blocked = $assetPlan->blocked;
        foreach ($instructionPlan as $operation) {
            if ($operation->operation === ManagedAssetOperationKind::BLOCKED) {
                $blocked[] = $operation;
            } else {
                $operations[] = $operation;
            }
        }

        return new ManagedAssetChangePlan(
            ManagedAssetChangePlan::INTENT_INSTALL,
            $host,
            $withHooks,
            RepositorySetupStateToken::fromDriftProjections(
                $drift,
                $this->installStateFiles($host, $withHooks, $resolved),
            ),
            $operations,
            $blocked,
        );
    }

    /**
     * Applies only the exact typed plan the caller reviewed, and only while the
     * repository/source evidence bound into the plan remains current.
     *
     * @throws StaleRepositorySetupPlan
     */
    public function install(
        ManagedAssetChangePlan $plan,
        string $expectedState,
        ?AgentAssetSourcePaths $paths = null,
    ): ManagedAssetMutationResult {
        if ($plan->intent !== ManagedAssetChangePlan::INTENT_INSTALL) {
            throw new InvalidArgumentException('Expected an install setup plan.');
        }

        $resolved = $paths ?? $this->defaultSourcePaths();
        $this->assertCurrent($plan, $expectedState, $resolved);
        if ($plan->blocked !== []) {
            return new ManagedAssetMutationResult(
                $plan,
                false,
                [],
                $plan->blocked,
                ['Install was not applied because the reviewed plan contains blocked operations.'],
                $this->installStateToken($plan->agent, $plan->withHooks, $resolved),
            );
        }

        $outcome = (new RepositoryManagedAssetInstaller($this->rootPath))->apply($plan, $resolved);

        return new ManagedAssetMutationResult(
            $plan,
            $outcome['blocked'] === [],
            $outcome['applied'],
            $outcome['blocked'],
            $outcome['messages'],
            $this->installStateToken($plan->agent, $plan->withHooks, $resolved),
        );
    }

    /** Exactly what an uninstall would remove, and what it refuses to touch. */
    public function planUninstall(string $agent, bool $withHooks = false, ?AgentAssetSourcePaths $paths = null): ManagedAssetChangePlan
    {
        $resolved = $paths ?? $this->defaultSourcePaths();
        $host = $this->requireSupportedHost($agent);
        $drift = $this->managedAssetDrift($resolved);
        $assetPlan = (new ManagedAssetPlanner())->planUninstall($host, $withHooks, $drift);
        $instructionPlan = (new RepositoryInstructionSynchronizer($this->rootPath))->planUninstall($host);
        $operations = $assetPlan->operations;
        $blocked = $assetPlan->blocked;
        foreach ($instructionPlan as $operation) {
            if ($operation->operation === ManagedAssetOperationKind::BLOCKED) {
                $blocked[] = $operation;
            } else {
                $operations[] = $operation;
            }
        }

        return new ManagedAssetChangePlan(
            ManagedAssetChangePlan::INTENT_UNINSTALL,
            $host,
            $withHooks,
            RepositorySetupStateToken::fromDriftProjections(
                $drift,
                (new RepositoryInstructionSynchronizer($this->rootPath))->stateFiles($host),
            ),
            $operations,
            $blocked,
        );
    }

    /**
     * Removes only what the given plan authorised, and only if the repository
     * still looks the way the plan was computed against.
     *
     * @throws StaleRepositorySetupPlan
     */
    public function uninstall(
        ManagedAssetChangePlan $plan,
        string $expectedState,
        ?AgentAssetSourcePaths $paths = null,
    ): ManagedAssetMutationResult {
        if ($plan->intent !== ManagedAssetChangePlan::INTENT_UNINSTALL) {
            throw new InvalidArgumentException('Expected an uninstall setup plan.');
        }

        $resolved = $paths ?? $this->defaultSourcePaths();
        $this->assertCurrent($plan, $expectedState, $resolved);

        $instructionOutcome = (new RepositoryInstructionSynchronizer($this->rootPath))->applyUninstall($plan);
        $assetOperations = array_values(array_filter(
            $plan->operations,
            static fn (ManagedAssetOperation $operation): bool => $operation->kind !== ManagedAssetKind::INSTRUCTIONS,
        ));
        $assetPlan = new ManagedAssetChangePlan(
            $plan->intent,
            $plan->agent,
            $plan->withHooks,
            $plan->expectedState,
            $assetOperations,
            [],
        );
        $assetOutcome = (new ManagedAssetUninstaller())->apply($assetPlan);
        $applied = [...$instructionOutcome['applied'], ...$assetOutcome['applied']];
        $runtimeBlocked = [...$instructionOutcome['blocked'], ...$assetOutcome['blocked']];

        return new ManagedAssetMutationResult(
            $plan,
            $runtimeBlocked === [] || $applied !== [],
            $applied,
            [...$plan->blocked, ...$runtimeBlocked],
            [...$instructionOutcome['messages'], ...$assetOutcome['messages']],
            RepositorySetupStateToken::fromDriftProjections(
                $this->managedAssetDrift($resolved),
                (new RepositoryInstructionSynchronizer($this->rootPath))->stateFiles($plan->agent),
            ),
        );
    }

    /** Repository-owned policy projection only; host/user settings stay outside this boundary. */
    public function syncPolicy(string $agent): RepositorySetupProjection
    {
        $host = $this->requireSupportedHost($agent);
        if (!in_array($host, HostPolicyProjector::supportedAgents(), true)) {
            throw new InvalidArgumentException('Repository policy projection is not supported for host: ' . $host);
        }

        (new HostPolicyProjector($this->rootPath))->sync($host);

        return $this->overview($host);
    }

    /** Applies only repository-declared local Git integration. */
    public function syncGitIntegration(): RepositorySetupProjection
    {
        (new RepositoryGitIntegrationSynchronizer($this->rootPath))->syncDeclared();

        return $this->overview();
    }

    /** @return list<RepositorySetupOperation> */
    public function legalOperations(?string $requestedAgent = null, ?AgentAssetSourcePaths $paths = null): array
    {
        $projection = $this->overview($requestedAgent);
        $host = $projection->host;
        if ($host === null || $projection->integration === null) {
            return [RepositorySetupOperation::HOST_USER_ACTION];
        }

        $resolved = $paths ?? $this->defaultSourcePaths();
        $operations = [];

        $installPlan = $this->planInstall($host, false, $resolved);
        foreach ($installPlan->operations as $operation) {
            if ($operation->operation === ManagedAssetOperationKind::ADD) {
                $operations[] = RepositorySetupOperation::INSTALL_ASSETS;
                break;
            }
        }
        foreach ($installPlan->operations as $operation) {
            if ($operation->operation === ManagedAssetOperationKind::UPDATE) {
                $operations[] = RepositorySetupOperation::UPDATE_ASSETS;
                break;
            }
        }
        if ($this->planUninstall($host, false, $resolved)->mutates()) {
            $operations[] = RepositorySetupOperation::REMOVE_ASSETS;
        }
        if ($installPlan->blocked !== []) {
            $operations[] = RepositorySetupOperation::REVIEW_CONFLICT;
        }

        if ($projection->integration->policy === RepositorySetupIntegrationState::MISSING) {
            $operations[] = RepositorySetupOperation::SYNC_POLICY;
        }
        if (in_array(
            $projection->integration->policy,
            [RepositorySetupIntegrationState::CONFLICT, RepositorySetupIntegrationState::MANUAL],
            true,
        )) {
            $operations[] = RepositorySetupOperation::REVIEW_CONFLICT;
        }
        if ($projection->integration->gitIntegration === RepositorySetupIntegrationState::MISSING) {
            $operations[] = RepositorySetupOperation::SYNC_GIT_INTEGRATION;
        }
        if ($projection->runtimeBoundary !== null) {
            $operations[] = RepositorySetupOperation::HOST_USER_ACTION;
        }

        return array_values(array_unique($operations, SORT_REGULAR));
    }

    /** @throws StaleRepositorySetupPlan */
    private function assertCurrent(
        ManagedAssetChangePlan $plan,
        string $expectedState,
        AgentAssetSourcePaths $paths,
    ): void {
        if (!$plan->expectedState->matches($expectedState)) {
            throw new StaleRepositorySetupPlan($plan->expectedState->value, $expectedState);
        }

        $observed = $plan->intent === ManagedAssetChangePlan::INTENT_INSTALL
            ? $this->installStateToken($plan->agent, $plan->withHooks, $paths)
            : RepositorySetupStateToken::fromDriftProjections(
                $this->managedAssetDrift($paths),
                (new RepositoryInstructionSynchronizer($this->rootPath))->stateFiles($plan->agent),
            );
        if (!$observed->matches($plan->expectedState->value)) {
            throw new StaleRepositorySetupPlan($plan->expectedState->value, $observed->value);
        }
    }

    private function installStateToken(string $agent, bool $withHooks, AgentAssetSourcePaths $paths): RepositorySetupStateToken
    {
        return RepositorySetupStateToken::fromDriftProjections(
            $this->managedAssetDrift($paths),
            $this->installStateFiles($agent, $withHooks, $paths),
        );
    }

    /** @return list<string> */
    private function installStateFiles(string $agent, bool $withHooks, AgentAssetSourcePaths $paths): array
    {
        $packageRoot = dirname(__DIR__, 2);
        $files = (new RepositoryInstructionSynchronizer($this->rootPath))->stateFiles($agent);
        $files[] = PackageResources::projectInstructions();

        $sourceRoots = [
            $paths->absoluteSkillsRoot(),
            ...FirstPartySkillRoots::resolve($packageRoot),
            $paths->absoluteSubagentsRoot(),
        ];
        if ($withHooks && $agent === 'codex') {
            $sourceRoots[] = $paths->absoluteHooksRoot();
        }
        if ($withHooks && $agent === 'claude') {
            $sourceRoots[] = $paths->absoluteClaudeHooksRoot();
        }

        foreach (array_values(array_unique($sourceRoots)) as $root) {
            array_push($files, ...$this->sourceFiles($root));
        }

        $files = array_values(array_unique($files));
        sort($files, SORT_STRING);

        return $files;
    }

    /** @return list<string> */
    private function sourceFiles(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $files[] = $item->getPathname();
            }
        }

        return $files;
    }

    /** @return non-empty-string */
    private function requireSupportedHost(string $agent): string
    {
        return InitAgent::parse($agent, InitAgent::canonicalNames())->canonicalName();
    }

    private function defaultSourcePaths(): AgentAssetSourcePaths
    {
        $layout = new ProjectLayout($this->rootPath);
        $canonicalConfig = $layout->configPath();
        $config = (new InitConfigLoader($this->rootPath))->load(
            is_file($canonicalConfig) ? $layout->display($canonicalConfig) : null,
        );

        return AgentAssetSourcePaths::fromSources($this->rootPath, $config['paths'], []);
    }

    public function overview(?string $requestedAgent = null): RepositorySetupProjection
    {
        $probe = $this->runtimeProbe ?? new HostRuntimeProbe();
        $selection = $this->selectHost($requestedAgent, $probe);
        if ($selection['host'] === null) {
            return new RepositorySetupProjection(
                host: null,
                selection: $selection['selection'],
                runtime: null,
                integration: null,
                policyDetail: null,
                policyPath: null,
                runtimeBoundary: null,
                nextActionKind: RepositorySetupNextActionKind::DECISION_REQUIRED,
                nextAction: $selection['decision'],
            );
        }

        $host = $selection['host'];
        $runtime = $probe->probe($host);
        $policy = $this->policyStatus($host);
        $git = $this->gitIntegration();
        $integration = new RepositorySetupIntegration(
            instructions: (new InitSyncInstructionsCommand($this->rootPath))->isCurrentFor($host)
                ? RepositorySetupIntegrationState::READY
                : RepositorySetupIntegrationState::MISSING,
            skills: $this->manifestReady($this->skillsRoot($host), 'skills', $host, $this->expectedSkillEntries())
                ? RepositorySetupIntegrationState::READY
                : RepositorySetupIntegrationState::MISSING,
            subagents: $this->manifestReady($this->subagentsRoot($host), 'subagents', $host, $this->expectedSubagentEntries($host))
                ? RepositorySetupIntegrationState::READY
                : RepositorySetupIntegrationState::MISSING,
            policy: RepositorySetupIntegrationState::from($policy['status']),
            gitIntegration: RepositorySetupIntegrationState::from($git['status']),
        );
        $next = $this->nextAction($host, $integration, $policy, $git['action']);

        return new RepositorySetupProjection(
            host: $host,
            selection: $selection['selection'],
            runtime: new RepositorySetupRuntime(
                status: RepositorySetupRuntimeState::from($runtime['status']),
                command: $runtime['command'],
                path: $runtime['path'],
            ),
            integration: $integration,
            policyDetail: $policy['detail'],
            policyPath: $policy['path'],
            runtimeBoundary: $this->runtimeBoundary($host),
            nextActionKind: $next['kind'],
            nextAction: $next['action'],
        );
    }

    /**
     * @return array{host:non-empty-string|null,selection:RepositorySetupSelection,decision:non-empty-string|null}
     */
    private function selectHost(?string $requestedAgent, HostRuntimeProbe $probe): array
    {
        if ($requestedAgent !== null) {
            try {
                $agent = InitAgent::parse($requestedAgent, InitAgent::canonicalNames());
            } catch (InvalidArgumentException $exception) {
                $decision = $exception->getMessage();

                return [
                    'host' => null,
                    'selection' => RepositorySetupSelection::MISSING,
                    'decision' => $decision === ''
                        ? 'Pass --agent=<' . implode('|', InitAgent::canonicalNames()) . '> because the requested host could not be resolved.'
                        : $decision,
                ];
            }

            return [
                'host' => $agent->canonicalName(),
                'selection' => RepositorySetupSelection::EXPLICIT,
                'decision' => null,
            ];
        }

        $available = [];
        foreach (InitAgent::canonicalNames() as $agent) {
            if ($probe->probe($agent)['status'] === 'available') {
                $available[] = $agent;
            }
        }

        if (count($available) === 1) {
            return [
                'host' => $available[0],
                'selection' => RepositorySetupSelection::AUTO,
                'decision' => null,
            ];
        }

        $canonical = implode('|', InitAgent::canonicalNames());
        if ($available === []) {
            return [
                'host' => null,
                'selection' => RepositorySetupSelection::MISSING,
                'decision' => 'Pass --agent=<' . $canonical . '> because no probed coding-host executable is visible on PATH. Hosts without a stable runtime probe must be selected explicitly.',
            ];
        }

        return [
            'host' => null,
            'selection' => RepositorySetupSelection::AMBIGUOUS,
            'decision' => 'Pass --agent=<' . implode('|', $available) . '> because multiple coding-host executables are visible on PATH.',
        ];
    }

    /**
     * @return array{status:'ready'|'missing'|'conflict'|'manual'|'unsupported',path:non-empty-string|null,detail:non-empty-string}
     */
    private function policyStatus(string $host): array
    {
        if (!in_array($host, HostPolicyProjector::supportedAgents(), true)) {
            return [
                'status' => 'unsupported',
                'path' => null,
                'detail' => 'agent-loop has no repository policy projector for ' . $host . '; authority controls remain host/user owned',
            ];
        }

        return (new HostPolicyProjector($this->rootPath))->inspect($host);
    }

    /** @param list<string> $desiredEntries */
    private function manifestReady(string $targetRoot, string $kind, string $agent, array $desiredEntries): bool
    {
        if ($desiredEntries === []) {
            throw new RuntimeException('Expected managed ' . $kind . ' entries are unavailable for host inspection.');
        }

        $manifestPath = rtrim($targetRoot, '/') . '/' . InitSyncManifest::fileName();
        if (!is_file($manifestPath)) {
            return false;
        }

        $manifest = InitSyncManifest::load($targetRoot, $kind, $agent);
        if (!$manifest->hasDriftEvidence()) {
            return false;
        }

        foreach ($desiredEntries as $entry) {
            if (!$manifest->isManaged($entry)) {
                return false;
            }
        }

        $states = ManagedAssetDriftInspector::inspect($manifest, $targetRoot, $agent, $desiredEntries);

        return $states['locally_modified'] === []
            && $states['stale'] === []
            && $states['incompatible'] === []
            && $states['unverifiable'] === [];
    }

    /** @return list<string> */
    private function expectedSkillEntries(): array
    {
        $packageRoot = dirname(__DIR__, 2);
        $roots = FirstPartySkillRoots::resolve($packageRoot);

        $entries = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                throw new RuntimeException('First-party skills root is missing: ' . $root);
            }
            foreach (scandir($root) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (is_file($root . '/' . $entry . '/SKILL.md')) {
                    $entries[] = $entry;
                }
            }
        }

        $entries = array_values(array_unique($entries));
        sort($entries, SORT_STRING);
        if ($entries === []) {
            throw new RuntimeException('No first-party skill entries are available for host inspection.');
        }

        return $entries;
    }

    /** @return list<string> */
    private function expectedSubagentEntries(string $host): array
    {
        $root = PackageResources::subagentsRoot();
        if (!is_dir($root)) {
            throw new RuntimeException('Bundled subagents root is missing: ' . $root);
        }

        $suffix = (new ManagedAssetTargetCatalog($this->rootPath))->subagentSuffix($host);
        $entries = [];
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !str_ends_with($entry, '.md')) {
                continue;
            }
            if (is_file($root . '/' . $entry)) {
                $entries[] = substr($entry, 0, -3) . $suffix;
            }
        }

        sort($entries, SORT_STRING);
        if ($entries === []) {
            throw new RuntimeException('No bundled subagent entries are available for host inspection.');
        }

        return $entries;
    }

    /**
     * @param array{status:'ready'|'missing'|'conflict'|'manual'|'unsupported',path:non-empty-string|null,detail:non-empty-string} $policy
     * @param non-empty-string|null $gitIntegrationAction
     * @return array{kind:RepositorySetupNextActionKind,action:non-empty-string|null}
     */
    private function nextAction(
        string $host,
        RepositorySetupIntegration $integration,
        array $policy,
        ?string $gitIntegrationAction,
    ): array {
        if (
            $integration->instructions === RepositorySetupIntegrationState::MISSING
            || $integration->skills === RepositorySetupIntegrationState::MISSING
            || $integration->subagents === RepositorySetupIntegrationState::MISSING
        ) {
            return [
                'kind' => RepositorySetupNextActionKind::COMMAND,
                'action' => (new RepositoryActivation($this->rootPath))->cliPath() . ' init install-assets --agent=' . $host,
            ];
        }

        if ($integration->policy === RepositorySetupIntegrationState::MISSING) {
            return [
                'kind' => RepositorySetupNextActionKind::COMMAND,
                'action' => (new RepositoryActivation($this->rootPath))->cliPath() . ' init sync-policy --agent=' . $host,
            ];
        }

        if (in_array(
            $integration->policy,
            [RepositorySetupIntegrationState::CONFLICT, RepositorySetupIntegrationState::MANUAL],
            true,
        )) {
            $path = $policy['path'] === null ? '' : '; review ' . $policy['path'];

            return [
                'kind' => RepositorySetupNextActionKind::HOST_WORK,
                'action' => $policy['detail'] . $path . ' and preserve project-owned configuration before retrying.',
            ];
        }

        if ($integration->gitIntegration === RepositorySetupIntegrationState::MISSING && $gitIntegrationAction !== null) {
            return ['kind' => RepositorySetupNextActionKind::COMMAND, 'action' => $gitIntegrationAction];
        }

        return ['kind' => RepositorySetupNextActionKind::NONE, 'action' => null];
    }

    /** @return array{status:'ready'|'missing'|'not_declared',action:non-empty-string|null} */
    private function gitIntegration(): array
    {
        $activation = new RepositoryActivation($this->rootPath);
        $checks = $activation->localGitIntegrationChecks();
        if ($checks === []) {
            return ['status' => 'not_declared', 'action' => null];
        }

        foreach ($checks as $check) {
            if (str_starts_with($check->render(), '[' . InitCheckLevel::WARN . ']')) {
                return ['status' => 'missing', 'action' => $activation->syncGitHooksCommand()];
            }
        }

        return ['status' => 'ready', 'action' => null];
    }

    /** @return non-empty-string */
    private function runtimeBoundary(string $host): string
    {
        return match ($host) {
            'claude' => HostPolicyProjector::claudeUserScopeAction(),
            'codex' => 'Codex loads project rules from the trusted project config layer. Repository policy can prepare .codex/rules, but trusting the project remains an explicit host/user decision.',
            'opencode' => 'OpenCode --auto automatically approves ask decisions. The projected agent-loop remote-mutation rules use deny because deny remains effective under --auto.',
            'copilot' => 'agent-loop projects portable instructions, skills, and agents for Copilot, but does not claim a repository-native authority policy projector for this host.',
            'gemini' => 'agent-loop projects portable instructions, skills, and agents for Gemini CLI, but does not claim a repository-native authority policy projector for this host.',
            'antigravity' => 'agent-loop projects portable instructions, skills, and agents for Antigravity. Runtime auto-detection is unavailable, and authority controls remain host/user owned.',
            default => throw new InvalidArgumentException('Unsupported self-discovery host: ' . $host),
        };
    }

    private function skillsRoot(string $host): string
    {
        return (new ManagedAssetTargetCatalog($this->rootPath))->skillsTargetRoot($host);
    }

    private function subagentsRoot(string $host): string
    {
        return (new ManagedAssetTargetCatalog($this->rootPath))->subagentsTargetRoot($host);
    }
}
