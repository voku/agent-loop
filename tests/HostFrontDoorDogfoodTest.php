<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HostFrontDoorDogfoodTest extends TestCase
{
    private string $reportRoot;

    protected function setUp(): void
    {
        $this->reportRoot = sys_get_temp_dir() . '/agent-loop-host-front-door-report-' . bin2hex(random_bytes(6));
        if (!mkdir($this->reportRoot, 0o775, true) && !is_dir($this->reportRoot)) {
            throw new RuntimeException('Unable to create dogfood report root: ' . $this->reportRoot);
        }
    }

    protected function tearDown(): void
    {
        $report = $this->reportRoot . '/report.json';
        if (is_file($report) && !unlink($report)) {
            throw new RuntimeException('Unable to remove dogfood report: ' . $report);
        }
        if (is_dir($this->reportRoot) && !rmdir($this->reportRoot)) {
            throw new RuntimeException('Unable to remove dogfood report root: ' . $this->reportRoot);
        }
    }

    public function testRealDogfoodJourneyProducesCompactFrictionEvidenceWithoutWorkspaceLeaks(): void
    {
        $beforeWorkspaces = $this->dogfoodWorkspaces();
        $reportPath = $this->reportRoot . '/report.json';

        $result = $this->runDogfood($reportPath);

        self::assertSame(0, $result['exit'], $result['stderr'] . "\n" . $result['stdout']);
        self::assertSame('', $result['stderr']);
        self::assertFileExists($reportPath);

        $stdoutReport = $this->json($result['stdout']);
        $fileReport = $this->json((string) file_get_contents($reportPath));
        self::assertSame($stdoutReport, $fileReport, 'stdout and retained evidence must describe the same journey.');

        self::assertSame('1.0', $fileReport['schema_version']);
        self::assertSame('HOST-FRONT-DOOR', $fileReport['task_id']);
        self::assertSame('enter -> host-native work -> finish', $fileReport['journey']);
        self::assertSame(4, $fileReport['front_door_commands']);
        self::assertSame(2, $fileReport['failed_front_door_commands']);
        self::assertSame(0, $fileReport['repeated_same_state_commands']);
        self::assertSame(0, $fileReport['manual_corrections']);
        self::assertSame(0, $fileReport['hidden_prerequisite_repairs']);
        self::assertSame([false, true], $fileReport['mutation_ready_transition']);
        self::assertTrue($fileReport['premature_done_caught']);
        self::assertTrue($fileReport['final_complete']);

        self::assertIsInt($fileReport['context_lines']);
        self::assertGreaterThan(0, $fileReport['context_lines']);
        self::assertLessThanOrEqual(40, $fileReport['context_lines']);
        self::assertIsInt($fileReport['context_bytes']);
        self::assertGreaterThan(0, $fileReport['context_bytes']);
        self::assertLessThanOrEqual(4096, $fileReport['context_bytes']);

        $commands = $fileReport['commands'];
        self::assertIsArray($commands);
        self::assertCount(4, $commands);

        self::assertCommand($commands[0] ?? null, 'enter', 'unprepared', 1, false, null);
        self::assertCommand($commands[1] ?? null, 'enter', 'governed_ready', 0, true, null);
        self::assertCommand($commands[2] ?? null, 'finish', 'ready_to_close', 1, null, false);
        self::assertCommand($commands[3] ?? null, 'finish', 'complete', 0, null, true);

        self::assertIsArray($commands[0]);
        self::assertStringContainsString('workflow plan HOST-FRONT-DOOR', (string) ($commands[0]['next_action'] ?? ''));
        self::assertIsArray($commands[2]);
        self::assertSame('agent-loop workflow close HOST-FRONT-DOOR --status done', $commands[2]['next_action'] ?? null);
        self::assertIsArray($commands[3]);
        self::assertSame('none', $commands[3]['next_action'] ?? null);

        self::assertSame($beforeWorkspaces, $this->dogfoodWorkspaces(), 'dogfood must remove its isolated workflow workspace.');
    }

    /** @return array{exit: int, stdout: string, stderr: string} */
    private function runDogfood(string $reportPath): array
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/tools/host-front-door-dogfood.php', '--report=' . $reportPath],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__),
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start host front-door dogfood.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    /** @return array<string, mixed> */
    private function json(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private static function assertCommand(
        mixed $command,
        string $expectedCommand,
        string $expectedPhase,
        int $expectedExit,
        ?bool $expectedMutationReady,
        ?bool $expectedComplete,
    ): void {
        self::assertIsArray($command);
        self::assertSame($expectedCommand, $command['command'] ?? null);
        self::assertSame($expectedPhase, $command['phase'] ?? null);
        self::assertSame($expectedExit, $command['exit'] ?? null);
        if ($expectedMutationReady !== null) {
            self::assertSame($expectedMutationReady, $command['mutation_ready'] ?? null);
        }
        if ($expectedComplete !== null) {
            self::assertSame($expectedComplete, $command['complete'] ?? null);
        }
    }

    /** @return list<string> */
    private function dogfoodWorkspaces(): array
    {
        $matches = glob(sys_get_temp_dir() . '/agent-loop-host-front-door-dogfood-*', GLOB_ONLYDIR);
        if ($matches === false) {
            throw new RuntimeException('Unable to inspect host front-door dogfood workspaces.');
        }
        sort($matches, SORT_STRING);

        return array_values($matches);
    }
}
