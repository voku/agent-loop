<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use voku\AgentLoop\Cli\OptionTokens;
use voku\AgentLoop\PathResolver;

/**
 * Discovers the active coding host and reports the one repository-owned action
 * required to converge its agent-loop integration.
 *
 * Host/user-owned boundaries such as Claude Auto Mode and Codex project trust
 * are reported separately and never masquerade as repository mutations.
 */
final readonly class InitHostStatusCommand
{
    public function __construct(
        private string $rootPath,
        private ?HostRuntimeProbe $runtimeProbe = null,
    ) {
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        $argumentError = $this->validateTokens($tokens);
        if ($argumentError !== null) {
            fwrite(\STDERR, $argumentError . "\n");

            return 1;
        }

        $format = OptionTokens::value($tokens, 'format') ?? 'text';
        $requestedAgent = OptionTokens::value($tokens, 'agent');
        try {
            $status = $this->buildStatus($requestedAgent);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            fwrite(\STDERR, '[FAIL] init host-status: ' . $exception->getMessage() . "\n");

            return 1;
        }

        if ($format === 'json') {
            try {
                echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            } catch (JsonException $exception) {
                fwrite(\STDERR, 'Unable to encode host status: ' . $exception->getMessage() . "\n");

                return 1;
            }

            return 0;
        }

        $this->renderText($status);

        return 0;
    }

    /**
     * @return array{
     *     schema_version: 1,
     *     host: non-empty-string|null,
     *     selection: 'explicit'|'auto'|'ambiguous'|'missing',
     *     runtime: array{status: 'available'|'missing'|'unprobed', command: non-empty-string|null, path: non-empty-string|null}|null,
     *     integration: array{instructions: 'ready'|'missing', skills: 'ready'|'missing', subagents: 'ready'|'missing', policy: 'ready'|'missing'|'conflict'|'manual'|'unsupported'}|null,
     *     policy_detail: non-empty-string|null,
     *     policy_path: non-empty-string|null,
     *     runtime_boundary: non-empty-string|null,
     *     next_action_kind: 'command'|'host_work'|'decision_required'|'none',
     *     next_action: non-empty-string|null
     * }
     */
    private function buildStatus(?string $requestedAgent): array
    {
        $probe = $this->runtimeProbe ?? new HostRuntimeProbe();
        $selection = $this->selectHost($requestedAgent, $probe);
        if ($selection['host'] === null) {
            return [
                'schema_version' => 1,
                'host' => null,
                'selection' => $selection['selection'],
                'runtime' => null,
                'integration' => null,
                'policy_detail' => null,
                'policy_path' => null,
                'runtime_boundary' => null,
                'next_action_kind' => 'decision_required',
                'next_action' => $selection['decision'],
            ];
        }

        $host = $selection['host'];
        $runtime = $probe->probe($host);
        $policy = $this->policyStatus($host);
        $integration = [
            'instructions' => (new InitSyncInstructionsCommand($this->rootPath))->isCurrentFor($host) ? 'ready' : 'missing',
            'skills' => $this->manifestReady($this->skillsRoot($host), 'skills', $host, $this->expectedSkillEntries()) ? 'ready' : 'missing',
            'subagents' => $this->manifestReady($this->subagentsRoot($host), 'subagents', $host, $this->expectedSubagentEntries($host)) ? 'ready' : 'missing',
            'policy' => $policy['status'],
        ];

        $next = $this->nextAction($host, $integration, $policy);

        return [
            'schema_version' => 1,
            'host' => $host,
            'selection' => $selection['selection'],
            'runtime' => $runtime,
            'integration' => $integration,
            'policy_detail' => $policy['detail'],
            'policy_path' => $policy['path'],
            'runtime_boundary' => $this->runtimeBoundary($host),
            'next_action_kind' => $next['kind'],
            'next_action' => $next['action'],
        ];
    }

    /**
     * @return array{
     *     host: non-empty-string|null,
     *     selection: 'explicit'|'auto'|'ambiguous'|'missing',
     *     decision: non-empty-string|null
     * }
     */
    private function selectHost(?string $requestedAgent, HostRuntimeProbe $probe): array
    {
        if ($requestedAgent !== null) {
            try {
                $agent = InitAgent::parse($requestedAgent, InitAgent::canonicalNames());
            } catch (InvalidArgumentException $exception) {
                return [
                    'host' => null,
                    'selection' => 'missing',
                    'decision' => $exception->getMessage(),
                ];
            }

            return [
                'host' => $agent->canonicalName(),
                'selection' => 'explicit',
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
                'selection' => 'auto',
                'decision' => null,
            ];
        }

        $canonical = implode('|', InitAgent::canonicalNames());
        if ($available === []) {
            return [
                'host' => null,
                'selection' => 'missing',
                'decision' => 'Pass --agent=<' . $canonical . '> because no probed coding-host executable is visible on PATH. Hosts without a stable runtime probe must be selected explicitly.',
            ];
        }

        return [
            'host' => null,
            'selection' => 'ambiguous',
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
     * @param array{instructions: 'ready'|'missing', skills: 'ready'|'missing', subagents: 'ready'|'missing', policy: 'ready'|'missing'|'conflict'|'manual'|'unsupported'} $integration
     * @param array{status: 'ready'|'missing'|'conflict'|'manual'|'unsupported', path: non-empty-string|null, detail: non-empty-string} $policy
     * @return array{kind: 'command'|'host_work'|'decision_required'|'none', action: non-empty-string|null}
     */
    private function nextAction(string $host, array $integration, array $policy): array
    {
        if (
            $integration['instructions'] === 'missing'
            || $integration['skills'] === 'missing'
            || $integration['subagents'] === 'missing'
        ) {
            return [
                'kind' => 'command',
                'action' => (new RepositoryActivation($this->rootPath))->cliPath() . ' init install-assets --agent=' . $host,
            ];
        }

        if ($integration['policy'] === 'missing') {
            return [
                'kind' => 'command',
                'action' => (new RepositoryActivation($this->rootPath))->cliPath() . ' init sync-policy --agent=' . $host,
            ];
        }

        if (in_array($integration['policy'], ['conflict', 'manual'], true)) {
            $path = $policy['path'] === null ? '' : '; review ' . $policy['path'];

            return [
                'kind' => 'host_work',
                'action' => $policy['detail'] . $path . ' and preserve project-owned configuration before retrying.',
            ];
        }

        return ['kind' => 'none', 'action' => null];
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

    /**
     * @param array{
     *     schema_version: 1,
     *     host: non-empty-string|null,
     *     selection: 'explicit'|'auto'|'ambiguous'|'missing',
     *     runtime: array{status: 'available'|'missing'|'unprobed', command: non-empty-string|null, path: non-empty-string|null}|null,
     *     integration: array{instructions: 'ready'|'missing', skills: 'ready'|'missing', subagents: 'ready'|'missing', policy: 'ready'|'missing'|'conflict'|'manual'|'unsupported'}|null,
     *     policy_detail: non-empty-string|null,
     *     policy_path: non-empty-string|null,
     *     runtime_boundary: non-empty-string|null,
     *     next_action_kind: 'command'|'host_work'|'decision_required'|'none',
     *     next_action: non-empty-string|null
     * } $status
     */
    private function renderText(array $status): void
    {
        echo "agent-loop init host-status\n\n";
        echo 'Host: ' . ($status['host'] ?? 'unresolved') . ' (' . $status['selection'] . ")\n";
        if ($status['runtime'] !== null) {
            echo 'Runtime: ' . $status['runtime']['status'];
            if ($status['runtime']['path'] !== null) {
                echo ' (' . $status['runtime']['path'] . ')';
            }
            echo "\n";
        }
        if ($status['integration'] !== null) {
            echo 'Integration: instructions=' . $status['integration']['instructions']
                . ', skills=' . $status['integration']['skills']
                . ', subagents=' . $status['integration']['subagents']
                . ', policy=' . $status['integration']['policy'] . "\n";
        }
        if ($status['runtime_boundary'] !== null) {
            echo 'Runtime boundary: ' . $status['runtime_boundary'] . "\n";
        }
        echo 'next_action_kind=' . $status['next_action_kind'] . "\n";
        echo 'next_action=' . ($status['next_action'] ?? 'none') . "\n";
    }

    /** @param list<string> $tokens */
    private function validateTokens(array $tokens): ?string
    {
        $valueOptions = ['agent', 'format'];
        $count = count($tokens);

        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (!str_starts_with($token, '--')) {
                return 'Unknown init host-status argument: ' . $token;
            }

            $normalized = strtok(substr($token, 2), '=');
            if (!is_string($normalized) || !in_array($normalized, $valueOptions, true)) {
                return 'Unknown init host-status option: --' . (is_string($normalized) ? $normalized : '');
            }

            $value = str_contains($token, '=')
                ? substr($token, strpos($token, '=') + 1)
                : ($tokens[$index + 1] ?? null);
            if (!is_string($value) || $value === '' || str_starts_with($value, '--')) {
                return 'Missing value for init host-status option: --' . $normalized;
            }
            if (!str_contains($token, '=')) {
                ++$index;
            }

            if ($normalized === 'format' && !in_array($value, ['text', 'json'], true)) {
                return 'Unknown init host-status format: ' . $value;
            }
        }

        return null;
    }
}
