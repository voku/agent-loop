<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use ReflectionClass;
use voku\AgentLoop\Cli\OptionTokens;
use voku\AgentRecallCompiler\Cli as RecallCli;

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
        $codexHooksRoot = $packageRoot . '/docs/agents/codex-hooks';
        $claudeHooksRoot = $packageRoot . '/docs/agents/claude-hooks';
        $extraSkillRoots = OptionTokens::values($tokens, 'extra-skills-root');
        $installsSubagents = $agent->isAll()
            || in_array($agent->canonicalName(), InitAgent::canonicalNames(), true);
        $hookAgents = $agent->isAll()
            ? ['codex', 'claude']
            : (in_array($agent->canonicalName(), ['codex', 'claude'], true) ? [$agent->canonicalName()] : []);

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
        foreach ($hookAgents as $hookAgent) {
            $hooksRoot = $hookAgent === 'codex' ? $codexHooksRoot : $claudeHooksRoot;
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

        foreach ($hookAgents as $hookAgent) {
            $hooksRoot = $hookAgent === 'codex' ? $codexHooksRoot : $claudeHooksRoot;
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

        if (!$agent->isAll()) {
            $canonicalAgent = $agent->canonicalName();
            if ($canonicalAgent === 'claude') {
                echo '[INFO] install assets: installed portable skills, bundled subagent roles, and repository discipline hooks for claude; installed project instructions.' . "\n";
            } elseif (in_array($canonicalAgent, ['copilot', 'antigravity'], true)) {
                echo '[INFO] install assets: installed portable skills, bundled subagent roles, and project instructions for ' . $canonicalAgent . '; repository discipline hooks are currently available for codex and claude.' . "\n";
            }
        }

        $sourceDescription = $extraSkillRoots === []
            ? 'first-party package guidance'
            : 'first-party package guidance plus ' . count($extraSkillRoots) . ' explicit local skill source(s)';

        echo $dryRun
            ? '[DRY-RUN] install assets: ' . $sourceDescription . ' validated; no files written.' . "\n"
            : '[OK] install assets: installed ' . $sourceDescription . ' without downloading remote code.' . "\n";

        return 0;
    }

    /** @return list<string> */
    private function firstPartySkillRoots(string $packageRoot): array
    {
        $recallFile = (new ReflectionClass(RecallCli::class))->getFileName();
        if (!is_string($recallFile)) {
            throw new InvalidArgumentException('Unable to resolve the installed agent-recall-compiler package path.');
        }

        return [
            $packageRoot . '/docs/agents/skills',
            dirname($recallFile, 2) . '/skills',
        ];
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
}
