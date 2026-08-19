<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Dogfood\ProcessRunner;

/** @internal */
final class ProportionalGovernanceMeasurementTest extends TestCase
{
    public function testPreLifecycleMapPreparationIsNotHiddenByTheWorkflowWindow(): void
    {
        $workspace = sys_get_temp_dir() . '/agent-loop-governance-measurement-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($workspace, 0o775, true));
        $reportPath = $workspace . '/report.json';

        try {
            $report = [
                'schema_version' => '2.0',
                'result' => 'passed',
                'scenarios' => [
                    [
                        'id' => 'map.consumer-boundary',
                        'status' => 'passed',
                        'commands' => [
                            ['display' => 'vendor/bin/agent-map build --root=. --paths=src,tests'],
                            ['display' => 'vendor/bin/agent-map search-index build --root=.'],
                            ['display' => 'vendor/bin/agent-map search RetryPolicy --format=json'],
                        ],
                    ],
                ],
                'front_door_journey' => [
                    'repeated_same_state_commands' => 0,
                    'context_lines' => 0,
                    'context_bytes' => 0,
                ],
                'recovery_convergence' => [],
                'friction' => [],
            ];
            file_put_contents(
                $reportPath,
                json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            );

            $result = (new ProcessRunner(dirname(__DIR__)))->run([
                PHP_BINARY,
                'tools/proportional-governance-measurement.php',
                '--workspace=' . $workspace,
                '--report=' . $reportPath,
            ]);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            $measured = json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($measured);
            $measurement = $measured['governance_measurement'] ?? null;
            self::assertIsArray($measurement);
            self::assertSame(0, $measurement['manual_preparation_commands'] ?? null);
            self::assertSame(2, $measurement['pre_lifecycle_preparation_commands'] ?? null);
            self::assertSame(2, $measurement['total_manual_preparation_commands'] ?? null);
        } finally {
            if (is_file($reportPath)) {
                unlink($reportPath);
            }
            if (is_dir($workspace)) {
                rmdir($workspace);
            }
        }
    }
}
