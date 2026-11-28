<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * `workflow reject` and `workflow request-changes`: both end the current
 * candidate revision without approval and require a new revision; the recorded
 * rejection_kind keeps a change request distinct from a refusal.
 */
final readonly class WorkflowRejectCommand
{
    public function __construct(private string $rootPath, private string $kind = TaskContract::REJECTION)
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
                if (!in_array($option, ['--revision', '--by', '--reason', '--head', '--recorded-by'], true) || isset($options[$option])) {
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
                throw new InvalidArgumentException('Usage: workflow ' . $this->command() . ' <task-id> --revision <revision> --by <actor> --reason <reason> [--head <commit>] [--recorded-by <actor>].');
            }
            $decisions = new WorkflowHumanDecisionService($this->rootPath);
            // Same availability check and store call as the embedding API's
            // rejectContract(), which cannot carry a change-request kind or a recorder.
            if (!$decisions->availableActions($taskId)->allows(WorkflowHumanDecisionProjection::REJECT_CONTRACT)) {
                throw new RuntimeException($this->kind === TaskContract::CHANGE_REQUEST
                    ? 'A change request is not a currently available human action.'
                    : 'Contract rejection is not a currently available human action.');
            }
            (new TaskContractStore($this->rootPath))->reject($taskId, $options['--by'], $revision, $options['--reason'], $options['--head'] ?? null, $this->kind, $options['--recorded-by'] ?? null);
            if ($this->kind === TaskContract::REJECTION) {
                echo sprintf("[OK] workflow reject: Contract revision %d rejected for %s; revise the Contract before continuing.\n", $revision, $taskId);

                return 0;
            }
            echo sprintf("[OK] workflow request-changes: changes requested for %s Contract revision %d; it stays unapproved until a revised revision is approved.\n", $taskId, $revision);

            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, '[FAIL] workflow ' . $this->command() . ': ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    private function command(): string
    {
        return $this->kind === TaskContract::CHANGE_REQUEST ? 'request-changes' : 'reject';
    }
}
