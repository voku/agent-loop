<?php

declare(strict_types=1);

/**
 * Installed release-set dogfood gate.
 *
 * The current agent-loop checkout is installed as a real Composer dependency
 * into a fresh consumer. Coordinated owner candidates are added as path
 * repositories only when the CI workspace contains them. Once those versions
 * are published, the same runner resolves them from Packagist without changes.
 */

final class ReleaseSetFailure extends RuntimeException
{
}

final class ReleaseSetDogfood
{
    private string $workspace;
    private string $candidateRoot;
    private string $consumerRoot;
    private string $artifactRoot;
    private string $logRoot;
    private string $reportPath;
    private bool $keep;

    /** @var list<array<string, mixed>> */
    private array $scenarios = [];

    /** @var list<array<string, mixed>> */
    private array $commands = [];

    /** @var list<array<string, string>> */
    private array $friction = [];

    private string $scenario = 'bootstrap';
    private int $commandNumber = 0;

    /** @param array{workspace: string, report: string, keep: bool} $options */
    public function __construct(
        private readonly string $repositoryRoot,
        array $options,
    ) {
        $this->workspace = $options['workspace'];
        $this->candidateRoot = $this->workspace . '/candidate-agent-loop';
        $this->consumerRoot = $this->workspace . '/consumer';
        $this->artifactRoot = $this->workspace . '/artifacts';
        $this->logRoot = $this->artifactRoot . '/logs';
        $this->reportPath = $options['report'];
        $this->keep = $options['keep'];
    }

    public function run(): int
    {
        $this->reset();
        $failed = false;

        try {
            $this->stageCandidate();
            $this->copyTree($this->repositoryRoot . '/tests/fixtures/release-set-consumer', $this->consumerRoot);
            $this->writeConsumerFiles();

            $this->step('install.resolve', fn () => $this->install());
            $this->step('map.consumer-boundary', fn () => $this->mapConsumerBoundary());
            $this->step('workflow.scaffold', fn () => $this->scaffold());
            $this->step('workflow.ephemeral', fn () => $this->ephemeral());
            $this->step('workflow.plan', fn () => $this->plan());
            $this->step('workflow.approve', fn () => $this->approve());
            $this->step('workflow.implement', fn () => $this->implement());
            $this->step('workflow.validate', fn () => $this->validate());
            $this->step('workflow.review', fn () => $this->review());
            $this->step('workflow.learn', fn () => $this->learn());
            $this->step('workflow.close', fn () => $this->close());
            $this->step('workflow.prune-replay', fn () => $this->pruneAndReplay());
        } catch (Throwable $exception) {
            $failed = true;
            $this->friction[] = [
                'scenario' => $this->scenario,
                'message' => $exception->getMessage(),
            ];
        }

        $this->writeReport($failed);
        echo 'Release-set dogfood: ' . ($failed ? 'FAILED' : 'PASSED') . "\n";
        echo 'Report: ' . $this->reportPath . "\n";
        if ($this->keep) {
            echo 'Workspace retained: ' . $this->workspace . "\n";
        } elseif (!$failed) {
            $this->removeTree($this->workspace);
        }

        return $failed ? 1 : 0;
    }

    private function install(): void
    {
        $this->mustRun(['composer', 'update', '--no-interaction', '--prefer-dist', '--no-progress', '--no-ansi']);
        $this->mustRun(['git', 'init', '--initial-branch=main']);
        $this->mustRun(['git', 'config', 'user.name', 'Release Set Gate']);
        $this->mustRun(['git', 'config', 'user.email', 'release-set@example.invalid']);
        $this->mustRun(['git', 'add', 'composer.json', 'composer.lock', '.gitignore', 'src', 'tests', 'tools']);
        $this->mustRun(['git', 'commit', '-m', 'fixture: initial consumer state']);

        $packages = $this->resolvedPackages();
        foreach ([
            'voku/agent-loop',
            'voku/agent-session',
            'voku/agent-recall-compiler',
            'voku/agent-learning',
            'voku/agent-map',
            'voku/simple-php-code-parser',
        ] as $package) {
            if (!isset($packages[$package])) {
                throw new ReleaseSetFailure('Resolved consumer is missing ' . $package . '.');
            }
        }
        $this->artifact($this->consumerRoot . '/composer.lock');
    }

