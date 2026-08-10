<?php

declare(strict_types=1);

namespace voku\AgentLoop;

use voku\AgentLoop\Init\InitConfigLoader;

/**
 * Resolves repository-local workflow state without changing the project/source root.
 *
 * Legacy layout remains the default. Compact layout keeps focused-package state
 * below .agent-loop/ so a host library can exclude the complete workflow state
 * from distribution with one directory rule.
 */
final readonly class ProjectLayout
{
    private const CONFIG_RELATIVE = '.agent-loop/init.json';

    public function __construct(private string $projectRoot)
    {
    }

    public function isCompact(): bool
    {
        return $this->config()['layout'] === 'compact';
    }

    public function stateRoot(): string
    {
        return $this->isCompact()
            ? $this->projectRoot() . '/.agent-loop'
            : $this->projectRoot();
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

    public function learningRoot(): ?string
    {
        if ($this->isCompact()) {
            return $this->stateRoot() . '/learning';
        }

        foreach (['infra/doc/agent-learning', 'learning-root'] as $relativePath) {
            $candidate = $this->projectRoot() . '/' . $relativePath;
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function recallRoot(): string
    {
        $configured = $this->config()['paths']['recall_root'] ?? null;
        if (is_string($configured) && $configured !== '') {
            return PathResolver::join($this->projectRoot(), $configured);
        }

        if ($this->isCompact()) {
            return $this->stateRoot() . '/recall';
        }

        if (is_dir($this->projectRoot() . '/infra/doc/agent-learning')) {
            return $this->projectRoot() . '/infra/doc/agent-learning/recall-output';
        }

        return $this->projectRoot() . '/recall';
    }

    public function mapIndex(): string
    {
        return $this->isCompact()
            ? $this->stateRoot() . '/map/php-symbols.json'
            : $this->projectRoot() . '/.agent-map/php-symbols.json';
    }

    public function mapSearchIndex(): string
    {
        return $this->isCompact()
            ? $this->stateRoot() . '/map/search.sqlite'
            : $this->projectRoot() . '/.agent-map/search.sqlite';
    }

    private function projectRoot(): string
    {
        return rtrim($this->projectRoot, '/');
    }

    /**
     * @return array{
     *     warnings: list<string>,
     *     layout: 'legacy'|'compact',
     *     paths: array<string, string>,
     *     agents: array<string, array<string, string>>
     * }
     */
    private function config(): array
    {
        return (new InitConfigLoader($this->projectRoot()))->load(self::CONFIG_RELATIVE);
    }
}
