<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\ImplementationSnapshot;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStore;
use voku\AgentSession\ValidationEvidenceStore;
use voku\AgentSession\ValidationStatus;

final class WorkflowFinishValidationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-finish-validation-' . bin2hex(random_bytes(5));
        if (!mkdir($this->root . '/src', 0o775, true) && !is_dir($this->root . '/src')) {
            throw new RuntimeException('Unable to create source fixture directory.');
        }
        if (!mkdir($this->root . '/.agent-loop/learning', 0o775, true) && !is_dir($this->root . '/.agent-loop/learning')) {
            throw new RuntimeException('Unable to create learning fixture directory.');
        }
        file_put_contents($this->root . '/src/Foo.php', "<?php\nreturn 'current';\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testFinishExecutesDeclaredValidationOnceForCurrentImplementation(): void
    {
        [$command, $session] = $this->prepareRun('FINISH-1', 'php -r "exit(0);"');

        $first = $this->finish('FINISH-1');

        self::assertSame(1, $first['exit']);
        self::assertFalse($first['payload']['complete'] ?? true);
        self::assertStringContainsString('--reviewed-report-sha256', (string) ($first['payload']['next_action'] ?? ''));

        $evidence = (new ValidationEvidenceStore())->all($session);
        self::assertCount(1, $evidence);
        self::assertSame($command, $evidence[0]->command);
        self::assertSame(ValidationStatus::PASSED, $evidence[0]->status);
        self::assertSame(0, $evidence[0]->exitCode);
        self::assertSame(
            ImplementationSnapshot::capture($this->root, (new TaskContractStore($this->root))->load('FINISH-1'))->digest,
            $evidence[0]->implementationSnapshot,
        );

        $second = $this->finish('FINISH-1');

        self::assertSame(1, $second['exit']);
        self::assertStringContainsString('--reviewed-report-sha256', (string) ($second['payload']['next_action'] ?? ''));
        self::assertCount(1, (new ValidationEvidenceStore())->all($session), 'Current passing evidence must be reused, not duplicated.');
    }

    public function testFinishRecordsObservedValidationFailureWithoutInventingSuccess(): void
    {
        [$command, $session] = $this->prepareRun('FINISH-2', 'php -r "exit(7);"');

        $result = $this->finish('FINISH-2');

        self::assertSame(1, $result['exit']);
        self::assertFalse($result['payload']['complete'] ?? true);
        // finish must not name itself after observing its own failure.
        self::assertSame('host_work', $result['payload']['next_action_kind'] ?? null);
        self::assertStringContainsString(
            'change the implementation',
            (string) ($result['payload']['next_action'] ?? ''),
        );
        self::assertSame(
            $command,
            $result['payload']['manifest']['references']['verification']['action'] ?? null,
            'The observed failing validation command must stay visible in the manifest.',
        );

        $evidence = (new ValidationEvidenceStore())->all($session);
        self::assertCount(1, $evidence);
        self::assertSame(ValidationStatus::FAILED, $evidence[0]->status);
        self::assertSame(7, $evidence[0]->exitCode);
        self::assertNotNull($evidence[0]->implementationSnapshot);
    }

    public function testFinishHandlesLargeStderrWithoutDeadlock(): void
    {
        // 128KB of stderr before exiting; would deadlock sequential reads
        [$command, $session] = $this->prepareRun('FINISH-LARGE-ERR', 'php -r "fwrite(STDERR, str_repeat(\"x\", 131072)); exit(0);"');

        $result = $this->finish('FINISH-LARGE-ERR');

        self::assertSame(1, $result['exit']);
        $evidence = (new ValidationEvidenceStore())->all($session);
        self::assertCount(1, $evidence);
        self::assertSame(ValidationStatus::PASSED, $evidence[0]->status);
        self::assertSame(0, $evidence[0]->exitCode);
    }

    /** @return array{0: string, 1: Session} */
    private function prepareRun(string $taskId, string $validation): array
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            $taskId,
            'Let finish own deterministic validation evidence.',
            ['src/Foo.php'],
            [],
            [$validation],
            'fixture-planner',
        );
        $contract = $contracts->approve($taskId, 'fixture-approver');
        $sessions = new SessionStore();
        $session = $sessions->create($this->root . '/.agent-loop/sessions', $taskId, by: 'fixture-agent');
        (new GovernedRunStore($this->root))->prepare($contract, $session, $this->root . '/.agent-loop/learning');

        $recallDirectory = $this->root . '/.agent-loop/recall/' . $taskId;
        if (!mkdir($recallDirectory, 0o775, true) && !is_dir($recallDirectory)) {
            throw new RuntimeException('Unable to create Recall fixture directory.');
        }
        file_put_contents(
            $recallDirectory . '/meta.json',
            json_encode([
                'schema_version' => '1.0',
                'task_id' => $taskId,
                'compilation_id' => strtolower($taskId) . '-fixture',
                'bundle_sha256' => str_repeat('a', 64),
                'selected_guidance' => [],
                'selected_constraints' => [],
                'output_hashes' => [],
            ], JSON_THROW_ON_ERROR),
        );
        file_put_contents($recallDirectory . '/validation-plan.md', "# Validation\n\nRun the approved commands.\n");

        return [$validation, $session];
    }

    /** @return array{exit: int, payload: array<string, mixed>} */
    private function finish(string $taskId): array
    {
        ob_start();
        try {
            $exit = (new HostFrontDoorCommand($this->root))->run('finish', [$taskId, '--format=json']);
            $stdout = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        if (!is_string($stdout)) {
            throw new RuntimeException('Unable to capture finish JSON.');
        }
        $payload = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('Finish JSON did not decode to an object.');
        }

        return ['exit' => $exit, 'payload' => $payload];
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
