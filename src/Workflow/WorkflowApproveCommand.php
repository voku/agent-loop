<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use voku\AgentLoop\Init\RepositoryActivation;

final readonly class WorkflowApproveCommand
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $isJson = $this->isJsonRequested($args);

        try {
            $taskId = new WorkflowTaskId($args[0] ?? '');
            $options = $this->parse(array_slice($args, 1));
            $contracts = new TaskContractStore($this->rootPath);
            $contract = $contracts->load($taskId->value);

            $status = 'approved';
            if ($contract->status !== TaskContract::APPROVED) {
                // Approval records authority and nothing else. Map discovery is
                // deterministic preparation, so `enter` reconciles it rather
                // than the host being told to run agent-map first.
                $contract = $contracts->approve($taskId->value, $options['by']);
                $msg = "[OK] workflow approve: Contract revision {$contract->revision} approved for {$taskId->value}\n";
            } else {
                $status = 'already_approved';
                $msg = "[OK] workflow approve: current Contract revision is already approved\n";
            }

            $cli = (new RepositoryActivation($this->rootPath))->cliPath();
            if ($options['format'] === 'json') {
                $nextAction = $cli . ' enter ' . $taskId->value . ' --format=json';
                echo json_encode([
                    'schema_version' => '1.0',
                    'command' => 'workflow approve',
                    'task_id' => $taskId->value,
                    'status' => $status,
                    'revision' => $contract->revision,
                    'next_action' => $nextAction,
                    'next_action_kind' => 'command',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

                return 0;
            }

            echo $msg;
            echo "[NEXT] {$cli} enter {$taskId->value}\n";

            return 0;
        } catch (Throwable $exception) {
            if ($isJson) {
                $candidate = $args[0] ?? null;
                $taskIdVal = is_string($candidate) && !str_starts_with($candidate, '-') ? $candidate : null;
                echo json_encode([
                    'schema_version' => '1.0',
                    'command' => 'workflow approve',
                    'task_id' => $taskIdVal,
                    'status' => 'error',
                    'error' => $exception->getMessage(),
                    'next_action_kind' => 'host_work',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

                return 1;
            }

            if ($exception instanceof RuntimeException) {
                fwrite(
                    STDERR,
                    '[FAIL] workflow approve: ' . $exception->getMessage()
                    . "\n[ACTION REQUIRED] Repair the reported approval prerequisite and rerun workflow approve.\n",
                );

                return 1;
            }

            fwrite(STDERR, '[FAIL] workflow approve: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    /** @param list<string> $args */
    private function isJsonRequested(array $args): bool
    {
        foreach ($args as $index => $arg) {
            if ($arg === '--format=json' || $arg === '--json') {
                return true;
            }
            if ($arg === '--format' && ($args[$index + 1] ?? null) === 'json') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $tokens
     * @return array{by: string, format: string}
     */
    private function parse(array $tokens): array
    {
        $by = null;
        $format = 'text';
        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if ($token === '--json') {
                $format = 'json';
                continue;
            }
            if ($token === '--format=json' || $token === '--format=text') {
                $format = substr($token, 9);
                continue;
            }
            if ($token === '--format') {
                if (!isset($tokens[$index + 1]) || str_starts_with($tokens[$index + 1], '--')) {
                    throw new InvalidArgumentException('--format requires a value.');
                }
                $formatVal = trim($tokens[++$index]);
                if (!in_array($formatVal, ['text', 'json'], true)) {
                    throw new InvalidArgumentException('--format must be "text" or "json".');
                }
                $format = $formatVal;
                continue;
            }
            if ($token !== '--by') {
                throw new InvalidArgumentException('Unknown option: ' . $token);
            }
            if (!isset($tokens[$index + 1]) || str_starts_with($tokens[$index + 1], '--')) {
                throw new InvalidArgumentException('--by requires a value.');
            }
            $value = trim($tokens[++$index]);
            if ($value === '') {
                throw new InvalidArgumentException($token . ' requires a non-empty value.');
            }
            $by = $value;
        }
        if ($by === null) {
            throw new InvalidArgumentException('--by is required.');
        }

        return ['by' => $by, 'format' => $format];
    }
}