    private function mapConsumerBoundary(): void
    {
        $this->mustRun([
            'vendor/bin/agent-map', 'build', '--root=.', '--paths=src,tests', '--out=.agent-map/php-symbols.json',
        ]);
        $this->mustRun([
            'vendor/bin/agent-map', 'search-index', 'build', '--root=.', '--index=.agent-map/php-symbols.json', '--database=.agent-map/search.sqlite',
        ]);
        $exact = $this->mustRun([
            'vendor/bin/agent-map', 'scope', 'Fixture\\RetryPolicy::delayMilliseconds', '--index=.agent-map/php-symbols.json', '--format=json',
        ]);
        $decoded = $this->json($exact['stdout'], 'agent-map exact scope');
        $target = $decoded['target'] ?? null;
        if (!is_array($target) || ($target['label'] ?? null) !== 'Fixture\\RetryPolicy::delayMilliseconds') {
            throw new ReleaseSetFailure('agent-map did not resolve the consumer parser target.');
        }

        foreach ([
            'How is the delay before retrying a timed out request calculated?',
            'Wie wird die Wartezeit vor einem erneuten Versuch nach einer Zeitüberschreitung berechnet?',
        ] as $query) {
            $search = $this->mustRun([
                'vendor/bin/agent-map', 'search', $query,
                '--root=.', '--index=.agent-map/php-symbols.json', '--database=.agent-map/search.sqlite', '--format=json', '--limit=5',
            ]);
            if (!str_contains($search['stdout'], 'RetryPolicy')) {
                throw new ReleaseSetFailure('agent-map behavior search did not find RetryPolicy for query: ' . $query);
            }
        }
        $this->artifact($this->consumerRoot . '/.agent-map/php-symbols.json');
    }

    private function scaffold(): void
    {
        $this->mustRun(['vendor/bin/agent-loop', 'init', 'scaffold']);
        $this->assertFile($this->consumerRoot . '/tasks/DEMO-1.md');
        $this->assertFile($this->consumerRoot . '/todo/cards/DEMO-1.md');
    }

    private function ephemeral(): void
    {
        $started = $this->mustRun([
            'vendor/bin/agent-loop', 'session', 'start', '--task', 'EXP-1', '--by', 'release-set-gate', '--ephemeral',
        ]);
        if (preg_match('/Started session:\s+(\S+)/', $started['stdout'], $match) !== 1) {
            throw new ReleaseSetFailure('Ephemeral Session id was not reported.');
        }
        $status = $this->status('EXP-1');
        if (($status['manifest']['mode'] ?? null) !== 'ephemeral') {
            throw new ReleaseSetFailure('Ephemeral Session was not projected as ephemeral.');
        }
        $this->mustRun(['vendor/bin/agent-loop', 'session', 'close', $match[1], '--status', 'dropped']);
    }

