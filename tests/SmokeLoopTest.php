<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Dispatcher;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\WorkflowReviewReportReader;

/** End-to-end proof that the installed package boundaries cooperate. */
final class SmokeLoopTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-smoke-' . bin2hex(random_bytes(6));
        $this->copyDirectory(__DIR__ . '/fixtures/basic-loop', $this->root);
        mkdir($this->root . '/.agent-loop/tasks', 0o775, true);
        rename($this->root . '/tasks/task.001.md', $this->root . '/.agent-loop/tasks/task.001.md');
        rmdir($this->root . '/tasks');
        file_put_contents(
            $this->root . '/.agent-loop/init.json',
            json_encode(['version' => 1, 'paths' => ['learning_root' => 'learning-root']], JSON_THROW_ON_ERROR),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testStandaloneSessionRecallAndLearningPackagesReportNoDrift(): void
    {
        self::assertSame(0, $this->dispatch(['agent-loop', 'session', 'start', '--task', 'task.001', '--by', 'tester'])['exit']);

        self::assertSame(0, $this->dispatch([
            'agent-loop', 'recall', 'compile',
            '--root', $this->root . '/learning-root',
            '--task', 'task.001',
            '--file', 'src/Signup.php',
        ])['exit']);

        self::assertSame(0, $this->dispatch(['agent-loop', 'learn', 'validate', '--root', $this->root . '/learning-root'])['exit']);

        $result = $this->dispatch([
            'agent-loop', 'verify',
            '--learning-root=' . $this->root . '/learning-root',
        ]);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('[OK] tasks: 1 task file(s) parsed: task.001', $result['output']);
        self::assertStringContainsString('[SKIP] board: no typed board source', $result['output']);
        self::assertStringContainsString('[OK] sessions: 1 session(s) parsed, 1 active and consistent', $result['output']);
        self::assertStringContainsString('[OK] learning root: validated', $result['output']);
        self::assertStringContainsString('[OK] agent-loop verify: no drift detected.', $result['output']);
    }

    public function testVerifyFailsWhenActiveSessionHasNoRecallBriefing(): void
    {
        self::assertSame(0, $this->dispatch(['agent-loop', 'session', 'start', '--task', 'task.001', '--by', 'tester'])['exit']);

        $result = $this->dispatch(['agent-loop', 'verify']);

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('[FAIL] recall: active session', $result['output']);
        self::assertStringContainsString('has no compiled briefing', $result['output']);
    }

    public function testVerifyFailsWhenRecallOutputIsTamperedWith(): void
    {
        self::assertSame(0, $this->dispatch(['agent-loop', 'session', 'start', '--task', 'task.001', '--by', 'tester'])['exit']);

        self::assertSame(0, $this->dispatch([
            'agent-loop', 'recall', 'compile',
            '--root', $this->root . '/learning-root',
            '--task', 'task.001',
            '--file', 'src/Signup.php',
        ])['exit']);

        file_put_contents($this->root . '/.agent-loop/recall/task.001/system.md', "tampered\n", FILE_APPEND);

        $result = $this->dispatch(['agent-loop', 'verify']);

        self::assertSame(1, $result['exit'], $result['output']);
        // Recall owns its integrity manifest and reports the failure itself, so
        // this asserts the guarantee - the tampered file is named as stale -
        // rather than the owner's wording or its private metadata filename.
        self::assertStringContainsString('[FAIL] recall:', $result['output']);
        self::assertStringContainsString('stale', $result['output']);
        self::assertStringContainsString('system.md', $result['output']);
    }

    public function testVerifyRejectsRecordedOutputPathsThatEscapeTheRecallDirectory(): void
    {
        // The Recall integrity check only runs once Sessions exist.
        self::assertSame(0, $this->dispatch(['agent-loop', 'session', 'start', '--task', 'task.001', '--by', 'tester'])['exit']);

        $outside = $this->root . '/outside';
        mkdir($outside, 0o775, true);
        file_put_contents($outside . '/canary.txt', "canary\n");
        $canary = hash_file('sha256', $outside . '/canary.txt');
        self::assertIsString($canary);

        // QUARANTINED ADVERSARIAL FIXTURE - do not copy this style.
        //
        // Consumer tests should compile real Recall output and corrupt one
        // boundary field, so they cannot pass against documents the owner would
        // never emit. That pattern cannot express this case: the corruption
        // under test is a recorded path escaping the output directory, and
        // Recall does not emit one, so there is no canonical document to
        // corrupt into this state. Hand-authoring the metadata is the only way
        // to reach it, which is exactly why it stays confined to this test.
        //
        // The recorded digest matches, so a verifier that resolves the path
        // without checking it reads a file outside the output directory and
        // reports success for it.
        mkdir($this->root . '/.agent-loop/recall/task.001', 0o775, true);
        file_put_contents(
            $this->root . '/.agent-loop/recall/task.001/meta.json',
            json_encode([
                'task_id' => 'task.001',
                'output_hashes' => ['../../../outside/canary.txt' => $canary],
            ], JSON_THROW_ON_ERROR),
        );

        $result = $this->dispatch(['agent-loop', 'verify']);

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('unsafe relative path', $result['output']);
        self::assertStringContainsString('../../../outside/canary.txt', $result['output']);
    }

    public function testGovernedCompletionFlowUsesEnterAndFinishInsteadOfManualOwnerChoreography(): void
    {
        self::assertSame(0, $this->dispatch([
            'agent-loop', 'workflow', 'plan', 'task.001', '--by', 'tester',
            '--file', 'src/Signup.php',
            '--goal', 'Keep completion evidence auditable.',
            '--validation', 'php -l src/Signup.php',
        ])['exit']);
        self::assertSame(0, $this->dispatch([
            'agent-loop', 'workflow', 'approve', 'task.001', '--by', 'tester',
        ])['exit']);

        $enter = $this->dispatch(['agent-loop', 'enter', 'task.001', '--format=json']);
        self::assertSame(0, $enter['exit'], $enter['output']);
        $enterPayload = $this->json($enter['output']);
        self::assertTrue($enterPayload['mutation_ready'] ?? false);
        self::assertSame('compiled', $enterPayload['manifest']['references']['recall']['state'] ?? null);

        // Host-native implementation remains outside the lifecycle engine.
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Signup.php', "<?php\nfinal class Signup {}\n");

        $firstFinish = $this->dispatch(['agent-loop', 'finish', 'task.001', '--format=json']);
        self::assertSame(1, $firstFinish['exit'], $firstFinish['output']);
        $firstPayload = $this->json($firstFinish['output']);
        self::assertFalse($firstPayload['complete'] ?? true);
        self::assertSame('blocked', $firstPayload['manifest']['references']['verification']['state'] ?? null);
        self::assertSame('review', $firstPayload['manifest']['references']['verification']['gate'] ?? null);
        self::assertSame('unacknowledged', $firstPayload['manifest']['references']['review']['state'] ?? null);

        $review = (new WorkflowReviewReportReader($this->root))->read('task.001');
        self::assertSame('unacknowledged', $review['status']);
        self::assertNotNull($review['sha256']);
        self::assertSame(
            'agent-loop finish task.001 --reviewed-report-sha256 ' . $review['sha256'] . ' --by <actor>',
            $firstPayload['next_action'] ?? null,
        );

        $complete = $this->dispatch([
            'agent-loop', 'finish', 'task.001', '--format=json',
            '--reviewed-report-sha256', $review['sha256'],
            '--learning', 'no_durable_learning',
            '--learning-reason', 'The smoke run produced no reusable guidance.',
            '--by', 'tester',
        ]);
        self::assertSame(0, $complete['exit'], $complete['output']);
        $completePayload = $this->json($complete['output']);
        self::assertTrue($completePayload['complete'] ?? false);
        self::assertSame('complete', $completePayload['manifest']['state'] ?? null);
        self::assertSame('none', $completePayload['next_action'] ?? null);
        self::assertFileExists($this->root . '/.agent-loop/runs/task.001/verification.json');

        $report = $this->dispatch(['agent-loop', 'workflow', 'report', 'task.001']);
        self::assertSame(0, $report['exit']);
        self::assertStringContainsString('[passed] php -l src/Signup.php via session', $report['output']);
        self::assertStringContainsString('Run decision no_durable_learning', $report['output']);
    }

    /**
     * @param list<string> $argv
     * @return array{exit: int, output: string}
     */
    private function dispatch(array $argv): array
    {
        $dispatcher = new Dispatcher($this->root);

        ob_start();
        try {
            $frontDoor = $argv[1] ?? null;
            if (in_array($frontDoor, ['enter', 'finish'], true)) {
                $scriptName = $argv[0] ?? 'agent-loop';
                $exit = (new HostFrontDoorCommand(
                    $this->root,
                    static fn (array $recallRest): int => $dispatcher->run([
                        $scriptName,
                        'recall',
                        ...$recallRest,
                    ]),
                ))->run($frontDoor, array_slice($argv, 2));
            } else {
                $exit = $dispatcher->run($argv);
            }
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        return ['exit' => $exit, 'output' => $output];
    }

    /** @return array<string, mixed> */
    private function json(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function copyDirectory(string $source, string $destination): void
    {
        mkdir($destination, 0o775, true);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $target = $destination . '/' . $iterator->getSubPathName();
            if ($item->isDir()) {
                mkdir($target, 0o775, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
