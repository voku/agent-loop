<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use Throwable;
use voku\AgentLearning\RunLearningDecisionStatus;
use voku\AgentLearning\RunLearningDecisionStore;
use voku\AgentLoop\Run\GovernedRunStore;

final readonly class WorkflowLearningCommand
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        try {
            $taskId = new WorkflowTaskId($args[0] ?? '');
            $options = $this->parse(array_slice($args, 1));
            $run = (new GovernedRunStore($this->rootPath))->find($taskId->value)
                ?? throw new InvalidArgumentException('No governed Run exists for task ' . $taskId->value . '.');
            $decision = (new RunLearningDecisionStore($run->learningRoot($this->rootPath)))->record(
                $run->runId,
                $options['status'],
                $options['by'],
                $options['reason'],
                $options['findingIds'],
                $options['followUp'],
            );
            echo "[OK] workflow learn: {$decision->decision->value} recorded for {$run->runId}\n";

            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, '[FAIL] workflow learn: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @param list<string> $tokens
     * @return array{status: RunLearningDecisionStatus, by: string, reason: string, findingIds: list<string>, followUp: string|null}
     */
    private function parse(array $tokens): array
    {
        $status = null;
        $by = null;
        $reason = null;
        $followUp = null;
        $findingIds = [];

        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if (!in_array($token, ['--status', '--by', '--reason', '--finding', '--follow-up'], true)) {
                throw new InvalidArgumentException('Unknown option: ' . $token);
            }
            if (!isset($tokens[$index + 1]) || str_starts_with($tokens[$index + 1], '--')) {
                throw new InvalidArgumentException($token . ' requires a value.');
            }
            $value = trim($tokens[++$index]);
            if ($value === '') {
                throw new InvalidArgumentException($token . ' requires a non-empty value.');
            }
            if ($token === '--status') {
                $status = RunLearningDecisionStatus::tryFrom($value)
                    ?? throw new InvalidArgumentException('--status must be findings_recorded, no_durable_learning, or follow_up_required.');
            } elseif ($token === '--by') {
                $by = $value;
            } elseif ($token === '--reason') {
                $reason = $value;
            } elseif ($token === '--finding') {
                $findingIds[] = $value;
            } else {
                $followUp = $value;
            }
        }
        if (!$status instanceof RunLearningDecisionStatus || $by === null || $reason === null) {
            throw new InvalidArgumentException('--status, --by, and --reason are required.');
        }

        return [
            'status' => $status,
            'by' => $by,
            'reason' => $reason,
            'findingIds' => $findingIds,
            'followUp' => $followUp,
        ];
    }
}
