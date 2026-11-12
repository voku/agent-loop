<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use RuntimeException;
use Throwable;
use voku\AgentLoop\Run\CanonicalJson;
use voku\AgentLoop\Run\RunRetentionInventory;

final readonly class WorkflowRetentionCommand
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        if (in_array($args[0] ?? null, ['help', '--help', '-h'], true)) {
            echo "Usage: agent-loop workflow retention report [--older-than-days=90] [--format=text|json]\n";

            return 0;
        }
        if (($args[0] ?? null) !== 'report') {
            fwrite(STDERR, "[FAIL] workflow retention: expected report. Use workflow retention help.\n");

            return 1;
        }

        try {
            $days = 90;
            $format = 'text';
            foreach (array_slice($args, 1) as $arg) {
                if (str_starts_with($arg, '--older-than-days=')) {
                    $value = substr($arg, strlen('--older-than-days='));
                    if (preg_match('/^(0|[1-9][0-9]{0,5})$/', $value) !== 1) {
                        throw new RuntimeException('Expected non-negative --older-than-days (up to six digits).');
                    }
                    $days = (int) $value;

                    continue;
                }
                if (str_starts_with($arg, '--format=')) {
                    $format = substr($arg, strlen('--format='));
                    if (!in_array($format, ['text', 'json'], true)) {
                        throw new RuntimeException('Expected --format=text or --format=json.');
                    }

                    continue;
                }

                throw new RuntimeException('Unexpected argument: ' . $arg);
            }

            $report = (new RunRetentionInventory($this->rootPath))->inspect($days);
            if ($format === 'json') {
                echo CanonicalJson::pretty($report);

                return 0;
            }

            $totals = $report['totals'];
            printf(
                "Governed Runs: %d current, %d historical; %d files, %d bytes.\n",
                $totals['current'], $totals['history'], $totals['files'], $totals['bytes'],
            );
            printf(
                "Age threshold: %d days; %d meet it. Age is not archival authorization.\n",
                $days, $totals['age_threshold_met'],
            );
            foreach ($report['records'] as $record) {
                printf(
                    "  %s [%s] %s: BLOCKED (%s)\n",
                    $record['path'], $record['scope'], $record['run_document'],
                    implode(', ', $record['reasons']),
                );
            }

            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, '[FAIL] workflow retention: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }
}
