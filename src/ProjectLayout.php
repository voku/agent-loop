<?php

declare(strict_types=1);

namespace voku\AgentLoop;

use voku\AgentLoop\Init\InitConfigLoader;

/**
 * The single owner of every repository-local workflow path.
 *
 * Nothing else may spell a state location. Each private literal is a second
 * answer to "where does this live", and the two answers agree only in the
 * default configuration: that is how a read-only projection kept reading a
 * pre-consolidation session root long after the migration, and how a Run's
 * Learning close-out became invisible to the projection that had to explain it.
 *
 * `describe()` exists so a coding agent can ask instead of hardcoding.
 */
final readonly class ProjectLayout
{
    private const string CONFIG_RELATIVE = '.agent-loop/init.json';

    private const string DEFAULT_STATE_ROOT = '.agent-loop';

    public function __construct(private string $projectRoot)
    {
    }

    public function stateRoot(): string
    {
        return $this->configured('state_root') ?? $this->projectRoot() . '/' . self::DEFAULT_STATE_ROOT;
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
        return $this->configured('sessions_root') ?? $this->stateRoot() . '/sessions';
    }

    public function learningRoot(): string
    {
        return $this->configured('learning_root') ?? $this->stateRoot() . '/learning';
    }

    public function recallRoot(): string
    {
        return $this->configured('recall_root') ?? $this->stateRoot() . '/recall';
    }

    public function runsRoot(): string
    {
        return $this->stateRoot() . '/runs';
    }

    /** Everything durable about one governed Run lives under this directory. */
    public function runRoot(string $taskId): string
    {
        return $this->runsRoot() . '/' . $taskId;
    }

    public function contractsRoot(): string
    {
        return $this->stateRoot() . '/contracts';
    }

    public function contractPath(string $taskId): string
    {
        return $this->contractsRoot() . '/' . $taskId . '/contract.json';
    }

    public function editRoot(): string
    {
        return $this->stateRoot() . '/edit';
    }

    public function editBundle(string $taskId): string
    {
        return $this->editRoot() . '/' . $taskId;
    }

    public function risksRoot(): string
    {
        return $this->stateRoot() . '/risks';
    }

    public function mapRoot(): string
    {
        return $this->stateRoot() . '/map';
    }

    public function mapIndex(): string
    {
        return $this->mapRoot() . '/php-symbols.json';
    }

    public function mapSearchIndex(): string
    {
        return $this->mapRoot() . '/search.sqlite';
    }

    public function mapHistoryDatabase(): string
    {
        return $this->mapRoot() . '/history.sqlite';
    }

    public function toolInventory(): string
    {
        return $this->stateRoot() . '/tool-inventory.json';
    }

    public function gitHooksConfig(): string
    {
        return $this->stateRoot() . '/githooks.json';
    }

    public function configPath(): string
    {
        return $this->projectRoot() . '/' . self::CONFIG_RELATIVE;
    }

    /** Renders an owned absolute path back to how a report or message should show it. */
    public function display(string $absolutePath): string
    {
        return PathResolver::relativeTo($this->projectRoot(), $absolutePath);
    }

    /**
     * Machine-readable layout, so an agent can discover paths instead of
     * assuming them. Values are project-root-relative where they live inside
     * the repository, and absolute when a configured override points elsewhere.
     *
     * @return array<string, string>
     */
    public function describe(): array
    {
        $described = [
            'project_root' => $this->projectRoot(),
            'state_root' => $this->stateRoot(),
            'config' => $this->configPath(),
            'board_root' => $this->boardRoot(),
            'tasks_root' => $this->tasksRoot(),
            'sessions_root' => $this->sessionsRoot(),
            'learning_root' => $this->learningRoot(),
            'recall_root' => $this->recallRoot(),
            'runs_root' => $this->runsRoot(),
            'contracts_root' => $this->contractsRoot(),
            'edit_root' => $this->editRoot(),
            'risks_root' => $this->risksRoot(),
            'map_root' => $this->mapRoot(),
            'map_index' => $this->mapIndex(),
            'map_search_index' => $this->mapSearchIndex(),
            'map_history_database' => $this->mapHistoryDatabase(),
            'tool_inventory' => $this->toolInventory(),
            'githooks_config' => $this->gitHooksConfig(),
        ];

        foreach ($described as $key => $path) {
            if ($key !== 'project_root') {
                $described[$key] = $this->display($path);
            }
        }

        return $described;
    }

    private function projectRoot(): string
    {
        return rtrim(str_replace('\\', '/', $this->projectRoot), '/');
    }

    private function configured(string $key): ?string
    {
        $value = $this->config()['paths'][$key] ?? null;

        return is_string($value) && $value !== ''
            ? PathResolver::join($this->projectRoot(), $value)
            : null;
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
