<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\ProjectLayout;

/**
 * Typed owner boundary for repository/host setup state and safe mutations.
 *
 * Read methods are mutation-free. Mutations revalidate owner state immediately
 * before writing, so a stale browser view never becomes write authority.
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

    public function planInstall(string $agent, bool $withHooks = false, ?AgentAssetSourcePaths $paths = null): ManagedAssetChangePlan
    {
        $resolved = $paths ?? $this->defaultSourcePaths();
        $host = $this->requireSupportedHost($agent);
        if ($withHooks && !in_array($host, ['codex', 'claude'], true)) {
            throw new InvalidArgumentException('Executable host hooks are only supported for codex or claude.');
        }

        $drift = $this->managedAssetDrift($resolved);
        $assetPlan = (new ManagedAssetPlanner())->planInstall($host, $withHooks, $drift);
        $instructions = (new RepositoryInstructionSynchronizer($this->rootPath))->plan($host);
        $operations = $assetPlan->operations;
        $blocked = $assetPlan->blocked;
        foreach ($instructions as $operation) {
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
     * Applies only the exact typed plan that was reviewed and only while its
     * state token is still current. Any blocked operation makes install
     * fail-closed before the first write.
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

    public function planUninstall(string $agent, bool $withHooks = false, ?AgentAssetSourcePaths $paths = null): ManagedAssetChangePlan
    {
        $resolved = $paths ?? $this->defaultSourcePaths();
        $host = $this->requireSupportedHost($agent);
        $drift = $this->managedAssetDrift($resolved);
        $assetPlan = (new ManagedAssetPlanner())->planUninstall($host, $withHooks, $drift);
        $instructions = (new RepositoryInstructionSynchronizer($this->rootPath))->planUninstall($host);
        $operations = $assetPlan->operations;
        $blocked = $assetPlan->blocked;
        foreach ($instructions as $operation) {
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

    /** @throws StaleRepositorySetupPlan */
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

    private function installStateToken(
        string $agent,
        bool $withHooks,
        AgentAssetSourcePaths $paths,
    ): RepositorySetupStateToken {
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
        $files[] = $packageRoot . '/docs/agents/project-instructions.md';

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
            runtimeBoundary: $this->runtimeBoundary($host, $runtime),
            nextActionKind: $next['kind'],
            nextAction: $next['action'],
        );
    }

    /**
     * @return array{host:?string,selection:string,decision:?string}
     */
    private function selectHost(?string $requestedAgent, HostRuntimeProbe $probe): array
    {
        if ($requestedAgent !== null && trim($requestedAgent) !== '') {
            return [
                'host' => $this->requireSupportedHost($requestedAgent),
                'selection' => 'explicit',
                'decision' => null,
            ];
        }

        $detected = [];
        foreach (InitAgent::canonicalNames() as $candidate) {
            if ($probe->probe($candidate)['status'] === 'available') {
                $detected[] = $candidate;
            }
        }

        if (count($detected) === 1) {
            return ['host' => $detected[0], 'selection' => 'auto', 'decision' => null];
        }

        return [
            'host' => null,
            'selection' => $detected === [] ? 'none' : 'ambiguous',
            'decision' => $detected === []
                ? 'No supported coding host was detected. Select a host explicitly after installing it.'
                : 'Multiple supported coding hosts were detected (' . implode(', ', $detected) . '). Select one explicitly.',
        ];
    }

    /** @return array{status:string,detail:?string,path:?string} */
    private function policyStatus(string $agent): array
    {
        if (!in_array($agent, HostPolicyProjector::supportedAgents(), true)) {
            return ['status' => RepositorySetupIntegrationState::MANUAL->value, 'detail' => 'Host policy remains user-managed for this host.', 'path' => null];
        }

        $projector = new HostPolicyProjector($this->rootPath);
        $status = $projector->status($agent);

        return [
            'status' => $status['status'],
            'detail' => $status['detail'],
            'path' => $status['path'],
        ];
    }

    /** @return array{status:string,action:?string} */
    private function gitIntegration(): array
    {
        $activation = new RepositoryActivation($this->rootPath);
        if (!$activation->declaresGitHookPolicy() && !$activation->declaresCommitTemplate()) {
            return ['status' => RepositorySetupIntegrationState::NOT_DECLARED->value, 'action' => null];
        }

        $checks = $activation->localGitIntegrationChecks();
        foreach ($checks as $check) {
            if (!$check->isOk()) {
                return [
                    'status' => RepositorySetupIntegrationState::MISSING->value,
                    'action' => $activation->syncGitHooksCommand(),
                ];
            }
        }

        return ['status' => RepositorySetupIntegrationState::READY->value, 'action' => null];
    }

    /**
     * @param array{status:string,detail:?string,path:?string} $policy
     * @return array{kind:RepositorySetupNextActionKind,action:?string}
     */
    private function nextAction(
        string $host,
        RepositorySetupIntegration $integration,
        array $policy,
        ?string $gitAction,
    ): array {
        if ($integration->instructions === RepositorySetupIntegrationState::MISSING) {
            return [
                'kind' => RepositorySetupNextActionKind::REPOSITORY_ACTION,
                'action' => (new RepositoryActivation($this->rootPath))->cliPath() . ' init sync-instructions --agent=' . $host,
            ];
        }
        if ($integration->skills === RepositorySetupIntegrationState::MISSING
            || $integration->subagents === RepositorySetupIntegrationState::MISSING
        ) {
            return [
                'kind' => RepositorySetupNextActionKind::REPOSITORY_ACTION,
                'action' => (new RepositoryActivation($this->rootPath))->installAssetsCommand(),
            ];
        }
        if ($integration->policy === RepositorySetupIntegrationState::MISSING) {
            return [
                'kind' => RepositorySetupNextActionKind::REPOSITORY_ACTION,
                'action' => (new RepositoryActivation($this->rootPath))->cliPath() . ' init sync-policy --agent=' . $host,
            ];
        }
        if (in_array($integration->policy, [RepositorySetupIntegrationState::CONFLICT, RepositorySetupIntegrationState::MANUAL], true)) {
            return [
                'kind' => RepositorySetupNextActionKind::DECISION_REQUIRED,
                'action' => $policy['detail'],
            ];
        }
        if ($integration->gitIntegration === RepositorySetupIntegrationState::MISSING) {
            return [
                'kind' => RepositorySetupNextActionKind::REPOSITORY_ACTION,
                'action' => $gitAction,
            ];
        }

        return ['kind' => RepositorySetupNextActionKind::NONE, 'action' => null];
    }

    /** @param array{status:string,command:string|null,path:?string} $runtime */
    private function runtimeBoundary(string $host, array $runtime): ?RepositorySetupRuntimeBoundary
    {
        if ($runtime['status'] === 'available') {
            return null;
        }

        return new RepositorySetupRuntimeBoundary(
            kind: RepositorySetupNextActionKind::HOST_USER_ACTION,
            detail: match ($runtime['status']) {
                'missing' => 'Install or expose the ' . $host . ' executable outside repository setup.',
                'unprobed' => 'Confirm the ' . $host . ' runtime outside repository setup; this host cannot be probed automatically.',
                default => 'Confirm host runtime readiness outside repository setup.',
            },
        );
    }

    private function manifestReady(string $root, string $kind, string $host, array $expected): bool
    {
        try {
            $manifest = InitSyncManifest::load($root, $kind, $host);
        } catch (InvalidArgumentException) {
            return false;
        }

        foreach ($expected as $entry) {
            if (!$manifest->isManaged($entry)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function expectedSkillEntries(): array
    {
        return (new ManagedAssetTargetCatalog($this->rootPath))->expectedSkillEntries();
    }

    /** @return list<string> */
    private function expectedSubagentEntries(string $host): array
    {
        return (new ManagedAssetTargetCatalog($this->rootPath))->expectedSubagentEntries($host);
    }

    private function skillsRoot(string $host): string
    {
        return (new ManagedAssetTargetCatalog($this->rootPath))->skillsRoot($host);
    }

    private function subagentsRoot(string $host): string
    {
        return (new ManagedAssetTargetCatalog($this->rootPath))->subagentsRoot($host);
    }
}
