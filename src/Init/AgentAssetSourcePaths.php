<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use voku\AgentLoop\PackageResources;
use voku\AgentLoop\PathResolver;

final readonly class AgentAssetSourcePaths
{
    public function __construct(
        private string $rootPath,
        private string $skillsRoot,
        private string $subagentsRoot,
        private string $hooksRoot,
        private string $toolsRoot,
        private string $claudeHooksRoot,
        private bool $packageSkills = true,
        private bool $packageSubagents = true,
        /** @var list<string> */
        private array $extraSkillsRoots = [],
    ) {
    }

    /**
     * @param array<string, string> $configPaths
     * @param array<string, string> $cliOverrides
     * @param list<string> $extraSkillsRoots
     */
    public static function fromSources(
        string $rootPath,
        array $configPaths = [],
        array $cliOverrides = [],
        bool $packageSkills = true,
        bool $packageSubagents = true,
        array $extraSkillsRoots = [],
    ): self {
        $paths = [
            'skills_root' => PackageResources::SKILLS,
            'subagents_root' => PackageResources::SUBAGENTS,
            'codex_hooks_root' => PackageResources::hooks('codex'),
            'claude_hooks_root' => PackageResources::hooks('claude'),
            'tools_root' => PackageResources::TOOLS,
        ];

        foreach ($configPaths as $key => $value) {
            if ($value !== '' && array_key_exists($key, $paths)) {
                $paths[$key] = $value;
            }
        }

        $cliMap = [
            'skills-root' => 'skills_root',
            'subagents-root' => 'subagents_root',
            'hooks-root' => 'codex_hooks_root',
            'claude-hooks-root' => 'claude_hooks_root',
            'tools-root' => 'tools_root',
        ];

        foreach ($cliOverrides as $key => $value) {
            $mappedKey = $cliMap[$key] ?? null;
            if ($mappedKey !== null && $value !== '') {
                $paths[$mappedKey] = $value;
            }
        }

        return new self(
            $rootPath,
            $paths['skills_root'],
            $paths['subagents_root'],
            $paths['codex_hooks_root'],
            $paths['tools_root'],
            $paths['claude_hooks_root'],
            $packageSkills,
            $packageSubagents,
            $extraSkillsRoots,
        );
    }

    /**
     * The one place a loaded init config becomes source paths.
     *
     * The package asset policy travels with the paths. Callers that built paths
     * from `$config['paths']` alone silently fell back to the package default,
     * which is how `init status` and the sync commands came to compute a
     * different desired set than `install-assets` for the same config.
     *
     * @param array{paths: array<string, string>, package_skills: bool, package_subagents: bool, extra_skills_roots: list<string>} $config
     * @param array<string, string> $cliOverrides
     */
    public static function fromConfig(string $rootPath, array $config, array $cliOverrides = []): self
    {
        return self::fromSources(
            $rootPath,
            $config['paths'],
            $cliOverrides,
            $config['package_skills'],
            $config['package_subagents'],
            $config['extra_skills_roots'],
        );
    }

    public function packageSkills(): bool
    {
        return $this->packageSkills;
    }

    public function packageSubagents(): bool
    {
        return $this->packageSubagents;
    }

    /** @return list<string> */
    public function extraSkillsRoots(): array
    {
        return $this->extraSkillsRoots;
    }

    /** @return list<string> */
    public function absoluteExtraSkillRoots(): array
    {
        return array_map($this->resolvePath(...), $this->extraSkillsRoots);
    }

    public function withPackageSkills(bool $packageSkills): self
    {
        return new self(
            $this->rootPath,
            $this->skillsRoot,
            $this->subagentsRoot,
            $this->hooksRoot,
            $this->toolsRoot,
            $this->claudeHooksRoot,
            $packageSkills,
            $this->packageSubagents,
            $this->extraSkillsRoots,
        );
    }

    public function withPackageSubagents(bool $packageSubagents): self
    {
        return new self(
            $this->rootPath,
            $this->skillsRoot,
            $this->subagentsRoot,
            $this->hooksRoot,
            $this->toolsRoot,
            $this->claudeHooksRoot,
            $this->packageSkills,
            $packageSubagents,
            $this->extraSkillsRoots,
        );
    }

    public function skillsRoot(): string
    {
        return $this->skillsRoot;
    }

    public function subagentsRoot(): string
    {
        return $this->subagentsRoot;
    }

    public function hooksRoot(): string
    {
        return $this->hooksRoot;
    }

    public function toolsRoot(): string
    {
        return $this->toolsRoot;
    }

    public function claudeHooksRoot(): string
    {
        return $this->claudeHooksRoot;
    }

    public function absoluteClaudeHooksRoot(): string
    {
        return $this->resolvePath($this->claudeHooksRoot);
    }

    public function absoluteSkillsRoot(): string
    {
        return $this->resolvePath($this->skillsRoot);
    }

    public function absoluteSubagentsRoot(): string
    {
        return $this->resolvePath($this->subagentsRoot);
    }

    /**
     * Whether a resolved source lives under the configured project skills root.
     *
     * Default-mode sync materializes exactly this part of the desired set; the
     * rest of it is only retained.
     */
    public function containsSkillSource(string $sourcePath): bool
    {
        return self::contains($this->absoluteSkillsRoot(), $sourcePath);
    }

    /** Whether a resolved source lives under the configured project subagents root. */
    public function containsSubagentSource(string $sourcePath): bool
    {
        return self::contains($this->absoluteSubagentsRoot(), $sourcePath);
    }

    private static function contains(string $root, string $path): bool
    {
        $realRoot = realpath($root);
        if ($realRoot === false) {
            return false;
        }

        $realPath = realpath($path);
        $candidate = $realPath === false ? $path : $realPath;

        return $candidate === $realRoot || str_starts_with($candidate, rtrim($realRoot, '/') . '/');
    }

    public function absoluteHooksRoot(): string
    {
        return $this->resolvePath($this->hooksRoot);
    }

    public function absoluteToolsRoot(): string
    {
        return $this->resolvePath($this->toolsRoot);
    }

    private function resolvePath(string $path): string
    {
        return PathResolver::join($this->rootPath, $path);
    }
}