    private function plan(): void
    {
        $this->mustRun([
            'vendor/bin/agent-loop', 'workflow', 'plan', 'DEMO-1',
            '--by', 'release-set-gate',
            '--file', 'src/RetryPolicy.php',
            '--goal', 'Double the deterministic retry delay.',
            '--behavior-anchor', 'request timeout -> RetryPolicy delay -> caller-observed wait',
            '--validation', 'composer test',
        ]);
        $contract = $this->jsonFile($this->consumerRoot . '/.agent-loop/contracts/DEMO-1/contract.json');
        if (($contract['status'] ?? null) !== 'candidate' || ($contract['revision'] ?? null) !== 1) {
            throw new ReleaseSetFailure('PLAN did not persist candidate Contract revision 1.');
        }
        foreach (glob($this->consumerRoot . '/session_plan/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $sessionFile = $directory . '/session.json';
            if (is_file($sessionFile) && ($this->jsonFile($sessionFile)['task_id'] ?? null) === 'DEMO-1') {
                throw new ReleaseSetFailure('PLAN created pruneable Session state before approval.');
            }
        }
        $this->artifact($this->consumerRoot . '/.agent-loop/contracts/DEMO-1/contract.json');
    }

    private function approve(): void
    {
        $this->mustRun(['vendor/bin/agent-loop', 'workflow', 'approve', 'DEMO-1', '--by', 'release-set-gate']);
        $status = $this->status('DEMO-1');
        $this->assertReference($status, 'approval', 'current');
        $this->assertReference($status, 'recall', 'compiled');
        $runId = $status['manifest']['run_id'] ?? null;
        if (!is_string($runId) || !str_starts_with($runId, 'run:')) {
            throw new ReleaseSetFailure('APPROVE did not create durable Run identity.');
        }
        $this->writeJson($this->artifactRoot . '/run-before-close.json', ['run_id' => $runId]);
        $this->artifact($this->consumerRoot . '/.agent-loop/runs/DEMO-1/run.json');

        foreach (glob($this->consumerRoot . '/session_plan/*', GLOB_ONLYDIR) ?: [] as $directory) {
            if (is_file($directory . '/work-brief.json') || is_file($directory . '/approval.json') || is_file($directory . '/learning-decision.json')) {
                throw new ReleaseSetFailure('Session contains removed durable authority artifacts.');
            }
        }
    }

    private function implement(): void
    {
        $this->mustRun([PHP_BINARY, 'tools/apply-change.php']);
        $this->mustRun([
            'vendor/bin/agent-map', 'refresh', '--root=.', '--index=.agent-map/php-symbols.json', '--out=.agent-map/php-symbols.json',
        ]);
        $this->mustRun([
            'vendor/bin/agent-map', 'search-index', 'refresh', '--root=.', '--index=.agent-map/php-symbols.json', '--database=.agent-map/search.sqlite',
        ]);
        // Recompile context after the actual edit while preserving the same Run.
        $before = $this->status('DEMO-1')['manifest']['run_id'] ?? null;
        $this->mustRun(['vendor/bin/agent-loop', 'workflow', 'approve', 'DEMO-1', '--by', 'release-set-gate']);
        $after = $this->status('DEMO-1')['manifest']['run_id'] ?? null;
        if (!is_string($before) || $after !== $before) {
            throw new ReleaseSetFailure('Recall refresh changed durable Run identity.');
        }
        $this->artifact($this->consumerRoot . '/src/RetryPolicy.php');
    }

    private function validate(): void
    {
        $this->mustRun(['composer', 'test']);
        $this->mustRun([
            'vendor/bin/agent-loop', 'session', 'validation', 'record', 'DEMO-1',
            '--contract-revision', '1',
            '--command', 'composer test',
            '--status', 'passed',
            '--exit-code', '0',
            '--duration-ms', '0',
            '--by', 'release-set-gate',
        ]);
    }

    private function review(): void
    {
        $this->mustRun(['vendor/bin/agent-loop', 'review', 'blindspots', 'DEMO-1'], [0, 1]);
        $this->mustRun([
            'vendor/bin/agent-loop', 'session', 'checkpoint', 'DEMO-1',
            '--title', 'Release-set review',
            '--body', 'The deterministic blind-spot report was inspected by the release-set gate.',
        ]);
        $this->mustRun(['vendor/bin/agent-loop', 'review', 'blindspots', 'DEMO-1']);
    }

    private function learn(): void
    {
        $this->mustRun([
            'vendor/bin/agent-loop', 'workflow', 'learn', 'DEMO-1',
            '--status', 'no_durable_learning',
            '--by', 'release-set-gate',
            '--reason', 'The installed release-set proof produced no reusable guidance.',
        ]);
        $status = $this->status('DEMO-1');
        $this->assertReference($status, 'learning', 'decided');
    }

    private function close(): void
    {
        $this->mustRun(['vendor/bin/agent-loop', 'verify', '--task-id=DEMO-1']);
        $this->mustRun(['vendor/bin/agent-loop', 'workflow', 'close', 'DEMO-1', '--status', 'done']);
        $status = $this->status('DEMO-1');
        if (($status['manifest']['state'] ?? null) !== 'complete') {
            throw new ReleaseSetFailure('CLOSE did not produce complete durable Run state.');
        }
        $this->assertReference($status, 'verification', 'passed');
        $this->assertReference($status, 'session', 'done');
        $this->artifact($this->consumerRoot . '/.agent-loop/runs/DEMO-1/verification.json');
    }

    private function pruneAndReplay(): void
    {
        $before = $this->jsonFile($this->artifactRoot . '/run-before-close.json')['run_id'] ?? null;
        $this->mustRun(['vendor/bin/agent-loop', 'session', 'prune', '--keep-days', '0', '--status', 'done']);

        $status = $this->status('DEMO-1');
        $after = $status['manifest']['run_id'] ?? null;
        if (!is_string($before) || $after !== $before) {
            throw new ReleaseSetFailure('Pruning Session working memory changed Run identity.');
        }
        if (($status['manifest']['state'] ?? null) !== 'complete') {
            throw new ReleaseSetFailure('Completed Run stopped being complete after Session pruning.');
        }
        $this->assertReference($status, 'session', 'missing');
        $this->assertReference($status, 'verification', 'passed');
        $this->assertReference($status, 'learning', 'decided');

        $report = $this->mustRun(['vendor/bin/agent-loop', 'workflow', 'report', 'DEMO-1', '--format', 'json']);
        $decoded = $this->json($report['stdout'], 'post-prune workflow report');
        if (($decoded['validation'][0]['source'] ?? null) !== 'verification_receipt') {
            throw new ReleaseSetFailure('Post-prune report did not replay validation from durable Verification Receipt.');
        }
        $this->writeJson($this->artifactRoot . '/post-prune-status.json', $status);
        $this->writeJson($this->artifactRoot . '/post-prune-report.json', $decoded);
    }

    /** @return array<string, mixed> */
    private function status(string $taskId): array
    {
        $result = $this->mustRun(['vendor/bin/agent-loop', 'workflow', 'status', $taskId, '--format=json']);

        return $this->json($result['stdout'], 'workflow status ' . $taskId);
    }

    /** @param array<string, mixed> $status */
    private function assertReference(array $status, string $name, string $expected): void
    {
        $actual = $status['manifest']['references'][$name]['state'] ?? null;
        if ($actual !== $expected) {
            throw new ReleaseSetFailure(sprintf(
                'Expected %s state %s, got %s.',
                $name,
                $expected,
                is_scalar($actual) ? (string) $actual : get_debug_type($actual),
            ));
        }
    }

    private function writeConsumerFiles(): void
    {
        $this->mkdir($this->artifactRoot);
        $this->mkdir($this->logRoot);

        $repositories = [[
            'type' => 'path',
            'url' => str_replace('\\', '/', $this->candidateRoot),
            'options' => ['symlink' => false, 'versions' => ['voku/agent-loop' => 'dev-main']],
        ]];
        foreach ([
            ['build/candidate-agent-session', 'voku/agent-session', '0.5.999'],
            ['build/candidate-agent-recall-compiler', 'voku/agent-recall-compiler', '0.11.999'],
            ['build/candidate-agent-learning', 'voku/agent-learning', '0.10.999'],
        ] as [$relative, $package, $version]) {
            $path = $this->repositoryRoot . '/' . $relative;
            if (!is_dir($path)) {
                continue;
            }
            $repositories[] = [
                'type' => 'path',
                'url' => str_replace('\\', '/', $path),
                'options' => ['symlink' => false, 'versions' => [$package => $version]],
            ];
        }

        $this->writeJson($this->consumerRoot . '/composer.json', [
            'name' => 'voku/release-set-consumer-fixture',
            'type' => 'project',
            'require-dev' => ['voku/agent-loop' => 'dev-main'],
            'repositories' => $repositories,
            'autoload' => ['psr-4' => ['Fixture\\' => 'src/']],
            'scripts' => ['test' => 'php tests/run.php'],
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
            'config' => ['allow-plugins' => false, 'sort-packages' => true],
        ]);
        file_put_contents(
            $this->consumerRoot . '/.gitignore',
            "/vendor/\n/.agent-map/\n/.agent-loop/\nsession_plan/\n/recall/\n/infra/doc/agent-learning/history/\n",
        );
    }

    private function stageCandidate(): void
    {
        $this->mkdir($this->candidateRoot);
        foreach (['composer.json', 'LICENSE'] as $file) {
            $source = $this->repositoryRoot . '/' . $file;
            if (is_file($source)) {
                copy($source, $this->candidateRoot . '/' . $file);
            }
        }
        foreach (['src', 'bin'] as $directory) {
            $this->copyTree($this->repositoryRoot . '/' . $directory, $this->candidateRoot . '/' . $directory);
        }
    }

    /** @return array<string, array{version: string}> */
    private function resolvedPackages(): array
    {
        $lock = $this->jsonFile($this->consumerRoot . '/composer.lock');
        $packages = [];
        foreach (['packages', 'packages-dev'] as $section) {
            foreach (is_array($lock[$section] ?? null) ? $lock[$section] : [] as $row) {
                if (!is_array($row) || !is_string($row['name'] ?? null)) {
                    continue;
                }
                $packages[$row['name']] = [
                    'version' => is_string($row['version'] ?? null) ? $row['version'] : 'unknown',
                ];
            }
        }
        ksort($packages, SORT_STRING);

        return $packages;
    }

    private function step(string $id, callable $callback): void
    {
        $this->scenario = $id;
        $before = count($this->commands);
        try {
            $callback();
            $this->scenarios[] = [
                'id' => $id,
                'status' => 'passed',
                'commands' => array_slice($this->commands, $before),
            ];
        } catch (Throwable $exception) {
            $this->scenarios[] = [
                'id' => $id,
                'status' => 'failed',
                'commands' => array_slice($this->commands, $before),
                'failure' => $exception->getMessage(),
            ];
            throw $exception;
        }
    }

    /** @param list<string> $command @param list<int> $allowedExitCodes @return array{exit: int, stdout: string, stderr: string} */
    private function mustRun(array $command, array $allowedExitCodes = [0]): array
    {
        $result = $this->runCommand($command);
        if (!in_array($result['exit'], $allowedExitCodes, true)) {
            throw new ReleaseSetFailure(sprintf(
                'Command failed with exit %d: %s. See %s and %s.',
                $result['exit'],
                implode(' ', $command),
                $result['stdout_log'],
                $result['stderr_log'],
            ));
        }

        return $result;
    }

    /** @param list<string> $command @return array{exit: int, stdout: string, stderr: string, stdout_log: string, stderr_log: string} */
    private function runCommand(array $command): array
    {
        ++$this->commandNumber;
        $base = sprintf('%03d-%s', $this->commandNumber, preg_replace('/[^A-Za-z0-9_.-]+/', '-', $this->scenario) ?? 'command');
        $stdoutPath = $this->logRoot . '/' . $base . '.stdout.log';
        $stderrPath = $this->logRoot . '/' . $base . '.stderr.log';
        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
        $environment['COMPOSER_NO_INTERACTION'] = '1';
        $environment['COMPOSER_ALLOW_SUPERUSER'] = '1';

        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['file', $stdoutPath, 'w'], 2 => ['file', $stderrPath, 'w']],
            $pipes,
            $this->consumerRoot,
            $environment,
        );
        if (!is_resource($process)) {
            throw new ReleaseSetFailure('Unable to start command: ' . implode(' ', $command));
        }
        fclose($pipes[0]);
        $exit = proc_close($process);
        $stdout = (string) file_get_contents($stdoutPath);
        $stderr = (string) file_get_contents($stderrPath);
        $result = [
            'exit' => $exit,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'stdout_log' => $this->relativeWorkspace($stdoutPath),
            'stderr_log' => $this->relativeWorkspace($stderrPath),
        ];
        $this->commands[] = [
            'display' => implode(' ', $command),
            'exit_code' => $exit,
            'stdout_sha256' => 'sha256:' . hash('sha256', $stdout),
            'stderr_sha256' => 'sha256:' . hash('sha256', $stderr),
            'stdout_log' => $result['stdout_log'],
            'stderr_log' => $result['stderr_log'],
        ];

