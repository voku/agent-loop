<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

final readonly class AgentAssetSourcePaths
{
    public function __construct(
        private string $rootPath,
        private string $skillsRoot,
        private string $subagentsRoot,
        private string $hooksRoot,
        private string $toolsRoot,
    ) {
    }

    /**
     * @param array<string, string> $configPaths
     * @param array<string, string> $cliOverrides
     */
    public static function fromSources(string $rootPath, array $configPaths = [], array $cliOverrides = []): self
    {
        $paths = [
            'skills_root' => 'docs/agents/skills',
            'subagents_root' => 'docs/agents/subagents',
            'codex_hooks_root' => 'docs/agents/codex-hooks',
            'tools_root' => 'docs/agents/tools',
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
        if ($path === '') {
            return rtrim(str_replace('\\', '/', $this->rootPath), '/');
        }

        if ($this->isAbsolutePath($path)) {
            return rtrim(str_replace('\\', '/', $path), '/');
        }

        return rtrim(str_replace('\\', '/', $this->rootPath), '/') . '/' . ltrim(str_replace('\\', '/', $path), '/');
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        // Unix / WSL absolute path
        if (str_starts_with($path, '/')) {
            return true;
        }

        // Windows absolute path with drive letter (e.g. C:\ or C:/)
        if (preg_match('/^[a-zA-Z]:[\\\\\/]/', $path) === 1) {
            return true;
        }

        // Windows UNC path (e.g. \\server\share or //server/share)
        if (str_starts_with($path, '\\\\') || str_starts_with($path, '//')) {
            return true;
        }

        return false;
    }
}
