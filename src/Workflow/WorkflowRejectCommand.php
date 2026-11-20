<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use Throwable;

final readonly class WorkflowRejectCommand
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        try {
            $taskId = (new WorkflowTaskId($args[0] ?? ''))->value;
            $options = [];
            for ($i = 1, $count = count($args); $i < $count; ++$i) {
                [$option, $inline] = array_pad(explode('=', $args[$i], 2), 2, null);
                if (!in_array($option, ['--revision', '--by', '--reason', '--head'], true) || isset($options[$option])) {
                    throw new InvalidArgumentException('Unknown or duplicate option: ' . $option);
                }
                $value = $inline ?? ($args[++$i] ?? '');
                if ($value === '' || str_starts_with($value, '--')) {
                    throw new InvalidArgumentException($option . ' requires a value.');
                }
                $options[$option] = $value;
            }
            $revision = filter_var($options['--revision'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!is_int($revision) || !isset($options['--by'], $options['--reason'])) {
                throw new InvalidArgumentException('Usage: workflow reject <task-id> --revision <revision> --by <actor> --reason <reason>.');
            }
            (new WorkflowHumanDecisionService($this->rootPath))->rejectContract($taskId, $options['--by'], $revision, $options['--reason'], $options['--head'] ?? null);
            echo sprintf("[OK] workflow reject: Contract revision %d rejected for %s; revise the Contract before continuing.\n", $revision, $taskId);

            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, '[FAIL] workflow reject: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }
}
