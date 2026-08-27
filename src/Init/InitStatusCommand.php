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
        ['alias' => 'open-code', 'canonical' => 'opencode'],
        ['alias' => 'github-copilot', 'canonical' => 'copilot'],
        ['alias' => 'gemini-cli', 'canonical' => 'gemini'],
        ['alias' => 'agy', 'canonical' => 'antigravity'],
        ['alias' => 'google-antigravity', 'canonical' => 'antigravity'],
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

    /** @return list<string> */
    private function projectedSkillAgents(): array
    {
        $catalog = new ManagedAssetTargetCatalog($this->rootPath);
        $agents = [];
        foreach (InitAgent::canonicalNames() as $agent) {
            $manifestPath = rtrim($catalog->skillsTargetRoot($agent), '/') . '/' . InitSyncManifest::fileName();
            if (is_file($manifestPath)) {
                $agents[] = $agent;
            }
        }

        return $agents;
    }

    /** @return list<InitCheckResult> */
    private function buildSourceResults(AgentAssetSourcePaths $paths): array
    {
        $catalog = new ManagedAssetTargetCatalog($this->rootPath);
        $hooksRoot = $paths->absoluteHooksRoot();
        $hooksJsonExists = is_file($hooksRoot . '/hooks.json');
        $hookScriptsCount = count($catalog->hookScriptFiles($hooksRoot));

        return [
            InitCheckResult::ok('skills-root: ' . $paths->skillsRoot() . ' (' . count($catalog->skillSourceEntries($paths)) . ' skill(s))'),
            InitCheckResult::ok('subagents-root: ' . $paths->subagentsRoot() . ' (' . count($catalog->subagentSourceFiles($paths)) . ' file(s))'),
            InitCheckResult::ok('hooks-root: ' . $paths->hooksRoot() . ' (hooks.json: ' . ($hooksJsonExists ? 'yes' : 'no') . ', scripts: ' . $hookScriptsCount . ')'),
            InitCheckResult::info('tools-root: ' . $paths->toolsRoot() . ' (' . (is_dir($paths->absoluteToolsRoot()) ? 'found' : 'missing') . ')'),
        ];
    }

    /**
     * The projection targets this repository owns.
     *
     * Root and expected-entry resolution lives in
     * {@see ManagedAssetTargetCatalog} so `init status`, the typed setup
     * projection, and the drift projector cannot drift apart from each other.
     *
     * @return list<array{label: string, targetRoot: string, kind: string, agent: string, desiredEntries: list<string>|null}>
     */
    private function buildManifestTargets(AgentAssetSourcePaths $paths): array
    {
        $targets = [];
        foreach ((new ManagedAssetTargetCatalog($this->rootPath))->targets($paths) as $target) {
            $targets[] = [
                'label' => $target->label,
                'targetRoot' => $target->targetRoot,
                'kind' => $target->kind->value,
                'agent' => $target->host,
                'desiredEntries' => $target->desiredEntries(),
            ];
        }

        return $targets;
    }

    /**
     * @param array{label: string, targetRoot: string, kind: string, agent: string, desiredEntries: list<string>|null} $target
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

        $desiredEntries = ManagedAssetExpectationResolver::resolve($manifest, $desiredEntries);
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
     * @param list<string> $tokens
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

    /** @param list<string> $tokens */
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
