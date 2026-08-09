<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use voku\AgentLoop\Run\RunManifestTransitionWriter;

final readonly class WorkflowContractCommand
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
            $store = new ExecutionContractStore($this->rootPath);

            if ($options['status'] === ExecutionContractStatus::READY) {
                $source = $options['from'];
                if ($source === null || !is_file($source)) {
                    throw new RuntimeException('Ready execution contract requires --from <existing-markdown-file>.');
                }
                $content = file_get_contents($source);
                if ($content === false) {
                    throw new RuntimeException('Unable to read execution contract source: ' . $source);
                }
                $path = $store->writeReady($taskId->value, $options['by'], $content);
            } else {
                $path = $store->writeStopped(
                    $taskId->value,
                    $options['status'],
                    $options['by'],
                    $options['reason'] ?? '',
                    $options['evidence'],
                    $options['affectedConstraint'],
                    $options['minimumChange'] ?? '',
                );
            }

            $manifestPath = (new RunManifestTransitionWriter($this->rootPath))->write($taskId->value);
            echo '[OK] workflow contract: ' . $options['status']->value . ' contract recorded at ' . $path . "\n";
            echo '[OK] workflow contract: run manifest refreshed at ' . $manifestPath . "\n";
            echo "Next:\n  agent-loop workflow status {$taskId->value}\n";

            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, '[FAIL] workflow contract: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @param list<string> $tokens
     * @return array{status: ExecutionContractStatus, by: string, from: string|null, reason: string|null, evidence: list<string>, affectedConstraint: string|null, minimumChange: string|null}
     */
    private function parse(array $tokens): array
    {
        $status = null;
        $by = null;
        $from = null;
        $reason = null;
        $evidence = [];
        $affectedConstraint = null;
        $minimumChange = null;

        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if (!in_array($token, ['--status', '--by', '--from', '--reason', '--evidence', '--affected-constraint', '--minimum-change'], true)) {
                throw new InvalidArgumentException('Unknown option: ' . $token);
            }
            if (!isset($tokens[$index + 1]) || str_starts_with($tokens[$index + 1], '--')) {
                throw new InvalidArgumentException($token . ' requires a value.');
            }
            $value = trim($tokens[++$index]);
            if ($value === '') {
                throw new InvalidArgumentException($token . ' requires a non-empty value.');
            }

            match ($token) {
                '--status' => $status = ExecutionContractStatus::tryFrom(strtolower($value)),
                '--by' => $by = $value,
                '--from' => $from = $value,
                '--reason' => $reason = $value,
                '--evidence' => $evidence[] = $value,
                '--affected-constraint' => $affectedConstraint = $value,
                '--minimum-change' => $minimumChange = $value,
            };
        }

        if (!$status instanceof ExecutionContractStatus) {
            throw new InvalidArgumentException('--status must be ready, blocked, or rejected.');
        }
        if ($by === null) {
            throw new InvalidArgumentException('--by is required.');
        }
        if ($status === ExecutionContractStatus::READY) {
            if ($from === null) {
                throw new InvalidArgumentException('Ready execution contract requires --from.');
            }
            if ($reason !== null || $evidence !== [] || $affectedConstraint !== null || $minimumChange !== null) {
                throw new InvalidArgumentException('Ready execution contract does not accept stop-reason options.');
            }
        } elseif ($from !== null) {
            throw new InvalidArgumentException('Blocked/rejected execution contract must not use --from.');
        } elseif ($reason === null || $evidence === [] || $minimumChange === null) {
            throw new InvalidArgumentException('Blocked/rejected execution contract requires --reason, --evidence, and --minimum-change.');
        }

        return [
            'status' => $status,
            'by' => $by,
            'from' => $from,
            'reason' => $reason,
            'evidence' => $evidence,
            'affectedConstraint' => $affectedConstraint,
            'minimumChange' => $minimumChange,
        ];
    }
}
