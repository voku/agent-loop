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
        $json = false;
        try {
            $taskId = (new WorkflowTaskId($args[0] ?? ''))->value;
            $tokens = array_slice($args, 1);
            $attentionId = null;
            $actor = null;
            $response = null;
            for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
                $token = $tokens[$i];
                if (str_starts_with($token, '--format=')) {
                    $json = self::isJsonFormat(substr($token, 9));

                    continue;
                }
                if ($token === '--format') {
                    $json = self::isJsonFormat($tokens[++$i] ?? '');

                    continue;
                }
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
                throw new InvalidArgumentException('Usage: workflow attention <task-id> --resolve <attention-id> --by <actor> [--response <text>] [--format text|json].');
            }

            $resumed = (new WorkflowHumanDecisionService($this->rootPath))->resolveAttention($taskId, $attentionId, $actor, $response);
            if ($json) {
                $nextAction = $resumed->currentStageId === null
                    ? 'agent-loop finish ' . $taskId
                    : 'agent-loop pipeline stage ' . $taskId;
                echo json_encode([
                    'schema_version' => '1.0',
                    'command' => 'workflow attention',
                    'status' => 'ok',
                    'task_id' => $taskId,
                    'attention_id' => $attentionId,
                    'resolved_by' => $actor,
                    'current_stage' => $resumed->currentStageId,
                    'attempt' => $resumed->currentAttempt,
                    'next_action' => $nextAction,
                    'next_action_kind' => 'command',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

                return 0;
            }
            echo sprintf(
                "[OK] workflow attention: %s resolved by %s; stage %s attempt %d is current\n",
                $attentionId,
                $actor,
                $resumed->currentStageId ?? 'complete',
                $resumed->currentAttempt,
            );

            return 0;
        } catch (Throwable $exception) {
            if ($json) {
                echo json_encode([
                    'schema_version' => '1.0',
                    'command' => 'workflow attention',
                    'status' => 'error',
                    'message' => $exception->getMessage(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

                return 1;
            }
            fwrite(STDERR, '[FAIL] workflow attention: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    private static function isJsonFormat(string $value): bool
    {
        if (!in_array($value, ['text', 'json'], true)) {
            throw new InvalidArgumentException('--format accepts text or json.');
        }

        return $value === 'json';
    }
}
