<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;

final readonly class InitInstallAssetsCommand
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        $argumentError = $this->validateTokens($tokens);
        if ($argumentError !== null) {
            fwrite(\STDERR, $argumentError . "\n");

            return 1;
        }

        $requestedAgent = $this->readOptionValue($tokens, 'agent');
        if ($requestedAgent === null) {
            fwrite(\STDERR, "Missing required option: --agent\n");

            return 1;
        }

        try {
            $agent = InitAgent::parse(
                $requestedAgent,
                ['codex', 'claude', 'copilot', 'antigravity'],
                true,
            );
        } catch (InvalidArgumentException $exception) {
            fwrite(\STDERR, $exception->getMessage() . "\n");

            return 1;
        }

        foreach ($agent->messages() as $message) {
            echo $message . "\n";
        }

        $packageRoot = dirname(__DIR__, 2);
        $skillsRoot = $packageRoot . '/docs/agents/skills';
        $subagentsRoot = $packageRoot . '/docs/agents/subagents';
        $hooksRoot = $packageRoot . '/docs/agents/codex-hooks';
        $installsSubagents = $agent->isAll()
            || in_array($agent->canonicalName(), ['codex', 'claude', 'copilot', 'antigravity'], true);
        $installsHooks = $agent->isAll() || $agent->canonicalName() === 'codex';

        if (!is_dir($skillsRoot)) {
            fwrite(\STDERR, 'Bundled skills root is missing: ' . $skillsRoot . "\n");

            return 1;
        }
        if ($installsSubagents && !is_dir($subagentsRoot)) {
            fwrite(\STDERR, 'Bundled subagents root is missing: ' . $subagentsRoot . "\n");

            return 1;
        }
        if ($installsHooks && !is_file($hooksRoot . '/hooks.json')) {
            fwrite(\STDERR, 'Bundled Codex hooks are missing: ' . $hooksRoot . '/hooks.json' . "\n");

            return 1;
        }

        $dryRun = in_array('--dry-run', $tokens, true);
        $forwarded = $this->forwardedTokens($tokens);
        $skillsExit = (new InitSyncSkillsCommand($this->rootPath))->run(array_merge(
            [
                '--agent=' . ($agent->isAll() ? 'all' : $agent->canonicalName()),
                '--skills-root=' . $skillsRoot,
            ],
            $forwarded,
        ));
        if ($skillsExit !== 0) {
            return $skillsExit;
        }

        if ($installsSubagents) {
            $subagentsExit = (new InitSyncSubagentsCommand($this->rootPath))->run(array_merge(
                [
                    '--agent=' . ($agent->isAll() ? 'all' : $agent->canonicalName()),
                    '--subagents-root=' . $subagentsRoot,
                ],
                $forwarded,
            ));
            if ($subagentsExit !== 0) {
                return $subagentsExit;
            }
        }

        if ($installsHooks) {
            $hooksExit = (new InitSyncHooksCommand($this->rootPath))->run(array_merge(
                ['--agent=codex', '--hooks-root=' . $hooksRoot],
                $forwarded,
            ));
            if ($hooksExit !== 0) {
                return $hooksExit;
            }
        }

        if (!$agent->isAll()) {
            $canonicalAgent = $agent->canonicalName();
            if ($canonicalAgent === 'claude') {
                echo '[INFO] install assets: installed portable skills and bundled subagent roles for claude; repository hooks are currently available for codex only.' . "\n";
            } elseif (in_array($canonicalAgent, ['copilot', 'antigravity'], true)) {
                echo '[INFO] install assets: installed portable skills and bundled subagent roles for ' . $canonicalAgent . '; repository hooks are currently available for codex only.' . "\n";
            }
        }

        echo $dryRun
            ? '[DRY-RUN] install assets: package-owned guidance validated; no files written.' . "\n"
            : '[OK] install assets: installed package-owned guidance without downloading remote code.' . "\n";

        return 0;
    }

    /**
     * @param list<string> $tokens
     * @return list<string>
     */
    private function forwardedTokens(array $tokens): array
    {
        $forwarded = [];
        foreach (['dry-run', 'force', 'adopt-existing'] as $flag) {
            if (in_array('--' . $flag, $tokens, true)) {
                $forwarded[] = '--' . $flag;
            }
        }

        return $forwarded;
    }

    /** @param list<string> $tokens */
    private function validateTokens(array $tokens): ?string
    {
        $valueOptions = ['agent'];
        $flagOptions = ['dry-run', 'force', 'adopt-existing'];
        $count = count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if (!str_starts_with($token, '--')) {
                return 'Unknown init install-assets argument: ' . $token;
            }

            $normalized = strtok(substr($token, 2), '=');
            if (!is_string($normalized) || !in_array($normalized, array_merge($valueOptions, $flagOptions), true)) {
                return 'Unknown init install-assets option: --' . (is_string($normalized) ? $normalized : '');
            }

            if (in_array($normalized, $valueOptions, true) && !str_contains($token, '=')) {
                $candidate = $tokens[$i + 1] ?? null;
                if (!is_string($candidate) || str_starts_with($candidate, '--')) {
                    return 'Missing value for init install-assets option: --' . $normalized;
                }

                ++$i;
            }
        }

        return null;
    }

    /** @param list<string> $tokens */
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
