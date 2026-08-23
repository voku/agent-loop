<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use Throwable;
use voku\AgentLoop\Execution\ExecutionProfileName;
use voku\AgentLoop\Execution\ExecutionProfileSelectionStore;

final readonly class WorkflowExecutionProfileCommand
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        try {
            $taskId = (new WorkflowTaskId($args[0] ?? ''))->value;
            $tokens = array_slice($args, 1);
            if ($tokens === []) {
                $selection = (new ExecutionProfileSelectionStore($this->rootPath))->find($taskId);
                echo ($selection === null ? ExecutionProfileName::MANUAL : $selection->profile)->value . "\n";

                return 0;
            }

            $profile = null;
            $actor = null;
            for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
                $token = $tokens[$i];
                if (!in_array($token, ['--profile', '--by'], true)) {
                    throw new InvalidArgumentException('Unknown option: ' . $token);
                }
                if (!isset($tokens[$i + 1]) || str_starts_with($tokens[$i + 1], '--')) {
                    throw new InvalidArgumentException($token . ' requires a value.');
                }
                $value = trim($tokens[++$i]);
                if ($value === '') {
                    throw new InvalidArgumentException($token . ' requires a non-empty value.');
                }
                if ($token === '--profile') {
                    $profile = ExecutionProfileName::tryFrom($value)
                        ?? throw new InvalidArgumentException('Unknown execution profile: ' . $value);
                } else {
                    $actor = $value;
                }
            }
            if (!$profile instanceof ExecutionProfileName || $actor === null) {
                throw new InvalidArgumentException('Usage: workflow execution-profile <task-id> --profile manual|surgical|standard|hardened --by <actor>.');
            }

            $selection = (new ExecutionProfileSelectionStore($this->rootPath))->select($taskId, $profile, $actor);
            echo sprintf(
                "[OK] workflow execution-profile: %s selected for %s Contract revision %d\n",
                $selection->profile->value,
                $selection->taskId,
                $selection->contractRevision,
            );

            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, '[FAIL] workflow execution-profile: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }
}
