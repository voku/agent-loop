<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use RuntimeException;
use voku\AgentLoop\PathResolver;

/**
 * Typed, mutation-free owner projection for repository/host setup state.
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
     * @return array{
     *     host: non-empty-string|null,
     *     selection: RepositorySetupSelection,
     *     decision: non-empty-string|null
     * }
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
     * @return array{status: 'ready'|'missing'|'conflict'|'manual'|'unsupported', path: non-empty-string|null, detail: non-empty-string}
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
        $root = dirname(__DIR__, 2) . '/docs/agents/subagents';
        if (!is_dir($root)) {
            throw new RuntimeException('Bundled subagents root is missing: ' . $root);
        }

        $suffix = match ($host) {
            'codex' => '.toml',
            'copilot' => '.agent.md',
            'claude', 'opencode', 'gemini', 'antigravity' => '.md',
            default => throw new InvalidArgumentException('Unsupported self-discovery host: ' . $host),
        };
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
     * @param array{status: 'ready'|'missing'|'conflict'|'manual'|'unsupported', path: non-empty-string|null, detail: non-empty-string} $policy
     * @param non-empty-string|null $gitIntegrationAction
     * @return array{kind: RepositorySetupNextActionKind, action: non-empty-string|null}
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

    /** @return array{status: 'ready'|'missing'|'not_declared', action: non-empty-string|null} */
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
        return match ($host) {
            'codex' => PathResolver::fromEnvironment($this->rootPath, 'CODEX_SKILLS_DIR')
                ?? (($home = PathResolver::fromEnvironment($this->rootPath, 'CODEX_HOME')) !== null ? $home . '/skills' : $this->rootPath . '/.codex/skills'),
            'claude' => PathResolver::fromEnvironment($this->rootPath, 'CLAUDE_SKILLS_DIR') ?? $this->rootPath . '/.claude/skills',
            'opencode' => PathResolver::fromEnvironment($this->rootPath, 'OPENCODE_SKILLS_DIR') ?? $this->rootPath . '/.opencode/skills',
            'copilot' => PathResolver::fromEnvironment($this->rootPath, 'COPILOT_SKILLS_DIR') ?? $this->rootPath . '/.github/skills',
            'gemini' => PathResolver::fromEnvironment($this->rootPath, 'GEMINI_SKILLS_DIR') ?? $this->rootPath . '/.gemini/skills',
            'antigravity' => PathResolver::fromEnvironment($this->rootPath, 'ANTIGRAVITY_SKILLS_DIR') ?? $this->rootPath . '/.agents/skills',
            default => throw new InvalidArgumentException('Unsupported self-discovery host: ' . $host),
        };
    }

    private function subagentsRoot(string $host): string
    {
        return match ($host) {
            'codex' => PathResolver::fromEnvironment($this->rootPath, 'CODEX_AGENTS_DIR')
                ?? (($home = PathResolver::fromEnvironment($this->rootPath, 'CODEX_HOME')) !== null ? $home . '/agents' : $this->rootPath . '/.codex/agents'),
            'claude' => PathResolver::fromEnvironment($this->rootPath, 'CLAUDE_AGENTS_DIR') ?? $this->rootPath . '/.claude/agents',
            'opencode' => PathResolver::fromEnvironment($this->rootPath, 'OPENCODE_AGENTS_DIR') ?? $this->rootPath . '/.opencode/agents',
            'copilot' => PathResolver::fromEnvironment($this->rootPath, 'COPILOT_AGENTS_DIR') ?? $this->rootPath . '/.github/agents',
            'gemini' => PathResolver::fromEnvironment($this->rootPath, 'GEMINI_AGENTS_DIR') ?? $this->rootPath . '/.gemini/agents',
            'antigravity' => PathResolver::fromEnvironment($this->rootPath, 'ANTIGRAVITY_AGENTS_DIR') ?? $this->rootPath . '/.agents/agents',
            default => throw new InvalidArgumentException('Unsupported self-discovery host: ' . $host),
        };
    }
}
