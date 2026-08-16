<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use voku\AgentLoop\Cli\OptionTokens;
use voku\AgentLoop\PathResolver;

final readonly class InitStatusCommand
{
    /**
     * @var list<array{alias: string, canonical: string}>
     */
    private const array BUILTIN_ALIASES = [
        ['alias' => 'openai-codex', 'canonical' => 'codex'],
        ['alias' => 'claude-code', 'canonical' => 'claude'],
        ['alias' => 'github-copilot', 'canonical' => 'copilot'],
        ['alias' => 'agy', 'canonical' => 'antigravity'],
        ['alias' => 'google-antigravity', 'canonical' => 'antigravity'],
        ['alias' => 'gemini', 'canonical' => 'antigravity'],
        ['alias' => 'gemini-cli', 'canonical' => 'antigravity'],
    ];

    public function __construct(private string $rootPath)
    {
    }

    /**
     * @param list<string> $tokens
     */
    public function run(array $tokens): int
    {
        $argumentError = $this->validateTokens($tokens);
        if ($argumentError !== null) {
            fwrite(\STDERR, $argumentError . "\n");

            return 1;
        }

        $config = (new InitConfigLoader($this->rootPath))->load(OptionTokens::value($tokens, 'config'));
        foreach ($config['warnings'] as $warning) {
            echo $warning . "\n";
        }

        $paths = AgentAssetSourcePaths::fromSources($this->rootPath, $config['paths'], $this->readPathOverrides($tokens));

        echo "agent-loop init status\n\n";

        $activation = new RepositoryActivation($this->rootPath);
        $projectedAgents = $this->projectedSkillAgents();
        $gitIntegrationChecks = $activation->localGitIntegrationChecks();

        echo "Activation:\n";
        foreach ($this->buildActivationResults($activation, $projectedAgents, $gitIntegrationChecks) as $result) {
            echo $result->render() . "\n";
        }
        echo "\n";

        echo "Source paths:\n";
        foreach ($this->buildSourceResults($paths) as $result) {
            echo $result->render() . "\n";
        }
        echo "\n";

        echo "Agent aliases:\n";
        foreach (self::BUILTIN_ALIASES as $aliasDefinition) {
            echo InitCheckResult::info('alias ' . $aliasDefinition['alias'] . ' -> ' . $aliasDefinition['canonical'])->render() . "\n";
        }
        echo "\n";

        $staleLines = [];
        $driftLines = [];
        echo "Target manifests:\n";
        foreach ($this->buildManifestTargets($paths) as $target) {
            [$manifestLine, $staleLine, $targetDriftLines] = $this->reportManifestTarget($target);
            echo $manifestLine . "\n";
            if ($staleLine !== null) {
                $staleLines[] = $staleLine;
            }
            foreach ($targetDriftLines as $driftLine) {
                $driftLines[] = $driftLine;
            }
        }
        echo "\n";

        echo "Stale managed entries:\n";
        foreach ($staleLines as $staleLine) {
            echo $staleLine . "\n";
        }

        echo "\nManaged asset drift:\n";
        if ($driftLines === []) {
            echo "[INFO] no projected managed assets with drift evidence\n";
        } else {
            foreach ($driftLines as $driftLine) {
                echo $driftLine . "\n";
            }
        }

        $nextCommands = $this->buildNextCommands($activation, $projectedAgents, $gitIntegrationChecks);
        if ($nextCommands !== []) {
            echo "\nNext:\n";
            foreach ($nextCommands as $command) {
                echo '  ' . $command . "\n";
            }
        }

        return 0;
    }

    /**
     * The part of the report that decides whether an agent activates anything.
     *
     * Source presence, host projection and local Git activation are three
     * different facts, and only the first one used to be reported. A repository
     * where nothing is projected into any host - so no running agent can reach a
     * single skill - looked exactly like a healthy one.
     *
     * None of these lines claims an agent *consumed* anything: a projected skill
     * is readable by the host, which is not the same as a session having used it.
     *
     * @param list<string> $projectedAgents
     * @param list<InitCheckResult> $gitIntegrationChecks
     * @return list<InitCheckResult>
     */
    private function buildActivationResults(
        RepositoryActivation $activation,
        array $projectedAgents,
        array $gitIntegrationChecks,
    ): array {
        $results = [$activation->cliCheck()];

        $results[] = $projectedAgents === []
            ? InitCheckResult::warn(
                'Host skills: not projected for any host, so no running agent can read them; run '
                . $activation->installAssetsCommand(),
            )
            : InitCheckResult::ok('Host skills: projected for ' . implode(', ', $projectedAgents));

        return [...$results, ...$gitIntegrationChecks];
    }

    /**
     * @param list<string> $projectedAgents
     * @param list<InitCheckResult> $gitIntegrationChecks
     * @return list<string>
     */
    private function buildNextCommands(
        RepositoryActivation $activation,
        array $projectedAgents,
        array $gitIntegrationChecks,
    ): array {
        $commands = [];
        if ($projectedAgents === []) {
            $commands[] = $activation->installAssetsCommand();
        }

        foreach ($gitIntegrationChecks as $check) {
            if (str_starts_with($check->render(), '[WARN]')) {
                $commands[] = $activation->syncGitHooksCommand();

                break;
            }
        }

        return $commands;
    }

    /**
     * Hosts that currently carry a managed skill projection.
     *
     * @return list<string>
     */
    private function projectedSkillAgents(): array
    {
        $agents = [];
        foreach (InitAgent::canonicalNames() as $agent) {
            $manifestPath = rtrim($this->resolveSkillsTargetRoot($agent), '/') . '/' . InitSyncManifest::fileName();
            if (is_file($manifestPath)) {
                $agents[] = $agent;
            }
        }

        return $agents;
    }

    /**
     * @return list<InitCheckResult>
     */
    private function buildSourceResults(AgentAssetSourcePaths $paths): array
    {
        $hooksRoot = $paths->absoluteHooksRoot();
        $hooksJsonExists = is_file($hooksRoot . '/hooks.json');
        $hookScriptsCount = count($this->findHookScriptFiles($hooksRoot));

        return [
            InitCheckResult::ok('skills-root: ' . $paths->skillsRoot() . ' (' . count($this->findSkillSourceEntries($paths)) . ' skill(s))'),
            InitCheckResult::ok('subagents-root: ' . $paths->subagentsRoot() . ' (' . count($this->findSubagentSourceFiles($paths)) . ' file(s))'),
            InitCheckResult::ok('hooks-root: ' . $paths->hooksRoot() . ' (hooks.json: ' . ($hooksJsonExists ? 'yes' : 'no') . ', scripts: ' . $hookScriptsCount . ')'),
            InitCheckResult::info('tools-root: ' . $paths->toolsRoot() . ' (' . (is_dir($paths->absoluteToolsRoot()) ? 'found' : 'missing') . ')'),
        ];
    }

    /**
     * @return list<array{label: string, targetRoot: string, kind: string, agent: string, desiredEntries: list<string>|null}>
     */
    private function buildManifestTargets(AgentAssetSourcePaths $paths): array
    {
        // A projection legitimately contains the Recall consumer skills that
        // `install-assets` re-exports from agent-recall-compiler. Comparing it
        // against this repository's skills root alone reported those as stale
        // managed entries the moment the install succeeded.
        $skillsDesiredEntries = $this->findSkillSourceEntries($paths);
        foreach (FirstPartySkillRoots::recallSkillEntries() as $recallEntry) {
            if (!in_array($recallEntry, $skillsDesiredEntries, true)) {
                $skillsDesiredEntries[] = $recallEntry;
            }
        }
        sort($skillsDesiredEntries);

        return [
            ['label' => 'codex skills', 'targetRoot' => $this->resolveSkillsTargetRoot('codex'), 'kind' => 'skills', 'agent' => 'codex', 'desiredEntries' => $skillsDesiredEntries],
            ['label' => 'claude skills', 'targetRoot' => $this->resolveSkillsTargetRoot('claude'), 'kind' => 'skills', 'agent' => 'claude', 'desiredEntries' => $skillsDesiredEntries],
            ['label' => 'copilot skills', 'targetRoot' => $this->resolveSkillsTargetRoot('copilot'), 'kind' => 'skills', 'agent' => 'copilot', 'desiredEntries' => $skillsDesiredEntries],
            ['label' => 'antigravity skills', 'targetRoot' => $this->resolveSkillsTargetRoot('antigravity'), 'kind' => 'skills', 'agent' => 'antigravity', 'desiredEntries' => $skillsDesiredEntries],
            ['label' => 'codex subagents', 'targetRoot' => $this->resolveSubagentsTargetRoot('codex'), 'kind' => 'subagents', 'agent' => 'codex', 'desiredEntries' => $this->subagentsDesiredEntries($paths, '.toml')],
            ['label' => 'claude subagents', 'targetRoot' => $this->resolveSubagentsTargetRoot('claude'), 'kind' => 'subagents', 'agent' => 'claude', 'desiredEntries' => $this->subagentsDesiredEntries($paths, '.md')],
            ['label' => 'copilot subagents', 'targetRoot' => $this->resolveSubagentsTargetRoot('copilot'), 'kind' => 'subagents', 'agent' => 'copilot', 'desiredEntries' => $this->subagentsDesiredEntries($paths, '.agent.md')],
            ['label' => 'antigravity subagents', 'targetRoot' => $this->resolveSubagentsTargetRoot('antigravity'), 'kind' => 'subagents', 'agent' => 'antigravity', 'desiredEntries' => $this->subagentsDesiredEntries($paths, '.md')],
            ['label' => 'codex hooks', 'targetRoot' => $this->resolveHooksTargetRoot(), 'kind' => 'hooks', 'agent' => 'codex', 'desiredEntries' => $this->hooksDesiredEntries($paths)],
            ['label' => 'claude hooks', 'targetRoot' => $this->resolveClaudeHooksTargetRoot(), 'kind' => 'hooks', 'agent' => 'claude', 'desiredEntries' => $this->claudeHooksDesiredEntries($paths)],
        ];
    }

    /**
     * @param array{label: string, targetRoot: string, kind: string, agent: string, desiredEntries: list<string>|null} $target
     *
     * @return array{0: string, 1: ?string, 2: list<string>}
     */
    private function reportManifestTarget(array $target): array
    {
        $label = $target['label'];
        $targetRoot = $target['targetRoot'];
        $desiredEntries = $target['desiredEntries'];

        $manifestPath = rtrim($targetRoot, '/') . '/' . InitSyncManifest::fileName();
        if (!is_file($manifestPath)) {
            return [
                '[INFO] ' . $label . ': no manifest at ' . $manifestPath,
                null,
                $this->unmanagedTargetLines($label, $targetRoot, $desiredEntries),
            ];
        }

        try {
            $manifest = InitSyncManifest::load($targetRoot, $target['kind'], $target['agent']);
        } catch (InvalidArgumentException $exception) {
            return ['[WARN] ' . $label . ': ' . $exception->getMessage(), null, []];
        }

        $desiredEntries = $this->includeInstalledFirstPartyEntries($manifest, $desiredEntries);
        $managedEntryCount = count($manifest->managedEntries());
        $manifestLine = '[OK] ' . $label . ': manifest found (' . $managedEntryCount . ' managed entrie(s))';

        if ($desiredEntries === null) {
            $staleLine = '[INFO] ' . $label . ': stale entries not checked (source invalid)';
        } else {
            $staleEntries = $manifest->staleEntries($desiredEntries);
            $staleLine = $staleEntries === []
                ? '[OK] ' . $label . ': no stale managed entries'
                : '[WARN] ' . $label . ': stale managed entries: ' . implode(', ', $staleEntries);
        }

        $states = ManagedAssetDriftInspector::inspect(
            $manifest,
            $targetRoot,
            $target['agent'],
            $desiredEntries,
        );

        return [$manifestLine, $staleLine, $this->driftLines($label, $states)];
    }

    /**
     * Package-owned v2 projections carry their current source identity in the
     * manifest. An installed consumer does not need to duplicate those package
     * sources under its own docs/agents tree just to keep status truthful.
     *
     * @param list<string>|null $desiredEntries
     * @return list<string>|null
     */
    private function includeInstalledFirstPartyEntries(InitSyncManifest $manifest, ?array $desiredEntries): ?array
    {
        if ($desiredEntries === null || !$manifest->hasDriftEvidence()) {
            return $desiredEntries;
        }

        foreach ($manifest->managedEntries() as $entry) {
            $metadata = $manifest->entry($entry);
            if ($metadata === null
                || !in_array($metadata['semantic_owner'], ['voku/agent-loop', 'voku/agent-recall-compiler'], true)
                || $metadata['source_path'] === null
                || InitSyncManifest::digestPath($metadata['source_path']) === null
            ) {
                continue;
            }

            $desiredEntries[] = $entry;
        }

        $desiredEntries = array_values(array_unique($desiredEntries));
        sort($desiredEntries, SORT_STRING);

        return $desiredEntries;
    }

    /**
     * @param list<string>|null $desiredEntries
     * @return list<string>
     */
    private function unmanagedTargetLines(string $label, string $targetRoot, ?array $desiredEntries): array
    {
        if ($desiredEntries === null) {
            return [];
        }

        $projectOwned = [];
        foreach ($desiredEntries as $entry) {
            if (InitSyncManifest::representationDigest($targetRoot, $entry) !== null) {
                $projectOwned[] = $entry;
            }
        }
        if ($projectOwned === []) {
            return [];
        }

        sort($projectOwned, SORT_STRING);

        return ['[INFO] ' . $label . ': unmanaged/project-owned: ' . implode(', ', $projectOwned)];
    }

    /**
     * @param array{
     *     current:list<string>,
     *     locally_modified:list<string>,
     *     stale:list<string>,
     *     incompatible:list<string>,
     *     project_owned:list<string>,
     *     unverifiable:list<string>
     * } $states
     * @return list<string>
     */
    private function driftLines(string $label, array $states): array
    {
        $lines = [];
        if ($states['current'] !== []) {
            $lines[] = '[OK] ' . $label . ': current: ' . implode(', ', $states['current']);
        }
        if ($states['locally_modified'] !== []) {
            $lines[] = '[WARN] ' . $label . ': locally modified: ' . implode(', ', $states['locally_modified']);
        }
        if ($states['stale'] !== []) {
            $lines[] = '[WARN] ' . $label . ': stale: ' . implode(', ', $states['stale']);
        }
        if ($states['incompatible'] !== []) {
            $lines[] = '[WARN] ' . $label . ': incompatible with installed runtime capabilities: ' . implode(', ', $states['incompatible']);
        }
        if ($states['project_owned'] !== []) {
            $lines[] = '[INFO] ' . $label . ': unmanaged/project-owned: ' . implode(', ', $states['project_owned']);
        }
        if ($states['unverifiable'] !== []) {
            $lines[] = '[WARN] ' . $label . ': drift evidence unavailable for legacy managed entries; resync to establish a baseline: ' . implode(', ', $states['unverifiable']);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function findSkillSourceEntries(AgentAssetSourcePaths $paths): array
    {
        $skillsRoot = $paths->absoluteSkillsRoot();
        if (!is_dir($skillsRoot)) {
            return [];
        }

        $entries = [];
        foreach (scandir($skillsRoot) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (is_file($skillsRoot . '/' . $entry . '/SKILL.md')) {
                $entries[] = $entry;
            }
        }

        sort($entries);

        return $entries;
    }

    /**
     * @return list<string>
     */
    private function findSubagentSourceFiles(AgentAssetSourcePaths $paths): array
    {
        $subagentsRoot = $paths->absoluteSubagentsRoot();
        if (!is_dir($subagentsRoot)) {
            return [];
        }

        $files = [];
        foreach (scandir($subagentsRoot) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !str_ends_with($entry, '.md')) {
                continue;
            }

            if (is_file($subagentsRoot . '/' . $entry)) {
                $files[] = $entry;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function subagentsDesiredEntries(AgentAssetSourcePaths $paths, string $targetSuffix): array
    {
        $entries = [];
        foreach ($this->findSubagentSourceFiles($paths) as $file) {
            $entries[] = substr($file, 0, -3) . $targetSuffix;
        }

        sort($entries);

        return $entries;
    }

    /**
     * @return list<string>
     */
    private function findHookScriptFiles(string $hooksRoot): array
    {
        $hookScriptsDir = $hooksRoot . '/hooks';
        if (!is_dir($hookScriptsDir)) {
            return [];
        }

        $files = [];
        foreach (scandir($hookScriptsDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $hookScriptsDir . '/' . $entry;
            if (is_file($path) && str_ends_with($entry, '.php')) {
                $files[] = $entry;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Returns null when hooks.json exists but is invalid (desired entries cannot be determined).
     * Returns [] when hooks.json is absent (genuinely nothing configured).
     *
     * @return list<string>|null
     */
    private function hooksDesiredEntries(AgentAssetSourcePaths $paths): ?array
    {
        $hooksRoot = $paths->absoluteHooksRoot();
        if (!is_file($hooksRoot . '/hooks.json')) {
            return [];
        }

        if (CodexHooksDefinition::validationErrors($hooksRoot) !== []) {
            return null;
        }

        try {
            $definition = CodexHooksDefinition::fromRoot($hooksRoot);
        } catch (InvalidArgumentException) {
            return null;
        }

        $entries = ['hooks.json'];
        foreach ($definition->scriptNames() as $scriptName) {
            $entries[] = 'hooks/' . $scriptName;
        }

        sort($entries);

        return $entries;
    }

    /**
     * @return list<string>|null
     */
    private function claudeHooksDesiredEntries(AgentAssetSourcePaths $paths): ?array
    {
        $hooksRoot = $paths->absoluteClaudeHooksRoot();
        if (!is_file($hooksRoot . '/hooks.json')) {
            return [];
        }

        if (ClaudeHooksDefinition::validationErrors($hooksRoot) !== []) {
            return null;
        }

        try {
            $definition = ClaudeHooksDefinition::fromRoot($hooksRoot);
        } catch (InvalidArgumentException) {
            return null;
        }

        $entries = ['settings.json#hooks'];
        foreach ($definition->scriptNames() as $scriptName) {
            $entries[] = 'hooks/' . $scriptName;
        }

        sort($entries);

        return $entries;
    }

    private function resolveSkillsTargetRoot(string $agent): string
    {
        return match ($agent) {
            'codex' => PathResolver::fromEnvironment($this->rootPath, 'CODEX_SKILLS_DIR')
                ?? (($codexHome = PathResolver::fromEnvironment($this->rootPath, 'CODEX_HOME')) !== null ? $codexHome . '/skills' : $this->rootPath . '/.codex/skills'),
            'claude' => PathResolver::fromEnvironment($this->rootPath, 'CLAUDE_SKILLS_DIR') ?? $this->rootPath . '/.claude/skills',
            'copilot' => PathResolver::fromEnvironment($this->rootPath, 'COPILOT_SKILLS_DIR') ?? $this->rootPath . '/.github/skills',
            default => PathResolver::fromEnvironment($this->rootPath, 'ANTIGRAVITY_SKILLS_DIR') ?? $this->rootPath . '/.agents/skills',
        };
    }

    private function resolveSubagentsTargetRoot(string $agent): string
    {
        return match ($agent) {
            'codex' => PathResolver::fromEnvironment($this->rootPath, 'CODEX_AGENTS_DIR')
                ?? (($codexHome = PathResolver::fromEnvironment($this->rootPath, 'CODEX_HOME')) !== null ? $codexHome . '/agents' : $this->rootPath . '/.codex/agents'),
            'claude' => PathResolver::fromEnvironment($this->rootPath, 'CLAUDE_AGENTS_DIR') ?? $this->rootPath . '/.claude/agents',
            'copilot' => PathResolver::fromEnvironment($this->rootPath, 'COPILOT_AGENTS_DIR') ?? $this->rootPath . '/.github/agents',
            default => PathResolver::fromEnvironment($this->rootPath, 'ANTIGRAVITY_AGENTS_DIR') ?? $this->rootPath . '/.agents/agents',
        };
    }

    private function resolveHooksTargetRoot(): string
    {
        return PathResolver::fromEnvironment($this->rootPath, 'CODEX_HOME') ?? $this->rootPath . '/.codex';
    }

    private function resolveClaudeHooksTargetRoot(): string
    {
        return PathResolver::fromEnvironment($this->rootPath, 'CLAUDE_CONFIG_DIR') ?? $this->rootPath . '/.claude';
    }

    /**
     * @param list<string> $tokens
     *
     * @return array<string, string>
     */
    private function readPathOverrides(array $tokens): array
    {
        $overrides = [];
        foreach (['skills-root', 'subagents-root', 'hooks-root', 'tools-root'] as $option) {
            $value = OptionTokens::value($tokens, $option);
            if ($value !== null) {
                $overrides[$option] = $value;
            }
        }

        return $overrides;
    }

    /**
     * @param list<string> $tokens
     */
    private function validateTokens(array $tokens): ?string
    {
        $valueOptions = ['config', 'skills-root', 'subagents-root', 'hooks-root', 'tools-root'];
        $count = count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if (!str_starts_with($token, '--')) {
                return 'Unknown init status argument: ' . $token;
            }

            $normalized = strtok(substr($token, 2), '=');
            if (!in_array($normalized, $valueOptions, true)) {
                return 'Unknown init status option: --' . $normalized;
            }

            if (!str_contains($token, '=')) {
                $candidate = $tokens[$i + 1] ?? null;
                if (!is_string($candidate) || str_starts_with($candidate, '--')) {
                    return 'Missing value for init status option: --' . $normalized;
                }

                ++$i;
            }
        }

        return null;
    }
}
