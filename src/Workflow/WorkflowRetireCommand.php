<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunManifestTransitionWriter;
use voku\AgentLoop\Run\RunRetirementStore;

/**
 * `workflow retire`: give a governed Run up on the record, instead of faking a successful close.
 * It never touches the Contract, the code or the board; it only writes the retirement.
 */
final readonly class WorkflowRetireCommand
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
                ?? throw new RuntimeException('Only a governed Run can be retired; ' . $taskId->value . ' has none.');
            $retirement = (new RunRetirementStore($this->rootPath))->record(
                $run,
                $options['status'],
                $options['reason'],
                $options['by'],
            );
            try {
                (new RunManifestTransitionWriter($this->rootPath))->writeRecoveryProjection($taskId->value);
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    'Retirement recorded, but manifest refresh failed: ' . $exception->getMessage(),
                    0,
                    $exception,
                );
            }
            echo "[OK] workflow retire: Run {$retirement->runId} of {$taskId->value} retired as {$retirement->status} by {$retirement->retiredBy}\n";

            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, '[FAIL] workflow retire: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @param list<string> $tokens
     * @return array{status: string, reason: string, by: string}
     */
    private function parse(array $tokens): array
    {
        $values = ['--status' => null, '--reason' => null, '--by' => null];
        for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i];
            if (!array_key_exists($token, $values)) {
                throw new InvalidArgumentException('Unknown option: ' . $token);
            }
            if (!isset($tokens[$i + 1]) || str_starts_with($tokens[$i + 1], '--') || trim($tokens[$i + 1]) === '') {
                throw new InvalidArgumentException($token . ' requires a value.');
            }
            $values[$token] = trim($tokens[++$i]);
        }
        foreach ($values as $option => $value) {
            if ($value === null) {
                throw new InvalidArgumentException($option . ' is required.');
            }
        }
        if (!in_array($values['--status'], RunRetirementStore::STATUSES, true)) {
            throw new InvalidArgumentException('--status must be superseded or abandoned.');
        }

        return ['status' => $values['--status'], 'reason' => (string) $values['--reason'], 'by' => (string) $values['--by']];
    }
}
