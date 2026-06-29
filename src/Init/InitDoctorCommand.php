<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

final readonly class InitDoctorCommand
{
    /**
     * @var list<string>
     */
    private const array MAKEFILE_CANDIDATES = ['Makefile', 'makefile', 'GNUmakefile', 'Makefile.agent-loop.mk'];

    /**
     * @var list<string>
     */
    private const array MIGRATION_TARGETS = [
        'validate_agent_skills',
        'validate_agent_subagents',
        'validate_codex_hooks',
        'install_codex_skills',
        'install_copilot_skills',
        'install_claude_skills',
        'install_gemini_skills',
        'install_antigravity_skills',
        'install_agent_skills',
        'install_copilot_agents',
        'install_gemini_agents',
        'install_antigravity_agents',
        'install_agent_subagents',
        'install_codex_hooks',
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

        echo "agent-loop init doctor\n\n";
        foreach ($this->buildResults($paths) as $result) {
            echo $result->render() . "\n";
        }

        return 0;
    }

    /**
     * @return list<InitCheckResult>
     */
    private function buildResults(AgentAssetSourcePaths $paths): array
    {
        $results = [
            $this->checkPhpVersion(),
            ...$this->checkComposer(),
            $this->checkGit(),
            ...$this->checkMakefiles(),
            InitCheckResult::info('skills-root: ' . $paths->skillsRoot()),
            InitCheckResult::info('subagents-root: ' . $paths->subagentsRoot()),
            InitCheckResult::info('hooks-root: ' . $paths->hooksRoot()),
            InitCheckResult::info('tools-root: ' . $paths->toolsRoot()),
            $this->checkSkills($paths),
            $this->checkSubagents($paths),
            $this->checkHooks($paths),
            $this->checkTools($paths),
            InitCheckResult::ok('Workflow: init diagnostics do not affect workflow close'),
        ];

        return $results;
    }

    private function checkPhpVersion(): InitCheckResult
    {
        if (version_compare(\PHP_VERSION, '8.3.0', '>=')) {
            return InitCheckResult::ok('PHP: ' . \PHP_VERSION);
        }

        return InitCheckResult::warn('PHP: ' . \PHP_VERSION . ' detected, expected >= 8.3');
    }

    /**
     * @return list<InitCheckResult>
     */
    private function checkComposer(): array
    {
        $composerFile = rtrim($this->rootPath, '/') . '/composer.json';
        if (!is_file($composerFile)) {
            return [InitCheckResult::warn('Composer: composer.json not found')];
        }

        $content = file_get_contents($composerFile);
        if (!is_string($content)) {
            return [InitCheckResult::warn('Composer: invalid composer.json')];
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return [InitCheckResult::warn('Composer: invalid composer.json')];
        }

        $results = [InitCheckResult::ok('Composer: composer.json found')];

        $scripts = $decoded['scripts'] ?? null;
        $scriptNames = is_array($scripts) ? array_keys($scripts) : [];
        $requiredScripts = ['ci', 'test', 'phpstan'];
        $missingScripts = array_values(array_diff($requiredScripts, $scriptNames));
        if ($missingScripts === []) {
            $results[] = InitCheckResult::ok('Composer scripts: found ci, test, phpstan');

            return $results;
        }

        $results[] = InitCheckResult::warn('Composer scripts: missing ' . implode(', ', $missingScripts));

        return $results;
    }

    private function checkGit(): InitCheckResult
    {
        return is_dir(rtrim($this->rootPath, '/') . '/.git')
            ? InitCheckResult::ok('Git: .git directory found')
            : InitCheckResult::warn('Git: .git directory not found');
    }

    /**
     * @return list<InitCheckResult>
     */
    private function checkMakefiles(): array
    {
        $foundMakefiles = [];
        foreach (self::MAKEFILE_CANDIDATES as $candidate) {
            $absolutePath = rtrim($this->rootPath, '/') . '/' . $candidate;
            if (is_file($absolutePath)) {
                $foundMakefiles[$candidate] = $absolutePath;
            }
        }

        if ($foundMakefiles === []) {
            return [
                InitCheckResult::warn('Make: no Makefile found'),
                InitCheckResult::warn('Make agent assets: no migration-compatible agent asset targets found'),
            ];
        }

        $results = [
            InitCheckResult::ok('Make: ' . array_key_first($foundMakefiles) . ' found'),
        ];

        $foundTargets = [];
        foreach (self::MIGRATION_TARGETS as $target) {
            foreach ($foundMakefiles as $makefilePath) {
                $content = file_get_contents($makefilePath);
                if (!is_string($content)) {
                    continue;
                }

                if (preg_match('/^' . preg_quote($target, '/') . '\s*:/m', $content) === 1) {
                    $foundTargets[] = $target;

                    break;
                }
            }
        }

        if ($foundTargets === []) {
            $results[] = InitCheckResult::warn('Make agent assets: no migration-compatible agent asset targets found');

            return $results;
        }

        $results[] = InitCheckResult::ok('Make agent assets: found ' . implode(', ', $foundTargets));

        return $results;
    }

    private function checkSkills(AgentAssetSourcePaths $paths): InitCheckResult
    {
        $count = count($this->findSkillFiles($paths->absoluteSkillsRoot()));
        if ($count === 0) {
            return InitCheckResult::warn('Skills: no repo-managed skills found under ' . $paths->skillsRoot() . '/*/SKILL.md');
        }

        return InitCheckResult::ok('Skills: ' . $count . ' repo-managed skill file(s) found under ' . $paths->skillsRoot());
    }

    private function checkSubagents(AgentAssetSourcePaths $paths): InitCheckResult
    {
        $subagentsRoot = $paths->absoluteSubagentsRoot();
        if (!is_dir($subagentsRoot)) {
            return InitCheckResult::info('Subagents: no source files found, validation reserved');
        }

        $files = [];
        foreach (scandir($subagentsRoot) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $subagentsRoot . '/' . $entry;
            if (is_file($path) && str_ends_with($entry, '.md')) {
                $files[] = $entry;
            }
        }

        if ($files === []) {
            return InitCheckResult::info('Subagents: no source files found, validation reserved');
        }

        sort($files);

        return InitCheckResult::info('Subagents: detected ' . count($files) . ' candidate file(s), validation reserved');
    }

    private function checkHooks(AgentAssetSourcePaths $paths): InitCheckResult
    {
        $hooksRoot = $paths->absoluteHooksRoot();
        $hooksJson = is_file($hooksRoot . '/hooks.json');
        $hookFiles = [];

        if (is_dir($hooksRoot . '/hooks')) {
            foreach (scandir($hooksRoot . '/hooks') ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $hooksRoot . '/hooks/' . $entry;
                if (is_file($path) && str_ends_with($entry, '.php')) {
                    $hookFiles[] = $entry;
                }
            }
        }

        if (!$hooksJson && $hookFiles === []) {
            return InitCheckResult::info('Codex hooks: no source files found, validation reserved');
        }

        return InitCheckResult::info('Codex hooks: detected ' . ($hooksJson ? 'hooks.json and ' : 'no hooks.json and ') . count($hookFiles) . ' hook file(s), validation reserved');
    }

    private function checkTools(AgentAssetSourcePaths $paths): InitCheckResult
    {
        return is_dir($paths->absoluteToolsRoot())
            ? InitCheckResult::info('Tools: tools directory found')
            : InitCheckResult::info('Tools: tools directory not found');
    }

    /**
     * @param list<string> $tokens
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
            if ($token === 'help' || $token === '--help' || $token === '-h') {
                continue;
            }

            if (!str_starts_with($token, '--')) {
                return 'Unknown init doctor argument: ' . $token;
            }

            $normalized = strtok(substr($token, 2), '=');
            if (!in_array($normalized, $valueOptions, true)) {
                return 'Unknown init doctor option: --' . $normalized;
            }

            if (!str_contains($token, '=')) {
                $candidate = $tokens[$i + 1] ?? null;
                if (!is_string($candidate) || str_starts_with($candidate, '--')) {
                    return 'Missing value for init doctor option: --' . $normalized;
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

    /**
     * @return array<string, string>
     */
    private function findSkillFiles(string $skillsRoot): array
    {
        if (!is_dir($skillsRoot)) {
            return [];
        }

        $files = [];
        foreach (scandir($skillsRoot) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $skillFile = $skillsRoot . '/' . $entry . '/SKILL.md';
            if (is_file($skillFile)) {
                $files[$entry] = $skillFile;
            }
        }

        ksort($files);

        return $files;
    }
}
