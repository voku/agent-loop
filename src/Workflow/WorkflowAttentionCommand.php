<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use voku\AgentLoop\Execution\AttentionResolutionStore;
use voku\AgentLoop\Execution\ExecutionPlanStore;
use voku\AgentLoop\Execution\ExecutionStateStore;
use voku\AgentLoop\Run\GovernedRun;
use voku\AgentLoop\Run\GovernedRunStore;

final readonly class WorkflowAttentionCommand
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        try {
            $taskId = (new WorkflowTaskId($args[0] ?? ''))->value;
            $attentionId = null;
            $actor = null;
            $tokens = array_slice($args, 1);
            for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
                $token = $tokens[$i];
                if (!in_array($token, ['--resolve', '--by'], true)) {
                    throw new InvalidArgumentException('Unknown option: ' . $token);
                }
                if (!isset($tokens[$i + 1]) || str_starts_with($tokens[$i + 1], '--')) {
                    throw new InvalidArgumentException($token . ' requires a value.');
                }
                $value = trim($tokens[++$i]);
                if ($value === '') {
                    throw new InvalidArgumentException($token . ' requires a non-empty value.');
                }
                if ($token === '--resolve') {
                    $attentionId = $value;
                } else {
                    $actor = $value;
                }
            }
            if ($attentionId === null || $actor === null) {
                throw new InvalidArgumentException('Usage: workflow attention <task-id> --resolve <attention-id> --by <actor>.');
            }

            $contract = (new TaskContractStore($this->rootPath))->load($taskId);
            if ($contract->status !== TaskContract::APPROVED) {
                throw new RuntimeException('Attention resolution requires the current Contract to be approved.');
            }
            $run = (new GovernedRunStore($this->rootPath))->findForContract($contract);
            if (!$run instanceof GovernedRun) {
                throw new RuntimeException('Attention resolution requires the current governed Run.');
            }
            $plan = (new ExecutionPlanStore($this->rootPath))->prepare($run, $contract);
            $state = new ExecutionStateStore($this->rootPath);
            $projection = $state->projection($plan);
            $attention = $projection->attention;
            if ($attention === null || !hash_equals($attention->id, $attentionId)) {
                throw new RuntimeException('No matching pending Attention exists for this execution.');
            }

            $resolution = (new AttentionResolutionStore($this->rootPath))->record($plan, $attention, $actor);
            $after = $state->resolveAttention($plan, $resolution->attentionId);
            echo sprintf(
                "[OK] workflow attention: %s resolved by %s; stage %s attempt %d is current\n",
                $resolution->attentionId,
                $resolution->resolvedBy,
                $after->currentStageId ?? 'complete',
                $after->currentAttempt,
            );

            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, '[FAIL] workflow attention: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }
}
