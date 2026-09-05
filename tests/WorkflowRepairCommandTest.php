<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\ValidationDiagnostic;
use voku\AgentLoop\Workflow\ValidationDiagnosticStore;
use voku\AgentLoop\Workflow\WorkflowRepairCommand;

final class WorkflowRepairCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-repair-test-' . bin2hex(random_bytes(4));
        if (!mkdir($this->root, 0o775, true) && !is_dir($this->root)) {
            throw new RuntimeException('Unable to create test root: ' . $this->root);
        }
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testRepairWithNoFailuresReportsNoRepairNeeded(): void
    {
        $command = new WorkflowRepairCommand($this->root);
        $level = ob_get_level();
        ob_start();
        try {
            $exit = $command->run(['TASK-100', '--format=json']);
            $output = ob_get_clean();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        self::assertSame(0, $exit);
        $payload = json_decode((string) $output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('no_repair_needed', $payload['status']);
    }

    public function testRepairCapturesFailingOutputAndParsesDiagnostics(): void
    {
        $store = new ValidationDiagnosticStore($this->root);
        $output = <<<PHPSTAN
Line   src/UserGateway.php
------ -----------------------------------------------------------------------
42     Parameter #1 \$id of method find() expects int, string given.
PHPSTAN;

        $diagnostic = ValidationDiagnostic::fromExecution(
            'TASK-101',
            1,
            'php vendor/bin/phpstan analyse src/UserGateway.php',
            1,
            $output,
        );
        $store->record($diagnostic);

        $command = new WorkflowRepairCommand($this->root);
        $level = ob_get_level();
        ob_start();
        try {
            $exit = $command->run(['TASK-101', '--format=json']);
            $rawJson = ob_get_clean();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        self::assertSame(0, $exit);
        $payload = json_decode((string) $rawJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ready', $payload['status']);
        self::assertSame(1, $payload['attempt']);
        self::assertSame(2, $payload['max_attempts']);
        self::assertSame('phpstan', $payload['tool_category']);
        self::assertCount(1, $payload['errors']);
        self::assertSame('src/UserGateway.php', $payload['errors'][0]['file']);
        self::assertSame(42, $payload['errors'][0]['line']);
        self::assertStringContainsString('expects int, string given', $payload['errors'][0]['message']);
        self::assertStringContainsString('src/UserGateway.php:42', $payload['repair_instruction']);
    }

    public function testRepairEnforcesMaxAttemptsAndExhausts(): void
    {
        $store = new ValidationDiagnosticStore($this->root);
        $diagnostic = ValidationDiagnostic::fromExecution(
            'TASK-102',
            1,
            'php -l src/Broken.php',
            255,
            "PHP Parse error:  syntax error, unexpected token ';' in src/Broken.php on line 15\n",
        );
        $store->record($diagnostic);

        $command = new WorkflowRepairCommand($this->root);

        // Attempt 1
        $exit1 = $this->captureRun($command, ['TASK-102', '--format=json']);
        self::assertSame(0, $exit1['exit']);
        $payload1 = json_decode($exit1['stdout'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $payload1['attempt']);
        self::assertSame('ready', $payload1['status']);

        // Attempt 2
        $exit2 = $this->captureRun($command, ['TASK-102', '--format=json']);
        self::assertSame(0, $exit2['exit']);
        $payload2 = json_decode($exit2['stdout'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $payload2['attempt']);
        self::assertSame('ready', $payload2['status']);

        // Attempt 3: Budget exhausted!
        $exit3 = $this->captureRun($command, ['TASK-102', '--format=json']);
        self::assertSame(1, $exit3['exit']);
        $payload3 = json_decode($exit3['stdout'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('exhausted', $payload3['status']);
        self::assertSame('decision_required', $payload3['next_action_kind']);
        self::assertStringContainsString('Bounded repair limit reached', $payload3['message']);
    }

    public function testRepairClearsWhenValidationPasses(): void
    {
        $store = new ValidationDiagnosticStore($this->root);
        $diagnostic = ValidationDiagnostic::fromExecution(
            'TASK-103',
            1,
            'phpunit',
            1,
            "FAILURES!\n",
        );
        $store->record($diagnostic);
        self::assertNotNull($store->latest('TASK-103'));

        $store->clear('TASK-103');
        self::assertNull($store->latest('TASK-103'));
    }

    public function testManifestProjectsRepairActionOnValidationFailure(): void
    {
        $store = new ValidationDiagnosticStore($this->root);
        $diagnostic = ValidationDiagnostic::fromExecution(
            'TASK-104',
            1,
            'php -l src/Broken.php',
            255,
            "PHP Parse error: syntax error in src/Broken.php on line 1\n",
        );
        $store->record($diagnostic);

        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'TASK-104',
            'Fix parse error',
            ['src/Broken.php'],
            [],
            ['php -l src/Broken.php'],
            'tester',
        );
        $contracts->approve('TASK-104', 'tester');

        mkdir($this->root . '/src', 0777, true);
        file_put_contents($this->root . '/src/Broken.php', "<?php echo 1;\n");

        // Record failed evidence so readiness sees validation failed
        $sessionsRoot = (new \voku\AgentLoop\ProjectLayout($this->root))->sessionsRoot();
        $session = (new \voku\AgentSession\SessionStore())->create($sessionsRoot, 'TASK-104', by: 'tester');
        $run = (new \voku\AgentLoop\Run\GovernedRunStore($this->root))->prepare($contracts->load('TASK-104'), $session, $this->root . '/.agent-loop/learning');
        $snapshot = \voku\AgentLoop\Workflow\ImplementationSnapshot::capture($this->root, $contracts->load('TASK-104'));
        (new \voku\AgentSession\ValidationEvidenceStore())->record(
            $session,
            $contracts->load('TASK-104')->revision,
            'php -l src/Broken.php',
            \voku\AgentSession\ValidationStatus::FAILED,
            255,
            10,
            'tester',
            implementationSnapshot: $snapshot->digest,
        );

        $manifest = (new \voku\AgentLoop\Run\RunManifestProjector($this->root))->project('TASK-104');
        self::assertSame('agent-loop repair TASK-104', $manifest->references['verification']['repair_action'] ?? null);

        // Exhaust repair attempts
        $store->incrementRepairAttempt('TASK-104', 1);
        $store->incrementRepairAttempt('TASK-104', 1);

        $manifestExhausted = (new \voku\AgentLoop\Run\RunManifestProjector($this->root))->project('TASK-104');
        self::assertNull($manifestExhausted->references['verification']['repair_action'] ?? null);
    }

    /**
     * @param list<string> $args
     * @return array{exit: int, stdout: string}
     */
    private function captureRun(WorkflowRepairCommand $command, array $args): array
    {
        $level = ob_get_level();
        ob_start();
        try {
            $exit = $command->run($args);
            $stdout = (string) ob_get_clean();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return ['exit' => $exit, 'stdout' => $stdout];
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