        return $result;
    }

    private function writeReport(bool $failed): void
    {
        $this->mkdir(dirname($this->reportPath));
        $this->writeJson($this->reportPath, [
            'schema_version' => '2.0',
            'result' => $failed ? 'failed' : 'passed',
            'release_set' => is_file($this->consumerRoot . '/composer.lock') ? $this->resolvedPackages() : [],
            'scenarios' => $this->scenarios,
            'friction' => $this->friction,
            'platform' => [
                'php' => PHP_VERSION,
                'os_family' => PHP_OS_FAMILY,
            ],
        ]);
    }

    private function artifact(string $path): void
    {
        $this->assertFile($path);
        $target = $this->artifactRoot . '/evidence/' . basename($path);
        $this->mkdir(dirname($target));
        copy($path, $target);
    }

    private function assertFile(string $path): void
    {
        if (!is_file($path)) {
            throw new ReleaseSetFailure('Expected file is missing: ' . $path);
        }
    }

    /** @return array<string, mixed> */
    private function jsonFile(string $path): array
    {
        $this->assertFile($path);

        return $this->json((string) file_get_contents($path), $path);
    }

    /** @return array<string, mixed> */
    private function json(string $content, string $label): array
    {
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new ReleaseSetFailure($label . ' did not decode to an object.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $this->mkdir(dirname($path));
        file_put_contents($path, json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n");
    }

    private function reset(): void
    {
        $this->removeTree($this->workspace);
        $this->mkdir($this->workspace);
    }

    private function copyTree(string $source, string $destination): void
    {
        if (!is_dir($source)) {
            throw new ReleaseSetFailure('Fixture directory is missing: ' . $source);
        }
        $this->mkdir($destination);
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        ) as $item) {
            $target = $destination . '/' . $item->getSubPathname();
            if ($item->isDir()) {
                $this->mkdir($target);
            } else {
                $this->mkdir(dirname($target));
                copy($item->getPathname(), $target);
            }
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

    private function mkdir(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0o775, true) && !is_dir($path)) {
            throw new ReleaseSetFailure('Unable to create directory: ' . $path);
        }
    }

    private function relativeWorkspace(string $path): string
    {
        return str_starts_with($path, $this->workspace . '/')
            ? substr($path, strlen($this->workspace) + 1)
            : $path;
    }
}

/** @return array{workspace: string, report: string, keep: bool} */
function releaseSetOptions(array $argv): array
{
    $workspace = sys_get_temp_dir() . '/agent-loop-release-set-' . bin2hex(random_bytes(4));
    $report = getcwd() . '/build/release-set-report.json';
    $keep = false;
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--keep') {
            $keep = true;
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

    return ['workspace' => rtrim($workspace, '/'), 'report' => $report, 'keep' => $keep];
}

$repositoryRoot = dirname(__DIR__);
try {
    exit((new ReleaseSetDogfood($repositoryRoot, releaseSetOptions($argv)))->run());
} catch (Throwable $exception) {
    fwrite(STDERR, 'Release-set dogfood bootstrap failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
