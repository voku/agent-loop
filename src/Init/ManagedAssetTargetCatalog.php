<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use voku\AgentLoop\PathResolver;

/**
 * The single owner-side answer to "where does agent-loop project host assets,
 * and what should be there right now".
 *
 * This resolution used to exist three times — in `init status`, in the setup
 * projection, and implicitly in the sync commands — which is how a host root
 * can drift in one place and stay right in another. Every consumer now asks
 * here, so a new host or a moved directory is one edit.
 *
 * Resolution is mutation-free and honours the documented host environment
 * overrides.
 */
final readonly class ManagedAssetTargetCatalog
{
    public function __construct(private string $rootPath)
    {
    }

    /** @return list<ManagedAssetTarget> */
    public function targets(AgentAssetSourcePaths $paths): array
    {
        $skillEntries = $this->skillEntries($paths);

        $targets = [];
        foreach (InitAgent::canonicalNames() as $agent) {
            $targets[] = new ManagedAssetTarget(
                $agent . ' skills',
                $agent,
                ManagedAssetKind::SKILLS,
                $this->skillsTargetRoot($agent),
                ManagedAssetEntryExpectation::known($skillEntries),
            );
        }
        foreach (InitAgent::canonicalNames() as $agent) {
            $targets[] = new ManagedAssetTarget(
                $agent . ' subagents',
                $agent,
                ManagedAssetKind::SUBAGENTS,
                $this->subagentsTargetRoot($agent),
                ManagedAssetEntryExpectation::known($this->subagentEntries($paths, $this->subagentSuffix($agent))),
            );
        }

        $targets[] = new ManagedAssetTarget(
            'codex hooks',
            'codex',
            ManagedAssetKind::HOOKS,
            $this->codexHooksTargetRoot(),
            $this->codexHookEntries($paths),
        );
        $targets[] = new ManagedAssetTarget(
            'claude hooks',
            'claude',
            ManagedAssetKind::HOOKS,
            $this->claudeHooksTargetRoot(),
            $this->claudeHookEntries($paths),
        );

        return $targets;
    }

    /** @return list<ManagedAssetTarget> */
    public function targetsForHost(AgentAssetSourcePaths $paths, string $host): array
    {
        return array_values(array_filter(
            $this->targets($paths),
            static fn (ManagedAssetTarget $target): bool => $target->host === $host,
        ));
    }

    /** Suffix the given host expects for a projected subagent file. */
    public function subagentSuffix(string $host): string
    {
        return match ($host) {
            'codex' => '.toml',
            'copilot' => '.agent.md',
            'claude', 'opencode', 'gemini', 'antigravity' => '.md',
            default => throw new InvalidArgumentException('Unsupported self-discovery host: ' . $host),
        };
    }

    public function skillsTargetRoot(string $host): string
    {
        return match ($host) {
            'codex' => PathResolver::fromEnvironment($this->rootPath, 'CODEX_SKILLS_DIR')
                ?? (($home = PathResolver::fromEnvironment($this->rootPath, 'CODEX_HOME')) !== null ? $home . '/skills' : $this->rootPath . '/.codex/skills'),
            'claude' => PathResolver::fromEnvironment($this->rootPath, 'CLAUDE_SKILLS_DIR') ?? $this->rootPath . '/.claude/skills',
            'opencode' => PathResolver::fromEnvironment($this->rootPath, 'OPENCODE_SKILLS_DIR') ?? $this->rootPath . '/.opencode/skills',
            'copilot' => PathResolver::fromEnvironment($this->rootPath, 'COPILOT_SKILLS_DIR') ?? $this->rootPath . '/.github/skills',
            'gemini' => PathResolver::fromEnvironment($this->rootPath, 'GEMINI_SKILLS_DIR') ?? $this->rootPath . '/.gemini/skills',
            'antigravity' => PathResolver::fromEnvironment($this->rootPath, 'ANTIGRAVITY_SKILLS_DIR') ?? $this->rootPath . '/.agents/skills',
            default => throw new InvalidArgumentException('Unsupported skill status target: ' . $host),
        };
    }

    public function subagentsTargetRoot(string $host): string
    {
        return match ($host) {
            'codex' => PathResolver::fromEnvironment($this->rootPath, 'CODEX_AGENTS_DIR')
                ?? (($home = PathResolver::fromEnvironment($this->rootPath, 'CODEX_HOME')) !== null ? $home . '/agents' : $this->rootPath . '/.codex/agents'),
            'claude' => PathResolver::fromEnvironment($this->rootPath, 'CLAUDE_AGENTS_DIR') ?? $this->rootPath . '/.claude/agents',
            'opencode' => PathResolver::fromEnvironment($this->rootPath, 'OPENCODE_AGENTS_DIR') ?? $this->rootPath . '/.opencode/agents',
            'copilot' => PathResolver::fromEnvironment($this->rootPath, 'COPILOT_AGENTS_DIR') ?? $this->rootPath . '/.github/agents',
            'gemini' => PathResolver::fromEnvironment($this->rootPath, 'GEMINI_AGENTS_DIR') ?? $this->rootPath . '/.gemini/agents',
            'antigravity' => PathResolver::fromEnvironment($this->rootPath, 'ANTIGRAVITY_AGENTS_DIR') ?? $this->rootPath . '/.agents/agents',
            default => throw new InvalidArgumentException('Unsupported subagent status target: ' . $host),
        };
    }

    public function codexHooksTargetRoot(): string
    {
        return PathResolver::fromEnvironment($this->rootPath, 'CODEX_HOME') ?? $this->rootPath . '/.codex';
    }

    public function claudeHooksTargetRoot(): string
    {
        return PathResolver::fromEnvironment($this->rootPath, 'CLAUDE_CONFIG_DIR') ?? $this->rootPath . '/.claude';
    }

    /**
     * Skill entries present in the configured source tree.
     *
     * Deliberately separate from {@see skillEntries()}: this counts what the
     * repository actually ships, while the projection expectation also
     * includes the recall skills that are always part of the first-party set.
     *
     * @return list<string>
     */
    public function skillSourceEntries(AgentAssetSourcePaths $paths): array
    {
        $entries = [];
        $skillsRoot = $paths->absoluteSkillsRoot();
        if (is_dir($skillsRoot)) {
            foreach (scandir($skillsRoot) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (is_file($skillsRoot . '/' . $entry . '/SKILL.md')) {
                    $entries[] = $entry;
                }
            }
        }
        sort($entries, SORT_STRING);

        return $entries;
    }

    /**
     * Skill entries the current sources project, including the recall skills
     * that are always part of the first-party set.
     *
     * @return list<string>
     */
    public function skillEntries(AgentAssetSourcePaths $paths): array
    {
        $entries = $this->skillSourceEntries($paths);
        foreach (FirstPartySkillRoots::recallSkillEntries() as $recallEntry) {
            if (!in_array($recallEntry, $entries, true)) {
                $entries[] = $recallEntry;
            }
        }
        sort($entries, SORT_STRING);

        return $entries;
    }

    /** @return list<string> */
    public function hookScriptFiles(string $hooksRoot): array
    {
        $hookScriptsDir = rtrim($hooksRoot, '/') . '/hooks';
        if (!is_dir($hookScriptsDir)) {
            return [];
        }

        $files = [];
        foreach (scandir($hookScriptsDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_file($hookScriptsDir . '/' . $entry) && str_ends_with($entry, '.php')) {
                $files[] = $entry;
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /** @return list<string> */
    public function subagentEntries(AgentAssetSourcePaths $paths, string $targetSuffix): array
    {
        $entries = [];
        foreach ($this->subagentSourceFiles($paths) as $file) {
            $entries[] = substr($file, 0, -3) . $targetSuffix;
        }

        sort($entries, SORT_STRING);

        return $entries;
    }

    /**
     * Codex hook entries the bundled definition projects.
     *
     * An unreadable definition is reported with its reason rather than as an
     * empty expectation, because "no hooks are expected here" would license a
     * removal that "we cannot read the hook definition" must not.
     */
    public function codexHookEntries(AgentAssetSourcePaths $paths): ManagedAssetEntryExpectation
    {
        $hooksRoot = $paths->absoluteHooksRoot();
        if (!is_file($hooksRoot . '/hooks.json')) {
            return ManagedAssetEntryExpectation::known([]);
        }

        $errors = CodexHooksDefinition::validationErrors($hooksRoot);
        if ($errors !== []) {
            return ManagedAssetEntryExpectation::unknown(
                'Bundled Codex hook definition is invalid: ' . implode('; ', $errors),
            );
        }

        try {
            $definition = CodexHooksDefinition::fromRoot($hooksRoot);
        } catch (InvalidArgumentException $exception) {
            return ManagedAssetEntryExpectation::unknown(
                'Bundled Codex hook definition could not be read: ' . $exception->getMessage(),
            );
        }

        $entries = ['hooks.json'];
        foreach ($definition->scriptNames() as $scriptName) {
            $entries[] = 'hooks/' . $scriptName;
        }
        sort($entries, SORT_STRING);

        return ManagedAssetEntryExpectation::known($entries);
    }

    public function claudeHookEntries(AgentAssetSourcePaths $paths): ManagedAssetEntryExpectation
    {
        $hooksRoot = $paths->absoluteClaudeHooksRoot();
        if (!is_file($hooksRoot . '/hooks.json')) {
            return ManagedAssetEntryExpectation::known([]);
        }

        $errors = ClaudeHooksDefinition::validationErrors($hooksRoot);
        if ($errors !== []) {
            return ManagedAssetEntryExpectation::unknown(
                'Bundled Claude hook definition is invalid: ' . implode('; ', $errors),
            );
        }

        try {
            $definition = ClaudeHooksDefinition::fromRoot($hooksRoot);
        } catch (InvalidArgumentException $exception) {
            return ManagedAssetEntryExpectation::unknown(
                'Bundled Claude hook definition could not be read: ' . $exception->getMessage(),
            );
        }

        $entries = ['settings.json#hooks'];
        foreach ($definition->scriptNames() as $scriptName) {
            $entries[] = 'hooks/' . $scriptName;
        }
        sort($entries, SORT_STRING);

        return ManagedAssetEntryExpectation::known($entries);
    }

    /** @return list<string> */
    public function subagentSourceFiles(AgentAssetSourcePaths $paths): array
    {
        $root = $paths->absoluteSubagentsRoot();
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !str_ends_with($entry, '.md')) {
                continue;
            }
            if (is_file($root . '/' . $entry)) {
                $files[] = $entry;
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }
}
