<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

use InvalidArgumentException;

final readonly class EditRequest
{
    /**
     * @param list<string> $mapPaths
     * @param list<string> $mapExcludes
     * @param list<string> $runnerArguments
     */
    public function __construct(
        public string $taskId,
        public string $target,
        public string $instruction,
        public string $projectRoot,
        public string $recallRoot,
        public string $mapIndex,
        public string $mapRoot,
        public string $outputDirectory,
        public array $mapPaths = ['.'],
        public array $mapExcludes = [],
        public ?string $phpStanConfiguration = null,
        public bool $forceMapRebuild = false,
        public bool $allowMapRebuild = true,
        public bool $dryRun = false,
        public string $runner = 'stdout',
        public ?string $runnerCommand = null,
        public array $runnerArguments = [],
        public int $runnerTimeoutSeconds = 900,
        public bool $printPrompt = false,
    ) {
        foreach ([
            'task ID' => $taskId,
            'target' => $target,
            'instruction' => $instruction,
            'project root' => $projectRoot,
            'recall root' => $recallRoot,
            'map index' => $mapIndex,
            'map root' => $mapRoot,
            'output directory' => $outputDirectory,
        ] as $name => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Edit ' . $name . ' must not be empty.');
            }
        }

        if (!in_array($runner, ['stdout', 'command'], true)) {
            throw new InvalidArgumentException('Unknown edit runner: ' . $runner);
        }
        if ($runner === 'command' && ($runnerCommand === null || trim($runnerCommand) === '')) {
            throw new InvalidArgumentException('The command runner requires --runner-command.');
        }
        if ($runnerTimeoutSeconds < 1 || $runnerTimeoutSeconds > 86400) {
            throw new InvalidArgumentException('Runner timeout must be between 1 and 86400 seconds.');
        }
        if ($mapPaths === []) {
            throw new InvalidArgumentException('At least one map path is required.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => '1.0',
            'task_id' => $this->taskId,
            'target' => $this->target,
            'instruction' => $this->instruction,
            'project_root' => $this->projectRoot,
            'recall_root' => $this->recallRoot,
            'map_index' => $this->mapIndex,
            'map_root' => $this->mapRoot,
            'map_paths' => $this->mapPaths,
            'map_excludes' => $this->mapExcludes,
            'phpstan_configuration' => $this->phpStanConfiguration,
            'force_map_rebuild' => $this->forceMapRebuild,
            'allow_map_rebuild' => $this->allowMapRebuild,
            'dry_run' => $this->dryRun,
            'runner' => [
                'name' => $this->runner,
                'command' => $this->runnerCommand,
                'arguments' => $this->runnerArguments,
                'timeout_seconds' => $this->runnerTimeoutSeconds,
                'print_prompt' => $this->printPrompt,
            ],
        ];
    }
}
