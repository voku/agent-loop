<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use Throwable;
use voku\AgentLoop\Cli\OptionTokens;
use voku\AgentLoop\Run\RunPolicyEvaluation;

/**
 * Inspects captured validation diagnostics and projects a bounded auto-repair bundle.
 *
 * Enforces attempt ceilings so agents cannot loop indefinitely on unfixable errors.
 */
final readonly class WorkflowRepairCommand
{
    public const int DEFAULT_MAX_ATTEMPTS = 2;

    public function __construct(private string $rootPath)
    {
    }

    /**
     * @param list<string> $args
     */
    public function run(array $args): int
    {
        try {
            if ($args === [] || in_array($args[0], ['help', '--help', '-h'], true)) {
                echo "Usage: agent-loop repair <task-id> [--max-attempts=2] [--format text|json]\n";
                echo "Inspect the latest validation failure and project a bounded repair instruction.\n";

                return 0;
            }

            $taskId = new WorkflowTaskId($args[0]);
            $tokens = array_slice($args, 1);
            $format = OptionTokens::value($tokens, 'format') ?? 'text';
            if (!in_array($format, ['text', 'json'], true)) {
                throw new InvalidArgumentException('--format must be text or json.');
            }

            $maxAttemptsRaw = OptionTokens::value($tokens, 'max-attempts');
            $maxAttempts = $maxAttemptsRaw !== null ? max(1, min(5, (int) $maxAttemptsRaw)) : self::DEFAULT_MAX_ATTEMPTS;

            $store = new ValidationDiagnosticStore($this->rootPath);
            $diagnostic = $store->latest($taskId->value);

            if ($diagnostic === null || $diagnostic->exitCode === 0) {
                if ($format === 'json') {
                    echo json_encode([
                        'schema_version' => '1.0',
                        'command' => 'repair',
                        'task_id' => $taskId->value,
                        'status' => 'no_repair_needed',
                        'message' => 'No validation failure recorded for task ' . $taskId->value . '.',
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                } else {
                    echo "[OK] No repair needed: no validation failure recorded for task {$taskId->value}.\n";
                }

                return 0;
            }

            $currentAttempts = $store->repairAttemptCount($taskId->value, $diagnostic->contractRevision);

            if ($currentAttempts >= $maxAttempts) {
                $payload = [
                    'schema_version' => '1.0',
                    'command' => 'repair',
                    'task_id' => $taskId->value,
                    'status' => 'exhausted',
                    'attempt' => $currentAttempts,
                    'max_attempts' => $maxAttempts,
                    'message' => sprintf(
                        'Bounded repair limit reached (%d of %d attempts). Human escalation required.',
                        $currentAttempts,
                        $maxAttempts,
                    ),
                    'next_action' => 'Escalate to human decision: repair budget exhausted for task ' . $taskId->value,
                    'next_action_kind' => RunPolicyEvaluation::KIND_DECISION_REQUIRED,
                ];

                if ($format === 'json') {
                    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                } else {
                    echo sprintf(
                        "[BLOCKED] repair: Bounded repair limit reached (%d of %d attempts) for %s.\nHuman escalation required.\n",
                        $currentAttempts,
                        $maxAttempts,
                        $taskId->value,
                    );
                }

                return 1;
            }

            $newAttempt = $store->incrementRepairAttempt($taskId->value, $diagnostic->contractRevision);
            $instruction = $this->buildRepairInstruction($diagnostic);

            $payload = [
                'schema_version' => '1.0',
                'command' => 'repair',
                'task_id' => $taskId->value,
                'status' => 'ready',
                'attempt' => $newAttempt,
                'max_attempts' => $maxAttempts,
                'failing_command' => $diagnostic->command,
                'tool_category' => $diagnostic->toolCategory,
                'errors' => $diagnostic->errors,
                'repair_instruction' => $instruction,
                'next_action' => 'Apply repair and run agent-loop finish ' . $taskId->value,
                'next_action_kind' => RunPolicyEvaluation::KIND_HOST_WORK,
            ];

            if ($format === 'json') {
                echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            } else {
                echo sprintf("agent-loop repair %s (Attempt %d of %d)\n", $taskId->value, $newAttempt, $maxAttempts);
                echo "Tool: " . $diagnostic->toolCategory . "\n";
                echo "Command: " . $diagnostic->command . "\n";
                echo "Errors:\n";
                foreach ($diagnostic->errors as $error) {
                    $loc = isset($error['file']) ? $error['file'] . (isset($error['line']) ? ':' . $error['line'] : '') . ' - ' : '';
                    echo "  * " . $loc . $error['message'] . "\n";
                }
                echo "\nRepair Instruction:\n  " . $instruction . "\n";
                echo "\nNext: Apply repair and run agent-loop finish " . $taskId->value . "\n";
            }

            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, '[FAIL] repair: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    private function buildRepairInstruction(ValidationDiagnostic $diagnostic): string
    {
        $firstError = $diagnostic->errors[0] ?? null;
        if ($firstError !== null && isset($firstError['file'])) {
            $loc = $firstError['file'] . (isset($firstError['line']) ? ':' . $firstError['line'] : '');

            return sprintf(
                'Fix the specific validation failure in %s (%s) without modifying unrelated files.',
                $loc,
                $firstError['message'],
            );
        }

        if ($firstError !== null) {
            return sprintf(
                'Resolve the validation error from `%s`: %s.',
                $diagnostic->command,
                $firstError['message'],
            );
        }

        return sprintf(
            'Resolve the failure from `%s` without expanding scope.',
            $diagnostic->command,
        );
    }
}
