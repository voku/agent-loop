<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use Throwable;
use voku\AgentLoop\Execution\AttentionResolutionStore;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Execution\ExecutionPlanStore;

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
            $tokens = array_slice($args, 1);
            $attentionId = null;
            $actor = null;
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

            $gateway = new ExecutionGateway($this->rootPath);
            $projection = $gateway->projection($taskId);
            $attention = $projection->attention;
            if ($attention === null || $attention->id !== $attentionId) {
                throw new InvalidArgumentException('No matching pending Attention exists for this execution.');
            }
            $plan = (new ExecutionPlanStore($this->rootPath))->load($taskId);
            (new AttentionResolutionStore($this->rootPath))->record(
                $plan,
                $attention,
                $projection->currentAttempt,
                $actor,
            );
            $resumed = $gateway->resolveAttention($taskId, $attentionId);
            echo sprintf(
                "[OK] workflow attention: %s resolved by %s; stage %s attempt %d is current\n",
                $attentionId,
                $actor,
                $resumed->currentStageId ?? 'complete',
                $resumed->currentAttempt,
            );

            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, '[FAIL] workflow attention: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }
}
