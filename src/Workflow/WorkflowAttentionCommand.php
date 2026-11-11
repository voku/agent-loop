<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use Throwable;

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
            $response = null;
            for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
                $token = $tokens[$i];
                if (!in_array($token, ['--resolve', '--by', '--response'], true)) {
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
                } elseif ($token === '--by') {
                    $actor = $value;
                } else {
                    $response = $value;
                }
            }
            if ($attentionId === null || $actor === null) {
                throw new InvalidArgumentException('Usage: workflow attention <task-id> --resolve <attention-id> --by <actor> [--response <text>].');
            }

            $resumed = (new WorkflowHumanDecisionService($this->rootPath))->resolveAttention($taskId, $attentionId, $actor, $response);
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
