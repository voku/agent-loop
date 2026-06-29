<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;

final readonly class InitValidateCommand
{
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

        $kind = InitAssetKind::fromCli($this->readOptionValue($tokens, 'kind'));
        if ($kind === null) {
            fwrite(\STDERR, "Missing or invalid required option: --kind\n");

            return 1;
        }

        $config = (new InitConfigLoader($this->rootPath))->load($this->readOptionValue($tokens, 'config'));
        foreach ($config['warnings'] as $warning) {
            echo $warning . "\n";
        }

        try {
            $this->parseOptionalAgent($kind, $tokens, $config['agents']);
        } catch (InvalidArgumentException $exception) {
            fwrite(\STDERR, $exception->getMessage() . "\n");

            return 1;
        }

        $paths = AgentAssetSourcePaths::fromSources($this->rootPath, $config['paths'], $this->readPathOverrides($tokens));

        if ($kind->isSkills()) {
            return $this->validateSkills($paths);
        }

        if ($kind->isSubagents()) {
            echo "subagents validation is not implemented yet\n";

            return 1;
        }

        if ($kind->isHooks()) {
            echo "hooks validation is not implemented yet\n";

            return 1;
        }

        $skillsExit = $this->validateSkills($paths);
        echo "subagents validation is not implemented yet\n";
        echo "hooks validation is not implemented yet\n";

        return $skillsExit === 0 ? 1 : $skillsExit;
    }

    private function validateSkills(AgentAssetSourcePaths $paths): int
    {
        $skillFiles = $this->findSkillFiles($paths->absoluteSkillsRoot());
        if ($skillFiles === []) {
            echo '[WARN] validate skills: no skills found under ' . $paths->skillsRoot() . '/*/SKILL.md' . "\n";

            return 0;
        }

        $errors = [];
        foreach ($skillFiles as $directoryName => $skillFile) {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $directoryName) !== 1 || $directoryName === '.' || $directoryName === '..' || str_starts_with($directoryName, '.')) {
                $errors[] = '[FAIL] validate skills: invalid skill directory name ' . $directoryName;

                continue;
            }

            if (!is_readable($skillFile)) {
                $errors[] = '[FAIL] validate skills: unreadable skill ' . $directoryName;

                continue;
            }

            $content = file_get_contents($skillFile);
            if (!is_string($content) || trim($content) === '') {
                $errors[] = '[FAIL] validate skills: empty skill ' . $directoryName;
            }
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                echo $error . "\n";
            }

            return 1;
        }

        echo '[OK] validate skills: ' . count($skillFiles) . ' skill file(s) valid' . "\n";

        return 0;
    }

    /**
     * @param list<string> $tokens
     * @param array<string, array<string, string>> $configAgents
     */
    private function parseOptionalAgent(InitAssetKind $kind, array $tokens, array $configAgents): void
    {
        $agentValue = $this->readOptionValue($tokens, 'agent');
        if ($agentValue === null) {
            return;
        }

        $allowed = match (true) {
            $kind->isHooks() => ['codex'],
            $kind->isSubagents() => ['copilot', 'antigravity'],
            default => ['codex', 'claude', 'copilot', 'antigravity'],
        };

        $agent = InitAgent::parse($agentValue, $allowed, false, $configAgents);
        foreach ($agent->messages() as $message) {
            echo $message . "\n";
        }
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
        $valueOptions = ['kind', 'agent', 'config', 'skills-root', 'subagents-root', 'hooks-root', 'tools-root'];
        $count = count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if (!str_starts_with($token, '--')) {
                return 'Unknown init validate argument: ' . $token;
            }

            $normalized = strtok(substr($token, 2), '=');
            if (!in_array($normalized, $valueOptions, true)) {
                return 'Unknown init validate option: --' . $normalized;
            }

            if (!str_contains($token, '=')) {
                $candidate = $tokens[$i + 1] ?? null;
                if (!is_string($candidate) || str_starts_with($candidate, '--')) {
                    return 'Missing value for init validate option: --' . $normalized;
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
