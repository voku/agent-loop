<?php

declare(strict_types=1);

use voku\AgentLoop\Dogfood\MinimumReleasePin;

// The class file, not the autoloader: a candidate workflow checks this
// repository out and runs PHP against it without ever installing agent-loop's
// own dependencies, because what it exercises is a separately installed
// consumer. MinimumReleasePin depends on nothing but PHP, so it loads directly.
require dirname(__DIR__) . '/src/Dogfood/MinimumReleasePin.php';

final class PromptPrimitivesDogfoodFailure extends RuntimeException
{
}

final class PromptPrimitivesDogfood
{
    private const string TASK_ID = 'PROMPT-PRIMITIVES';
    private const string PLANNER = 'prompt-primitives-dogfood';
    private const string APPROVER = 'ci-approval-fixture';
    private const string VALIDATION = 'php -r "exit(0);"';

    private string $consumerRoot;
    private string $reportPath;
    private int $commandNumber = 0;

    /** @var list<array{command: string, exit_code: int, stdout_sha256: string, stderr_sha256: string}> */
    private array $commands = [];

    public function __construct(
        private readonly string $agentLoopRoot,
        private readonly string $recallRoot,
        string $workspace,
        string $reportPath,
    ) {
        $this->consumerRoot = rtrim($workspace, '/\\') . '/consumer';
        $this->reportPath = $reportPath;
    }

