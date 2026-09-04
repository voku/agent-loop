<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use RuntimeException;
use voku\AgentLoop\Cli\OptionTokens;
use voku\AgentLoop\PackageResources;
use voku\AgentLoop\PathResolver;
use voku\AgentLoop\ProjectLayout;

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

        $requestedConfig = OptionTokens::value($tokens, 'config');
        $layout = new ProjectLayout($this->rootPath);
        $canonicalConfig = $layout->configPath();
        $configPath = $requestedConfig ?? (is_file($canonicalConfig) ? $layout->display($canonicalConfig) : null);
        $config = (new InitConfigLoader($this->rootPath))->load($configPath);
        foreach ($config['warnings'] as $warning) {
            echo $warning . "\n";
        }

        $requestedAgent = OptionTokens::value($tokens, 'agent');
        if ($requestedAgent === null) {
            fwrite(\STDERR, "Missing required option: --agent\n");

            return 1;
        }

        try {
            $agent = InitAgent::parse(
                $requestedAgent,
                InitAgent::canonicalNames(),
                true,
                $config['agents'],
            );
        } catch (InvalidArgumentException $exception) {
            fwrite(\STDERR, $exception->getMessage() . "\n");

            return 1;
        }

        foreach ($agent->messages() as $message) {
            echo $message . "\n";
        }

        $withHooks = OptionTokens::hasFlag($tokens, 'with-hooks');
        if ($withHooks && !$agent->isAll() && !in_array($agent->canonicalName(), ['codex', 'claude'], true)) {
            echo '[FAIL] install assets: --with-hooks is only supported for codex, claude, or all.' . "\n";

            return 1;
        }

        $packageRoot = dirname(__DIR__, 2);
        $includePackageSkills = !OptionTokens::hasFlag($tokens, 'no-package-skills')
            && $config['package_skills'] !== false;
        $includePackageSubagents = !OptionTokens::hasFlag($tokens, 'no-package-subagents')
            && $config['package_subagents'] !== false;

        $skillRoots = [];
        if ($includePackageSkills) {
            try {
                $skillRoots = $this->firstPartySkillRoots($packageRoot);
            } catch (InvalidArgumentException $exception) {
                fwrite(\STDERR, $exception->getMessage() . "\n");

                return 1;
            }
        }

        $paths = AgentAssetSourcePaths::fromSources($this->rootPath, $config['paths']);
        $configuredSkillsRoot = $paths->absoluteSkillsRoot();
        if (is_dir($configuredSkillsRoot) && !in_array($configuredSkillsRoot, $skillRoots, true)) {
            $skillRoots[] = $configuredSkillsRoot;
        }
        $extraSkillRoots = OptionTokens::values($tokens, 'extra-skills-root');
        foreach ($extraSkillRoots as $extraSkillRoot) {
            $skillRoots[] = PathResolver::join($this->rootPath, $extraSkillRoot);
        }

        $subagentRoots = [];
        if ($includePackageSubagents) {
            $subagentsRoot = PackageResources::subagentsRoot();
            if (is_dir($subagentsRoot)) {
                $subagentRoots[] = $subagentsRoot;
            }
        }
        $configuredSubagentsRoot = $paths->absoluteSubagentsRoot();
        if (is_dir($configuredSubagentsRoot) && !in_array($configuredSubagentsRoot, $subagentRoots, true)) {
            $subagentRoots[] = $configuredSubagentsRoot;
        }
        $extraSubagentRoots = OptionTokens::values($tokens, 'extra-subagents-root');
        foreach ($extraSubagentRoots as $extraSubagentRoot) {
            $subagentRoots[] = PathResolver::join($this->rootPath, $extraSubagentRoot);
        }

        $installsSubagents = $agent->isAll()
            || in_array($agent->canonicalName(), InitAgent::canonicalNames(), true);
        $hookAgents = !$withHooks
            ? []
            : ($agent->isAll() ? ['codex', 'claude'] : [$agent->canonicalName()]);

        if ($skillRoots === []) {
            fwrite(\STDERR, "No skills roots configured or found.\n");

            return 1;
        }
        foreach ($skillRoots as $skillRoot) {
            if (!is_dir($skillRoot)) {
                fwrite(\STDERR, 'Skills root is missing: ' . $skillRoot . "\n");

                return 1;
            }
        }
        if ($installsSubagents && $subagentRoots === []) {
            fwrite(\STDERR, "No subagents roots configured or found.\n");

            return 1;
        }
        foreach ($hookAgents as $hookAgent) {
            $hooksRoot = PackageResources::hooksRoot($hookAgent);
            if (!is_file($hooksRoot . '/hooks.json')) {
                fwrite(\STDERR, 'Bundled ' . $hookAgent . ' hooks are missing: ' . $hooksRoot . '/hooks.json' . "\n");

                return 1;
            }
        }

        $dryRun = in_array('--dry-run', $tokens, true);
        $forwarded = $this->forwardedTokens($tokens);
        $skillArguments = [
            '--agent=' . ($agent->isAll() ? 'all' : $agent->canonicalName()),
        ];
        if ($configPath !== null) {
            $skillArguments[] = '--config=' . $configPath;
        }
        foreach ($skillRoots as $skillRoot) {
            $skillArguments[] = '--skills-root=' . $skillRoot;
        }

        $skillsExit = (new InitSyncSkillsCommand($this->rootPath))->run(array_merge(
            $skillArguments,
            $forwarded,
        ));
        if ($skillsExit !== 0) {
            return $skillsExit;
        }

        if ($installsSubagents) {
            $subagentArguments = [
                '--agent=' . ($agent->isAll() ? 'all' : $agent->canonicalName()),
            ];
            if ($configPath !== null) {
                $subagentArguments[] = '--config=' . $configPath;
            }
            foreach ($subagentRoots as $subagentRoot) {
                $subagentArguments[] = '--subagents-root=' . $subagentRoot;
            }

            $subagentsExit = (new InitSyncSubagentsCommand($this->rootPath))->run(array_merge(
                $subagentArguments,
                $forwarded,
            ));
            if ($subagentsExit !== 0) {
                return $subagentsExit;
            }
        }

        foreach ($hookAgents as $hookAgent) {
            $hooksRoot = PackageResources::hooksRoot($hookAgent);
            $hooksExit = (new InitSyncHooksCommand($this->rootPath))->run(array_merge(
                ['--agent=' . $hookAgent, '--hooks-root=' . $hooksRoot],
                $forwarded,
            ));
            if ($hooksExit !== 0) {
                return $hooksExit;
            }
        }

        $instructionArguments = [
            '--agent=' . ($agent->isAll() ? 'all' : $agent->canonicalName()),
        ];
        if ($dryRun) {
            $instructionArguments[] = '--dry-run';
        }
        $instructionsExit = (new InitSyncInstructionsCommand($this->rootPath))->run($instructionArguments);
        if ($instructionsExit !== 0) {
            return $instructionsExit;
        }

        $gitHooksExit = $this->activateLocalGitIntegration($tokens, $forwarded);
        if ($gitHooksExit !== 0) {
            return $gitHooksExit;
        }

        echo $withHooks
            ? '[IMPORTANT] install assets: executable host hooks were explicitly requested with --with-hooks.' . "\n"
            : '[INFO] install assets: executable host hooks were not registered; rerun with --with-hooks to opt in.' . "\n";

        $extraSources = [];
        if (is_dir($configuredSkillsRoot) && !in_array($configuredSkillsRoot, $this->firstPartySkillRoots($packageRoot), true)) {
            $extraSources[] = 'configured repository guidance';
        }
        if ($extraSkillRoots !== []) {
            $extraSources[] = count($extraSkillRoots) . ' explicit local skill source(s)';
        }
        $sourceDescription = $extraSources === []
            ? 'first-party package guidance'
            : 'first-party package guidance plus ' . implode(' and ', $extraSources);

        echo $dryRun
            ? '[DRY-RUN] install assets: ' . $sourceDescription . ' validated; no files written.' . "\n"
            : '[OK] install assets: installed ' . $sourceDescription . ' without downloading remote code.' . "\n";

        return 0;
    }

    /**
     * Activates the local Git integration this repository declared.
     *
     * Hook and commit-template activation used to be a separate, optional step
     * that only `init doctor` mentioned, so repositories that tracked a hook
     * policy routinely committed with the policy inert. It runs here only when
     * `.agent-loop/githooks.json` exists - that file is how a repository opts in -
     * and `--skip-git-config` still leaves Git configuration alone.
     *
     * @param list<string> $tokens
     * @param list<string> $forwarded
     */
    private function activateLocalGitIntegration(array $tokens, array $forwarded): int
    {
        $activation = new RepositoryActivation($this->rootPath);
        if (!$activation->declaresGitHookPolicy()) {
            return 0;
        }

        $arguments = $activation->syncGitHooksTokens();
        if (OptionTokens::hasFlag($tokens, 'skip-git-config')) {
            $arguments[] = '--skip-git-config';
        }

        return (new InitSyncGitHooksCommand($this->rootPath))->run(
            array_values(array_unique(array_merge($arguments, $forwarded))),
        );
    }

    /** @return list<string> */
    private function firstPartySkillRoots(string $packageRoot): array
    {
        try {
            return FirstPartySkillRoots::resolve($packageRoot);
        } catch (RuntimeException $exception) {
            throw new InvalidArgumentException($exception->getMessage(), 0, $exception);
        }
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
        $valueOptions = ['agent', 'config', 'extra-skills-root', 'extra-subagents-root'];
        $flagOptions = ['dry-run', 'force', 'adopt-existing', 'skip-git-config', 'with-hooks', 'no-package-skills', 'no-package-subagents'];
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
}
