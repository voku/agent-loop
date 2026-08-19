<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Dogfood\ProportionalGovernanceMeasurement;

final class ProportionalGovernanceMeasurementTest extends TestCase
{
    public function testDerivesMechanicalCostAndCapabilityObservations(): void
    {
        $workspace = $this->temporaryWorkspace();
        $status = json_encode([
            'manifest' => [
                'references' => [
                    'map' => ['state' => 'ready'],
                    'recall' => ['state' => 'compiled'],
                    'session' => ['state' => 'done'],
                    'execution_contract' => ['state' => 'not_required'],
                    'review' => ['state' => 'acknowledged'],
                    'learning' => ['state' => 'decided'],
                    'verification' => ['state' => 'passed'],
                ],
            ],
        ], JSON_THROW_ON_ERROR) . "\n";

        $actionOutputs = [
            'plan' => "planned\n",
            'approve' => "approved\n",
            'enter' => "entered\n",
            'validation-record' => "validation recorded\n",
            'review' => "reviewed\n",
            'learn' => "learning decided\n",
            'verify' => "verified\n",
            'finish' => "complete\n",
        ];
        $validationOutput = "tests passed\n";

        $report = [
            'scenarios' => [
                [
                    'id' => 'workflow.plan',
                    'commands' => [
                        $this->command($workspace, 'plan', 'vendor/bin/agent-loop workflow plan DEMO-1', $actionOutputs['plan']),
                    ],
                ],
                [
                    'id' => 'workflow.approve',
                    'commands' => [
                        $this->command($workspace, 'approve', 'vendor/bin/agent-loop workflow approve DEMO-1', $actionOutputs['approve']),
                        $this->command($workspace, 'enter', 'vendor/bin/agent-loop enter DEMO-1 --format=json', $actionOutputs['enter']),
                        $this->command($workspace, 'status-ready', 'vendor/bin/agent-loop workflow status DEMO-1 --format=json', $status),
                    ],
                ],
                [
                    'id' => 'workflow.validate',
                    'commands' => [
                        $this->command($workspace, 'composer-test', 'composer test', $validationOutput),
                        $this->command($workspace, 'validation-record', 'vendor/bin/agent-loop session validation record DEMO-1', $actionOutputs['validation-record']),
                    ],
                ],
                [
                    'id' => 'workflow.review',
                    'commands' => [
                        $this->command($workspace, 'review', 'vendor/bin/agent-loop review blindspots DEMO-1', $actionOutputs['review']),
                    ],
                ],
                [
                    'id' => 'workflow.learn',
                    'commands' => [
                        $this->command($workspace, 'learn', 'vendor/bin/agent-loop workflow learn DEMO-1', $actionOutputs['learn']),
                    ],
                ],
                [
                    'id' => 'workflow.close',
                    'commands' => [
                        $this->command($workspace, 'verify', 'vendor/bin/agent-loop verify --task-id=DEMO-1', $actionOutputs['verify']),
                        $this->command($workspace, 'finish', 'vendor/bin/agent-loop finish DEMO-1', $actionOutputs['finish']),
                        $this->command($workspace, 'status-complete', 'vendor/bin/agent-loop workflow status DEMO-1 --format=json', $status),
                    ],
                ],
            ],
            'front_door_journey' => [
                'repeated_same_state_commands' => 1,
                'context_lines' => 12,
                'context_bytes' => 345,
            ],
        ];

        $measurement = (new ProportionalGovernanceMeasurement($workspace))->measure($report);

        self::assertSame('1.0', $measurement['schema_version']);
        self::assertSame('ordinary_installed_consumer', $measurement['scope']);
        self::assertSame(4, $measurement['lifecycle_commands']);
        self::assertSame(4, $measurement['specialist_commands']);
        self::assertSame(0, $measurement['manual_preparation_commands']);
        self::assertSame(0, $measurement['manual_repair_commands']);
        self::assertSame(1, $measurement['repeated_same_state_calls']);
        self::assertSame(12, $measurement['context_lines']);
        self::assertSame(345, $measurement['context_bytes']);
        self::assertSame(array_sum(array_map('strlen', $actionOutputs)), $measurement['workflow_output_bytes']);
        self::assertSame(strlen($validationOutput), $measurement['validation_output_bytes']);
        self::assertSame([
            'map' => true,
            'recall' => true,
            'session' => true,
            'execution_contract' => false,
            'review' => true,
            'learning' => true,
            'verification' => true,
        ], $measurement['capability_activation']);
    }

    public function testUnobservedCapabilityStaysUnknown(): void
    {
        $workspace = $this->temporaryWorkspace();
        $report = [
            'scenarios' => [
                [
                    'id' => 'workflow.plan',
                    'commands' => [
                        $this->command($workspace, 'plan', 'vendor/bin/agent-loop workflow plan DEMO-1', "planned\n"),
                    ],
                ],
            ],
            'front_door_journey' => [
                'repeated_same_state_commands' => 0,
                'context_lines' => 0,
                'context_bytes' => 0,
            ],
        ];

        $measurement = (new ProportionalGovernanceMeasurement($workspace))->measure($report);

        self::assertSame([
            'map' => null,
            'recall' => null,
            'session' => null,
            'execution_contract' => null,
            'review' => null,
            'learning' => null,
            'verification' => null,
        ], $measurement['capability_activation']);
    }

    public function testRejectsLogTraversalInsteadOfReadingOutsideWorkspace(): void
    {
        $workspace = $this->temporaryWorkspace();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe measurement log path');

        (new ProportionalGovernanceMeasurement($workspace))->measure([
            'scenarios' => [
                [
                    'id' => 'workflow.plan',
                    'commands' => [[
                        'display' => 'vendor/bin/agent-loop workflow plan DEMO-1',
                        'stdout_log' => '../outside.log',
                        'stderr_log' => 'logs/safe.stderr.log',
                    ]],
                ],
            ],
            'front_door_journey' => [
                'repeated_same_state_commands' => 0,
                'context_lines' => 0,
                'context_bytes' => 0,
            ],
        ]);
    }

    /** @return array{display: non-empty-string, stdout_log: non-empty-string, stderr_log: non-empty-string} */
    private function command(string $workspace, string $name, string $display, string $stdout): array
    {
        $directory = $workspace . '/logs';
        if (!is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0777, true));
        }
        $stdoutRelative = 'logs/' . $name . '.stdout.log';
        $stderrRelative = 'logs/' . $name . '.stderr.log';
        self::assertNotFalse(file_put_contents($workspace . '/' . $stdoutRelative, $stdout));
        self::assertNotFalse(file_put_contents($workspace . '/' . $stderrRelative, ''));

        return [
            'display' => $display,
            'stdout_log' => $stdoutRelative,
            'stderr_log' => $stderrRelative,
        ];
    }

    private function temporaryWorkspace(): string
    {
        $workspace = sys_get_temp_dir() . '/agent-loop-measurement-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($workspace, 0777, true));

        return $workspace;
    }
}
