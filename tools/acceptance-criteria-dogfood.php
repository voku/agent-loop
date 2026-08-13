<?php

declare(strict_types=1);

final class AcceptanceCriteriaDogfoodFailure extends RuntimeException
{
}

final class AcceptanceCriteriaDogfood
{
    private const string TASK_ID = 'ACCEPTANCE-DOGFOOD';
    private const string VALIDATION = 'php -r "exit(0);"';
    private const array CRITERIA = [
        'Recall keeps the first required outcome visible.',
        'Workflow review keeps the second required outcome visible.',
    ];

    private string $consumerRoot;
    /** @var list<array{command: string, exit_code: int}> */
    private array $commands = [];

    public function __construct(
        private readonly string $agentLoopRoot,
        private readonly string $recallRoot,
        string $workspace,
        private readonly string $reportPath,
    ) {
        $this->consumerRoot = rtrim($workspace, '/\\') . '/consumer';
    }

    public function run(): int
    {
        $this->removeTree(dirname($this->consumerRoot));
        $this->mkdir($this->consumerRoot);
        $this->writeConsumerComposer();

        try {
            $this->runCommand(['composer', 'update', '--no-interaction', '--prefer-dist', '--no-progress', '--no-ansi']);
            $this->runCommand(['vendor/bin/agent-loop', 'init', 'scaffold']);
            $this->plan();
            $this->assertContract('candidate');

            $this->runCommand([
                'vendor/bin/agent-loop', 'workflow', 'approve', self::TASK_ID,
                '--by', 'ci-approval-fixture',
                '--learning-root', '.agent-loop/learning',
            ]);
            $this->assertContract('approved');
            $this->assertRecall();
            $this->assertWorkflowProjections();

            // A green validation command must not manufacture acceptance status.
            $this->runCommand([PHP_BINARY, '-r', 'exit(0);']);
            $this->runCommand([
                'vendor/bin/agent-loop', 'session', 'validation', 'record', self::TASK_ID,
                '--contract-revision', '1',
                '--command', self::VALIDATION,
                '--status', 'passed',
                '--exit-code', '0',
                '--duration-ms', '0',
                '--by', 'acceptance-dogfood',
            ]);
            $this->assertWorkflowProjections();

            $this->writeReport('passed', null);
            echo "Acceptance-criteria dogfood: PASSED\n";

            return 0;
        } catch (Throwable $exception) {
            $this->writeReport('failed', $exception->getMessage());
            fwrite(STDERR, 'Acceptance-criteria dogfood: FAILED: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    private function plan(): void
    {
        $this->runCommand([
            'vendor/bin/agent-loop', 'workflow', 'plan', self::TASK_ID,
            '--by', 'acceptance-dogfood',
            '--learning-root', '.agent-loop/learning',
            '--file', 'composer.json',
            '--goal', 'Prove required outcomes survive the installed governed workflow.',
            '--acceptance', self::CRITERIA[0],
            '--acceptance', self::CRITERIA[1],
            '--behavior-anchor', 'PLAN -> approved Contract -> governed Recall -> status/report',
            '--validation', self::VALIDATION,
        ]);
    }

    private function assertContract(string $expectedStatus): void
    {
        $contract = $this->jsonFile('.agent-loop/contracts/' . self::TASK_ID . '/contract.json');
        $this->assertCriteria($contract, 'persisted Contract');

        $status = $contract['status'] ?? null;
        if (!is_string($status) || $status !== $expectedStatus) {
            throw new AcceptanceCriteriaDogfoodFailure('persisted Contract status mismatch.');
        }
        $this->assertNoFakeAcceptanceStatus($contract, 'persisted Contract');
    }

    private function assertRecall(): void
    {
        $systemPath = '.agent-loop/recall/' . self::TASK_ID . '/system.md';
        $system = $this->textFile($systemPath);
        $this->assertContains($system, '## Acceptance Criteria', 'Recall system.md');
        $this->assertContains($system, 'required outcomes from the approved task Contract', 'Recall system.md');
        $this->assertContains($system, 'not evidence that they are satisfied', 'Recall system.md');
        foreach (self::CRITERIA as $criterion) {
            $this->assertContains($system, '- ' . $criterion, 'Recall system.md');
        }

        $facts = $this->jsonFile('.agent-loop/recall/' . self::TASK_ID . '/facts.json');
        $criteria = $this->taskContextAcceptanceCriteria($facts);
        if ($criteria !== self::CRITERIA) {
            throw new AcceptanceCriteriaDogfoodFailure('Recall task-context criteria mismatch.');
        }
    }

    private function assertWorkflowProjections(): void
    {
        $statusResult = $this->runCommand([
            'vendor/bin/agent-loop', 'workflow', 'status', self::TASK_ID, '--format=json',
        ]);
        $status = $this->json($statusResult['stdout'], 'workflow status');
        $contract = $status['manifest']['references']['contract'] ?? null;
        if (!is_array($contract)) {
            throw new AcceptanceCriteriaDogfoodFailure('workflow status misses Contract reference.');
        }
        $this->assertCriteria($contract, 'workflow status Contract');
        $this->assertNoFakeAcceptanceStatus($contract, 'workflow status Contract');

        $reportResult = $this->runCommand([
            'vendor/bin/agent-loop', 'workflow', 'report', self::TASK_ID, '--format', 'json',
        ]);
        $report = $this->json($reportResult['stdout'], 'workflow report');
        $reportContract = $report['contract'] ?? null;
        if (!is_array($reportContract)) {
            throw new AcceptanceCriteriaDogfoodFailure('workflow report misses Contract projection.');
        }
        $this->assertCriteria($reportContract, 'workflow report Contract');
        $this->assertNoFakeAcceptanceStatus($reportContract, 'workflow report Contract');
    }

    /**
     * @param array<string, mixed> $factsDocument
     * @return list<string>
     */
    private function taskContextAcceptanceCriteria(array $factsDocument): array
    {
        $facts = $factsDocument['facts'] ?? null;
        if (!is_array($facts)) {
            throw new AcceptanceCriteriaDogfoodFailure('Recall facts.json misses the facts list.');
        }

        $matches = [];
        foreach ($facts as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            if (($fact['id'] ?? null) !== 'task.' . self::TASK_ID || ($fact['type'] ?? null) !== 'task_context') {
                continue;
            }
            $matches[] = $fact;
        }
        if (count($matches) !== 1) {
            throw new AcceptanceCriteriaDogfoodFailure(sprintf(
                'Expected exactly one canonical task-context fact, found %d.',
                count($matches),
            ));
        }

        $payload = $matches[0]['payload'] ?? null;
        if (!is_array($payload)) {
            throw new AcceptanceCriteriaDogfoodFailure('Canonical task-context fact misses payload.');
        }

        return $this->criteriaFrom($payload, 'Canonical task-context fact');
    }

    /** @param array<string, mixed> $data */
    private function assertCriteria(array $data, string $label): void
    {
        if ($this->criteriaFrom($data, $label) !== self::CRITERIA) {
            throw new AcceptanceCriteriaDogfoodFailure($label . ' criteria mismatch.');
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function criteriaFrom(array $data, string $label): array
    {
        $criteria = $data['acceptance_criteria'] ?? null;
        if (!is_array($criteria)) {
            throw new AcceptanceCriteriaDogfoodFailure($label . ' misses acceptance_criteria.');
        }

        $result = [];
        foreach ($criteria as $criterion) {
            if (!is_string($criterion)) {
                throw new AcceptanceCriteriaDogfoodFailure($label . ' contains a non-string acceptance criterion.');
            }
            $result[] = $criterion;
        }

        return $result;
    }

    /** @param array<string, mixed> $data */
    private function assertNoFakeAcceptanceStatus(array $data, string $label): void
    {
        foreach (['acceptance_status', 'acceptance_passed', 'acceptance_satisfied'] as $key) {
            if (array_key_exists($key, $data)) {
                throw new AcceptanceCriteriaDogfoodFailure($label . ' manufactured ' . $key . '.');
            }
        }
    }

    private function writeConsumerComposer(): void
    {
        $this->writeJson($this->consumerRoot . '/composer.json', [
            'name' => 'voku/acceptance-criteria-dogfood-consumer',
            'type' => 'project',
            'require-dev' => [
                'voku/agent-loop' => 'dev-main',
                'voku/agent-recall-compiler' => '0.11.2',
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
                        'versions' => ['voku/agent-recall-compiler' => '0.11.2'],
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
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runCommand(array $command): array
    {
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->consumerRoot,
            array_merge(is_array(getenv()) ? getenv() : [], [
                'COMPOSER_NO_INTERACTION' => '1',
                'COMPOSER_ALLOW_SUPERUSER' => '1',
            ]),
        );
        if (!is_resource($process)) {
            throw new AcceptanceCriteriaDogfoodFailure('Unable to run: ' . implode(' ', $command));
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $stdout = is_string($stdout) ? $stdout : '';
        $stderr = is_string($stderr) ? $stderr : '';
        $this->commands[] = ['command' => implode(' ', $command), 'exit_code' => $exit];
        if ($exit !== 0) {
            throw new AcceptanceCriteriaDogfoodFailure(sprintf(
                'Command failed with exit %d: %s; stderr=%s',
                $exit,
                implode(' ', $command),
                trim($stderr),
            ));
        }

        return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function textFile(string $relativePath): string
    {
        $path = $this->consumerRoot . '/' . $relativePath;
        if (!is_file($path)) {
            throw new AcceptanceCriteriaDogfoodFailure('Missing file: ' . $relativePath);
        }
        $content = file_get_contents($path);
        if (!is_string($content)) {
            throw new AcceptanceCriteriaDogfoodFailure('Unable to read file: ' . $relativePath);
        }

        return $content;
    }

    /** @return array<string, mixed> */
    private function jsonFile(string $relativePath): array
    {
        return $this->json($this->textFile($relativePath), $relativePath);
    }

    /** @return array<string, mixed> */
    private function json(string $content, string $label): array
    {
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new AcceptanceCriteriaDogfoodFailure($label . ' did not decode to an object.');
        }

        return $decoded;
    }

    private function assertContains(string $haystack, string $needle, string $label): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new AcceptanceCriteriaDogfoodFailure($label . ' misses: ' . $needle);
        }
    }

    private function writeReport(string $result, ?string $failure): void
    {
        $this->writeJson($this->reportPath, [
            'schema_version' => '1.0',
            'result' => $result,
            'failure' => $failure,
            'criteria' => self::CRITERIA,
            'commands' => $this->commands,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $this->mkdir(dirname($path));
        if (file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n",
        ) === false) {
            throw new AcceptanceCriteriaDogfoodFailure('Unable to write JSON: ' . $path);
        }
    }

    private function mkdir(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0o775, true) && !is_dir($path)) {
            throw new AcceptanceCriteriaDogfoodFailure('Unable to create directory: ' . $path);
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeTree($full) : unlink($full);
        }
        rmdir($path);
    }
}

$options = getopt('', ['recall-root:', 'workspace:', 'report:']);
$recallRoot = $options['recall-root'] ?? null;
$workspace = $options['workspace'] ?? null;
$report = $options['report'] ?? null;
if (!is_string($recallRoot) || !is_string($workspace) || !is_string($report)) {
    fwrite(STDERR, "Usage: php tools/acceptance-criteria-dogfood.php --recall-root=PATH --workspace=PATH --report=PATH\n");
    exit(2);
}

exit((new AcceptanceCriteriaDogfood(dirname(__DIR__), $recallRoot, $workspace, $report))->run());
