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
    ) {
    }

    /**
     * @param array<string, string> $configPaths
     * @param array<string, string> $cliOverrides
     */
    public static function fromSources(string $rootPath, array $configPaths = [], array $cliOverrides = []): self
    {
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
