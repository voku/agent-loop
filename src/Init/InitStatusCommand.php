<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;

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

        $config = (new InitConfigLoader($this->rootPath))->load($this->readOptionValue($tokens, 'config'));
        foreach ($config['warnings'] as $warning) {
            echo $warning . "\n";
        }

        $paths = AgentAssetSourcePaths::fromSources($this->rootPath, $config['paths'], $this->readPathOverrides($tokens));

        echo "agent-loop init status\n\n";

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
        echo "Target manifests:\n";
        foreach ($this->buildManifestTargets($paths) as $target) {
            [$manifestLine, $staleLine] = $this->reportManifestTarget($target);
            echo $manifestLine . "\n";
            if ($staleLine !== null) {
                $staleLines[] = $staleLine;
            }
        }
        echo "\n";

        echo "Stale managed entries:\n";
        foreach ($staleLines as $staleLine) {
            echo $staleLine . "\n";
        }

        return 0;
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
     * @return list<array{label: string, targetRoot: string, kind: string, agent: string, desiredEntries: list<string>}>
     */
    private function buildManifestTargets(AgentAssetSourcePaths $paths): array
    {
        $skillsDesiredEntries = $this->findSkillSourceEntries($paths);

        return [
            ['label' => 'codex skills', 'targetRoot' => $this->resolveSkillsTargetRoot('codex'), 'kind' => 'skills', 'agent' => 'codex', 'desiredEntries' => $skillsDesiredEntries],
            ['label' => 'claude skills', 'targetRoot' => $this->resolveSkillsTargetRoot('claude'), 'kind' => 'skills', 'agent' => 'claude', 'desiredEntries' => $skillsDesiredEntries],
            ['label' => 'copilot skills', 'targetRoot' => $this->resolveSkillsTargetRoot('copilot'), 'kind' => 'skills', 'agent' => 'copilot', 'desiredEntries' => $skillsDesiredEntries],
            ['label' => 'antigravity skills', 'targetRoot' => $this->resolveSkillsTargetRoot('antigravity'), 'kind' => 'skills', 'agent' => 'antigravity', 'desiredEntries' => $skillsDesiredEntries],
            ['label' => 'copilot subagents', 'targetRoot' => $this->resolveSubagentsTargetRoot('copilot'), 'kind' => 'subagents', 'agent' => 'copilot', 'desiredEntries' => $this->subagentsDesiredEntries($paths, '.agent.md')],
            ['label' => 'antigravity subagents', 'targetRoot' => $this->resolveSubagentsTargetRoot('antigravity'), 'kind' => 'subagents', 'agent' => 'antigravity', 'desiredEntries' => $this->subagentsDesiredEntries($paths, '.md')],
            ['label' => 'codex hooks', 'targetRoot' => $this->resolveHooksTargetRoot(), 'kind' => 'hooks', 'agent' => 'codex', 'desiredEntries' => $this->hooksDesiredEntries($paths)],
        ];
    }

    /**
     * @param array{label: string, targetRoot: string, kind: string, agent: string, desiredEntries: list<string>} $target
     *
     * @return array{0: string, 1: ?string}
     */
    private function reportManifestTarget(array $target): array
    {
        $label = $target['label'];
        $targetRoot = $target['targetRoot'];

        $manifestPath = rtrim($targetRoot, '/') . '/' . InitSyncManifest::fileName();
        if (!is_file($manifestPath)) {
            return ['[INFO] ' . $label . ': no manifest at ' . $manifestPath, null];
        }

        try {
            $manifest = InitSyncManifest::load($targetRoot, $target['kind'], $target['agent']);
        } catch (InvalidArgumentException $exception) {
            return ['[WARN] ' . $label . ': ' . $exception->getMessage(), null];
        }

        $managedEntryCount = count($manifest->staleEntries([]));
        $manifestLine = '[OK] ' . $label . ': manifest found (' . $managedEntryCount . ' managed entrie(s))';

        $staleEntries = $manifest->staleEntries($target['desiredEntries']);
        $staleLine = $staleEntries === []
            ? '[OK] ' . $label . ': no stale managed entries'
            : '[WARN] ' . $label . ': stale managed entries: ' . implode(', ', $staleEntries);

        return [$manifestLine, $staleLine];
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
     * @return list<string>
     */
    private function hooksDesiredEntries(AgentAssetSourcePaths $paths): array
    {
        $hooksRoot = $paths->absoluteHooksRoot();
        if (!is_file($hooksRoot . '/hooks.json') || CodexHooksDefinition::validationErrors($hooksRoot) !== []) {
            return [];
        }

        try {
            $definition = CodexHooksDefinition::fromRoot($hooksRoot);
        } catch (InvalidArgumentException) {
            return [];
        }

        $entries = ['hooks.json'];
        foreach ($definition->scriptNames() as $scriptName) {
            $entries[] = 'hooks/' . $scriptName;
        }

        sort($entries);

        return $entries;
    }

    private function resolveSkillsTargetRoot(string $agent): string
    {
        return match ($agent) {
            'codex' => $this->resolvePathFromEnv('CODEX_SKILLS_DIR')
                ?? (($codexHome = $this->resolvePathFromEnv('CODEX_HOME')) !== null ? $codexHome . '/skills' : $this->rootPath . '/.codex/skills'),
            'claude' => $this->resolvePathFromEnv('CLAUDE_SKILLS_DIR') ?? $this->rootPath . '/.claude/skills',
            'copilot' => $this->resolvePathFromEnv('COPILOT_SKILLS_DIR') ?? $this->rootPath . '/.github/skills',
            default => $this->resolvePathFromEnv('ANTIGRAVITY_SKILLS_DIR') ?? $this->rootPath . '/.agents/skills',
        };
    }

    private function resolveSubagentsTargetRoot(string $agent): string
    {
        return $agent === 'copilot'
            ? ($this->resolvePathFromEnv('COPILOT_AGENTS_DIR') ?? $this->rootPath . '/.github/agents')
            : ($this->resolvePathFromEnv('ANTIGRAVITY_AGENTS_DIR') ?? $this->rootPath . '/.agents/agents');
    }

    private function resolveHooksTargetRoot(): string
    {
        $codexHome = getenv('CODEX_HOME');
        if (is_string($codexHome) && trim($codexHome) !== '') {
            if (str_starts_with($codexHome, '/')) {
                return rtrim($codexHome, '/');
            }

            return rtrim($this->rootPath, '/') . '/' . trim($codexHome, '/');
        }

        return $this->rootPath . '/.codex';
    }

    private function resolvePathFromEnv(string $envName): ?string
    {
        $value = getenv($envName);
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        if ($this->isAbsolutePath($value)) {
            return rtrim(str_replace('\\', '/', $value), '/');
        }

        return rtrim(str_replace('\\', '/', $this->rootPath), '/') . '/' . trim(str_replace('\\', '/', $value), '/');
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (str_starts_with($path, '/')) {
            return true;
        }

        if (preg_match('/^[a-zA-Z]:[\\\\\/]/', $path) === 1) {
            return true;
        }

        if (str_starts_with($path, '\\\\') || str_starts_with($path, '//')) {
            return true;
        }

        return false;
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
            $value = $this->readOptionValue($tokens, $option);
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

    /**
     * @param list<string> $tokens
     */
    private function readOptionValue(array $tokens, string $name): ?string
    {
        $prefix = '--' . $name . '=';
        foreach ($tokens as $index => $token) {
            if (str_starts_with($token, $prefix)) {
                $value = substr($token, strlen($prefix));

                return $value === '' ? null : $value;
            }

            if ($token === '--' . $name) {
                $candidate = $tokens[$index + 1] ?? null;
                if (is_string($candidate) && !str_starts_with($candidate, '--')) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
