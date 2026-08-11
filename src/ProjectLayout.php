<?php

declare(strict_types=1);

namespace voku\AgentLoop;

use voku\AgentLoop\Init\InitConfigLoader;

/**
 * Resolves repository-local workflow state without changing the project/source root.
 */
final readonly class ProjectLayout
{
    private const string CONFIG_RELATIVE = '.agent-loop/init.json';

    public function __construct(private string $projectRoot)
    {
    }

    public function stateRoot(): string
    {
        return $this->projectRoot() . '/.agent-loop';
    }

    public function boardRoot(): string
    {
        return $this->stateRoot();
    }

    public function tasksRoot(): string
    {
        return $this->stateRoot() . '/tasks';
    }

    public function sessionsRoot(): string
    {
        return $this->stateRoot() . '/sessions';
    }

    public function learningRoot(): string
    {
        return $this->stateRoot() . '/learning';
    }

    public function recallRoot(): string
    {
        $configured = $this->config()['paths']['recall_root'] ?? null;
        if (is_string($configured) && $configured !== '') {
            return PathResolver::join($this->projectRoot(), $configured);
        }

        return $this->stateRoot() . '/recall';
    }

    public function mapIndex(): string
    {
        return $this->stateRoot() . '/map/php-symbols.json';
    }

    public function mapSearchIndex(): string
    {
        return $this->stateRoot() . '/map/search.sqlite';
    }

    private function projectRoot(): string
    {
        return rtrim($this->projectRoot, '/');
    }

    /**
     * @return array{
     *     warnings: list<string>,
     *     paths: array<string, string>,
     *     agents: array<string, array<string, string>>
     * }
     */
    private function config(): array
    {
        return (new InitConfigLoader($this->projectRoot()))->load(self::CONFIG_RELATIVE);
    }
}