    public function run(): int
    {
        $this->removeTree(dirname($this->consumerRoot));
        $this->mkdir($this->consumerRoot);
        $this->writeConsumerComposer();

        try {
            $this->runCommand(['composer', 'update', '--no-interaction', '--prefer-dist', '--no-progress', '--no-ansi']);
            $this->assertFile('vendor/voku/agent-loop/resources/operating-prompts.json');
            $this->runCommand(['vendor/bin/agent-loop', 'init', 'scaffold']);
            $this->plan();
            $this->runCommand([
                'vendor/bin/agent-loop', 'workflow', 'approve', self::TASK_ID,
                '--by', self::APPROVER,
            ]);
            $entered = $this->runCommand([
                'vendor/bin/agent-loop', 'enter', self::TASK_ID, '--format=json',
            ]);
            $entry = $this->json($entered['stdout'], 'agent-loop enter');
            if (($entry['mutation_ready'] ?? null) !== true) {
                throw new PromptPrimitivesDogfoodFailure('Approved L1-only consumer did not become mutation-ready through enter.');
            }
            $this->assertCompiledControls();
            $this->validate();
            $this->review();
            $this->finalizePromptOutcomes();
            $this->runCommand([
                'vendor/bin/agent-loop', 'recall', 'log-outcome',
                '--root', '.agent-loop/learning',
                '--draft', '.agent-loop/recall/' . self::TASK_ID . '/recall-log.draft.json',
                '--by', self::PLANNER,
                '--commit', 'candidate-dogfood',
            ]);
            $this->runCommand([
                'vendor/bin/agent-loop', 'workflow', 'learn', self::TASK_ID,
                '--status', 'no_durable_learning',
                '--by', self::PLANNER,
                '--reason', 'The candidate dogfood exercised existing prompt-control semantics without producing a new durable rule.',
            ]);
            $this->runCommand(['vendor/bin/agent-loop', 'verify', '--task-id=' . self::TASK_ID]);

            // The deterministic report already exists from review(), but
            // acknowledging it is an authority-bearing decision. `finish` will
            // not reopen ready_to_close as an observable status any more: a
            // single acknowledge-and-close call now carries the Run straight
            // from incomplete to complete.
            $reviewDigest = $this->status()['manifest']['references']['review']['source']['sha256'] ?? null;
            if (!is_string($reviewDigest) || !str_starts_with($reviewDigest, 'sha256:')) {
                throw new PromptPrimitivesDogfoodFailure('Verified Run did not expose an exact review-report identity.');
            }
            $this->runCommand([
                'vendor/bin/agent-loop', 'finish', self::TASK_ID,
                '--reviewed-report-sha256', $reviewDigest,
                '--by', self::PLANNER,
            ]);
            $this->assertState('complete');

            $projectReflection = $this->runCommand([
                'vendor/bin/agent-loop', 'workflow', 'reflect', self::TASK_ID, '--scope', 'project',
            ]);
            $this->assertContains($projectReflection['stdout'], 'future work in this project meaningfully better', 'project reflection');

            $taskReflection = $this->runCommand([
                'vendor/bin/agent-loop', 'workflow', 'reflect', self::TASK_ID, '--scope', 'task',
            ]);
            $this->assertContains($taskReflection['stdout'], 'RETURN_TO_REVIEW', 'task reflection');

            $this->writeReport('passed', null, $projectReflection['stdout'], $taskReflection['stdout']);
            echo "Prompt-primitives dogfood: PASSED\n";

            return 0;
        } catch (Throwable $exception) {
            $this->writeReport('failed', $exception->getMessage(), null, null);
            fwrite(STDERR, 'Prompt-primitives dogfood: FAILED: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    private function plan(): void
    {
        $checkpoint = json_encode([
            'id' => 'checkpoint-autonomy',
            'arguments' => ['anchor_point' => 'each independently verifiable governed phase'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $momentum = json_encode([
            'id' => 'momentum',
            'arguments' => new stdClass(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->runCommand([
            'vendor/bin/agent-loop', 'workflow', 'plan', self::TASK_ID,
            '--by', self::PLANNER,
            '--file', 'composer.json',
            '--goal', 'Prove checkpoint autonomy, momentum, and post-task reflection through one installed governed workflow.',
            '--non-goal', 'Do not create a new lifecycle phase, synthetic human approval, or automatic follow-up task.',
            '--behavior-anchor', 'explicit L1 controls -> governed run -> normal close gates -> optional read-only reflection',
            '--validation', self::VALIDATION,
            '--operating-prompt-manifest', 'vendor/voku/agent-loop/resources/operating-prompts.json',
            '--operating-prompt', $checkpoint,
            '--operating-prompt', $momentum,
        ]);
    }

    private function assertCompiledControls(): void
    {
        $systemPath = '.agent-loop/recall/' . self::TASK_ID . '/system.md';
        $this->assertFile($systemPath);
        $system = (string) file_get_contents($this->consumerRoot . '/' . $systemPath);
        $this->assertContains($system, '### checkpoint-autonomy (L1)', 'compiled checkpoint autonomy');
        $this->assertContains($system, 'each independently verifiable governed phase', 'checkpoint anchor');
        $this->assertContains($system, '### momentum (L1)', 'compiled momentum');
        $this->assertContains($system, 'Do not restart discovery from scratch', 'momentum semantics');
        if (str_contains($system, '## L2 Operational Prompt Construction')) {
            throw new PromptPrimitivesDogfoodFailure('L1-only controls unexpectedly created an L2 construction section.');
        }

        $status = $this->status();
        $executionContract = $status['manifest']['references']['execution_contract']['state'] ?? null;
        if ($executionContract !== 'not_required') {
            throw new PromptPrimitivesDogfoodFailure(sprintf(
                'L1-only controls unexpectedly require an execution contract; state=%s.',
                is_scalar($executionContract) ? (string) $executionContract : get_debug_type($executionContract),
            ));
        }
    }

    private function validate(): void
    {
        $this->runCommand([PHP_BINARY, '-r', 'exit(0);']);
        $this->runCommand([
            'vendor/bin/agent-loop', 'session', 'validation', 'record', self::TASK_ID,
            '--contract-revision', '1',
            '--command', self::VALIDATION,
            '--status', 'passed',
            '--exit-code', '0',
            '--duration-ms', '0',
            '--by', self::PLANNER,
        ]);
    }

    private function review(): void
    {
        $this->runCommand(['vendor/bin/agent-loop', 'review', 'blindspots', self::TASK_ID], [0, 1]);
        $this->runCommand([
            'vendor/bin/agent-loop', 'session', 'checkpoint', self::TASK_ID,
            '--title', 'Prompt primitive checkpoint',
            '--body', 'The deterministic blind-spot report and current workflow evidence were inspected; no human-only gate is required.',
        ]);
        $this->runCommand(['vendor/bin/agent-loop', 'review', 'blindspots', self::TASK_ID]);
    }

    private function finalizePromptOutcomes(): void
    {
        $path = $this->consumerRoot . '/.agent-loop/recall/' . self::TASK_ID . '/recall-log.draft.json';
        $draft = $this->jsonFile($path);
        $rows = $draft['operating_prompt_outcomes'] ?? null;
        if (!is_array($rows) || count($rows) !== 2) {
            throw new PromptPrimitivesDogfoodFailure('Expected two operating-prompt outcome rows.');
        }

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new PromptPrimitivesDogfoodFailure('Operating-prompt outcome row is invalid.');
            }
            $promptId = $row['prompt_id'] ?? null;
            if (!is_string($promptId) || !in_array($promptId, ['checkpoint-autonomy', 'momentum'], true)) {
                throw new PromptPrimitivesDogfoodFailure('Unexpected operating-prompt outcome id.');
            }
            $row['applied'] = true;
            $row['outcome'] = 'helpful';
            $row['evidence'] = [
                $promptId === 'checkpoint-autonomy'
                    ? 'The governed run reached its next phase after an evidence-backed session checkpoint without a synthetic human approval record.'
                    : 'The run reused the already compiled task/validation context and continued through the existing governed lifecycle without restarting discovery.',
            ];
            $row['comment'] = 'Observed in the clean-consumer candidate dogfood.';
            $rows[$index] = $row;
        }
        $draft['operating_prompt_outcomes'] = $rows;
        file_put_contents(
            $path,
            json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n",
        );
    }

    /** @return array<string, mixed> */
    private function status(): array
    {
        $result = $this->runCommand([
            'vendor/bin/agent-loop', 'workflow', 'status', self::TASK_ID, '--format=json',
        ]);

        return $this->json($result['stdout'], 'workflow status');
    }

    private function assertState(string $expected): void
    {
        $actual = $this->status()['manifest']['state'] ?? null;
        if ($actual !== $expected) {
            throw new PromptPrimitivesDogfoodFailure(sprintf(
                'Expected workflow state %s, got %s.',
                $expected,
                is_scalar($actual) ? (string) $actual : get_debug_type($actual),
            ));
        }
    }

    private function writeConsumerComposer(): void
    {
        $candidateVersion = MinimumReleasePin::pathRepositoryVersion(
            MinimumReleasePin::declaredConstraint($this->agentLoopRoot . '/composer.json', 'voku/agent-recall-compiler'),
        );
        $this->writeJson($this->consumerRoot . '/composer.json', [
            'name' => 'voku/prompt-primitives-dogfood-consumer',
            'type' => 'project',
            'require-dev' => [
                'voku/agent-loop' => 'dev-main',
                'voku/agent-recall-compiler' => $candidateVersion,
            ],
            'repositories' => [
                [
                    'type' => 'path',
                    'url' => str_replace('\\', '/', $this->agentLoopRoot),
                    'options' => [
                        'symlink' => false,
                        'versions' => ['voku/agent-loop' => 'dev-main'],
                    ],
                ],
                [
                    'type' => 'path',
                    'url' => str_replace('\\', '/', $this->recallRoot),
                    'options' => [
                        'symlink' => false,
                        'versions' => ['voku/agent-recall-compiler' => $candidateVersion],
                    ],
                ],
            ],
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
            'config' => ['allow-plugins' => false, 'sort-packages' => true],
        ]);
    }

    /**
     * @param list<string> $command
     * @param list<int> $allowedExitCodes
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runCommand(array $command, array $allowedExitCodes = [0]): array
    {
        ++$this->commandNumber;
        $logRoot = dirname($this->reportPath) . '/prompt-primitives-logs';
        $this->mkdir($logRoot);
        $prefix = sprintf('%03d', $this->commandNumber);
        $stdoutPath = $logRoot . '/' . $prefix . '.stdout.log';
        $stderrPath = $logRoot . '/' . $prefix . '.stderr.log';

        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['file', $stdoutPath, 'w'], 2 => ['file', $stderrPath, 'w']],
            $pipes,
            $this->consumerRoot,
            array_merge(is_array(getenv()) ? getenv() : [], [
                'COMPOSER_NO_INTERACTION' => '1',
                'COMPOSER_ALLOW_SUPERUSER' => '1',
            ]),
        );
        if (!is_resource($process)) {
            throw new PromptPrimitivesDogfoodFailure('Unable to start command: ' . implode(' ', $command));
        }
        fclose($pipes[0]);
        $exit = proc_close($process);
        $stdout = (string) file_get_contents($stdoutPath);
        $stderr = (string) file_get_contents($stderrPath);
        $this->commands[] = [
            'command' => implode(' ', $command),
            'exit_code' => $exit,
            'stdout_sha256' => 'sha256:' . hash('sha256', $stdout),
            'stderr_sha256' => 'sha256:' . hash('sha256', $stderr),
        ];
        if (!in_array($exit, $allowedExitCodes, true)) {
            throw new PromptPrimitivesDogfoodFailure(sprintf(
                'Command failed with exit %d: %s. stderr=%s',
                $exit,
                implode(' ', $command),
                trim($stderr),
            ));
        }

        return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function assertContains(string $content, string $needle, string $label): void
    {
        if (!str_contains($content, $needle)) {
            throw new PromptPrimitivesDogfoodFailure($label . ' is missing expected text: ' . $needle);
        }
    }

    private function assertFile(string $relativePath): void
    {
        if (!is_file($this->consumerRoot . '/' . $relativePath)) {
            throw new PromptPrimitivesDogfoodFailure('Expected consumer file is missing: ' . $relativePath);
        }
    }

    /** @return array<string, mixed> */
    private function jsonFile(string $path): array
    {
        if (!is_file($path)) {
            throw new PromptPrimitivesDogfoodFailure('Expected JSON file is missing: ' . $path);
        }

        return $this->json((string) file_get_contents($path), $path);
    }

    /** @return array<string, mixed> */
    private function json(string $content, string $label): array
    {
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new PromptPrimitivesDogfoodFailure($label . ' did not decode to an object.');
        }

        return $decoded;
    }

    private function writeReport(
        string $result,
        ?string $failure,
        ?string $projectReflection,
        ?string $taskReflection,
    ): void {
        $this->writeJson($this->reportPath, [
            'schema_version' => '1.0',
            'result' => $result,
            'failure' => $failure,
            'recall_candidate' => basename($this->recallRoot),
            'commands' => $this->commands,
            'project_reflection_sha256' => $projectReflection !== null ? 'sha256:' . hash('sha256', $projectReflection) : null,
            'task_reflection_sha256' => $taskReflection !== null ? 'sha256:' . hash('sha256', $taskReflection) : null,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $this->mkdir(dirname($path));
        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n",
        );
    }

    private function mkdir(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0o775, true) && !is_dir($path)) {
            throw new PromptPrimitivesDogfoodFailure('Unable to create directory: ' . $path);
        }
    }

    private function removeTree(string $path): void
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

$recallRoot = null;
$workspace = sys_get_temp_dir() . '/agent-loop-prompt-primitives-' . bin2hex(random_bytes(4));
$report = dirname(__DIR__) . '/build/prompt-primitives-dogfood.json';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--recall-root=')) {
        $recallRoot = substr($argument, strlen('--recall-root='));
        continue;
    }
    if (str_starts_with($argument, '--workspace=')) {
        $workspace = substr($argument, strlen('--workspace='));
        continue;
    }
    if (str_starts_with($argument, '--report=')) {
        $report = substr($argument, strlen('--report='));
        continue;
    }
    throw new InvalidArgumentException('Unknown option: ' . $argument);
}
if (!is_string($recallRoot) || $recallRoot === '' || !is_dir($recallRoot)) {
    fwrite(STDERR, "--recall-root must point to the checked-out agent-recall-compiler candidate.\n");
    exit(2);
}

exit((new PromptPrimitivesDogfood(dirname(__DIR__), $recallRoot, $workspace, $report))->run());
