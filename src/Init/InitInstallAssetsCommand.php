<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use RuntimeException;
use voku\AgentLoop\Cli\OptionTokens;

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
            );
        } catch (InvalidArgumentException $exception) {
            fwrite(\STDERR, $exception->getMessage() . "\n");

            return 1;
        }

        foreach ($agent->messages() as $message) {
            echo $message . "\n";
        }

        $packageRoot = dirname(__DIR__, 2);
        try {
            $skillRoots = $this->firstPartySkillRoots($packageRoot);
        } catch (InvalidArgumentException $exception) {
            fwrite(\STDERR, $exception->getMessage() . "\n");

            return 1;
        }
        $subagentsRoot = $packageRoot . '/docs/agents/subagents';
        $extraSkillRoots = OptionTokens::values($tokens, 'extra-skills-root');
        $installsSubagents = $agent->isAll()
            || in_array($agent->canonicalName(), InitAgent::canonicalNames(), true);

        foreach ($skillRoots as $skillRoot) {
            if (!is_dir($skillRoot)) {
                fwrite(\STDERR, 'First-party skills root is missing: ' . $skillRoot . "\n");

                return 1;
            }
        }
        if ($installsSubagents && !is_dir($subagentsRoot)) {
            fwrite(\STDERR, 'Bundled subagents root is missing: ' . $subagentsRoot . "\n");

            return 1;
        }

        $dryRun = in_array('--dry-run', $tokens, true);
        $forwarded = $this->forwardedTokens($tokens);
        $skillArguments = [
            '--agent=' . ($agent->isAll() ? 'all' : $agent->canonicalName()),
        ];
        foreach ($skillRoots as $skillRoot) {
            $skillArguments[] = '--skills-root=' . $skillRoot;
        }
        foreach ($extraSkillRoots as $extraSkillRoot) {
            $skillArguments[] = '--skills-root=' . $extraSkillRoot;
        }

        $skillsExit = (new InitSyncSkillsCommand($this->rootPath))->run(array_merge(
            $skillArguments,
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

        echo '[INFO] install assets: executable host hooks were not registered; use `init sync-hooks --agent=codex` or `init sync-hooks --agent=claude` explicitly.' . "\n";

        $sourceDescription = $extraSkillRoots === []
            ? 'first-party package guidance'
            : 'first-party package guidance plus ' . count($extraSkillRoots) . ' explicit local skill source(s)';

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
        $valueOptions = ['agent', 'extra-skills-root'];
        $flagOptions = ['dry-run', 'force', 'adopt-existing', 'skip-git-config'];
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
