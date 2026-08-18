<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use Closure;
use InvalidArgumentException;
use Throwable;
use voku\AgentLoop\Run\RunManifestProjector;
use voku\AgentRecallCompiler\Reflection\FutureWorkPromptBuilder;

final readonly class WorkflowReflectCommand
{
    private ?Closure $stateResolver;

    /** @param null|callable(string): string $stateResolver */
    public function __construct(private string $rootPath, ?callable $stateResolver = null)
    {
        $this->stateResolver = $stateResolver === null ? null : Closure::fromCallable($stateResolver);
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        try {
            $taskId = new WorkflowTaskId($args[0] ?? '');
            $scope = $this->scope(array_slice($args, 1));
            $state = $this->state($taskId->value);
            if (!in_array($state, ['ready_to_close', 'complete'], true)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Reflection requires a task that is ready to close or complete; current state is %s. Finish normal validation/review first.',
                        $state,
                    ),
                );
            }

            echo (new FutureWorkPromptBuilder())->buildFromString($scope) . "\n";

            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, '[FAIL] workflow reflect: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    /** @param list<string> $tokens */
    private function scope(array $tokens): string
    {
        if ($tokens === []) {
            return 'project';
        }
        if (count($tokens) !== 2 || $tokens[0] !== '--scope') {
            throw new InvalidArgumentException('Usage: workflow reflect <task-id> [--scope project|task].');
        }
        $scope = trim($tokens[1]);
        if (!in_array($scope, ['project', 'task'], true)) {
            throw new InvalidArgumentException('--scope must be project or task.');
        }

        return $scope;
    }

    private function state(string $taskId): string
    {
        if ($this->stateResolver !== null) {
            $state = ($this->stateResolver)($taskId);
            if ($state === '') {
                throw new InvalidArgumentException('Workflow reflection state resolver returned an empty state.');
            }

            return $state;
        }

        return (new RunManifestProjector($this->rootPath))->project($taskId)->state;
    }
}
