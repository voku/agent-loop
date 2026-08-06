<?php

declare(strict_types=1);

/**
 * Installed release-set dogfood gate.
 *
 * This script intentionally depends only on PHP itself. It stages the current
 * agent-loop checkout without its vendor directory, installs that candidate and
 * the released focused packages into a fresh Composer consumer, then exercises
 * repository discovery and a governed lifecycle through close.
 */

final class DogfoodFailure extends RuntimeException
{
    public function __construct(
        public readonly string $classification,
        string $message,
    ) {
        parent::__construct($message);
    }
}

final readonly class CommandResult
{
    /**
     * @param list<string> $command
     */
    public function __construct(
        public array $command,
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public string $stdoutLog,
        public string $stderrLog,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'command' => array_values($this->command),
            'display' => self::display($this->command),
            'exit_code' => $this->exitCode,
            'stdout_sha256' => 'sha256:' . hash('sha256', $this->stdout),
            'stderr_sha256' => 'sha256:' . hash('sha256', $this->stderr),
            'stdout_log' => $this->stdoutLog,
            'stderr_log' => $this->stderrLog,
        ];
    }

    /** @param list<string> $command */
    private static function display(array $command): string
    {
        return implode(' ', array_map(
            static fn (string $argument): string => preg_match('/\A[A-Za-z0-9_\.\/\\:-]+\z/', $argument) === 1
                ? $argument
                : "'" . str_replace("'", "'\\''", $argument) . "'",
            $command,
        ));
    }
}

final class ReleaseSetDogfood
{
    private const REPORT_SCHEMA = '1.0';

    private string $workspace;
    private string $candidateRoot;
    private string $consumerRoot;
    private string $artifactRoot;
    private string $logRoot;
    private string $reportPath;
    private bool $keepWorkspace;

    /** @var list<array<string, mixed>> */
    private array $scenarios = [];

    /** @var list<array<string, string>> */
    private array $friction = [];

    /** @var list<array<string, mixed>> */
    private array $currentCommands = [];

    /** @var list<array<string, string>> */
    private array $currentArtifacts = [];

    private string $currentScenario = 'bootstrap';
    private int $commandNumber = 0;
    private bool $failed = false;

    /**
     * @param array{workspace: string, report: string, keep: bool} $options
     */
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
        $this->keepWorkspace = $options['keep'];
    }

    public function run(): int
    {
        $this->resetWorkspace();

        try {
            $this->stageCandidate();
            $this->copyTree(
                $this->repositoryRoot . '/tests/fixtures/release-set-consumer',
                $this->consumerRoot,
            );
            $this->writeConsumerFiles();

            $this->scenario('install.resolve', fn () => $this->install());
            $this->scenario('autoload.boundary', fn () => $this->autoloadBoundary());
            $this->scenario('cli.namespaces', fn () => $this->cliNamespaces());
            $this->scenario('discover.prepare', fn () => $this->prepareDiscovery());
            $this->scenario('discover.exact', fn () => $this->discoverExact());
            $this->scenario('discover.behavior.en', fn () => $this->discoverEnglish());
            $this->scenario('discover.behavior.de', fn () => $this->discoverGerman());
            $this->scenario('discover.no-answer', fn () => $this->discoverNoAnswer());
            $this->scenario('workflow.scaffold', fn () => $this->scaffold());
            $this->scenario('workflow.ephemeral', fn () => $this->ephemeralWorkflow());
            $this->scenario('workflow.governed.plan', fn () => $this->governedPlan());
            $this->scenario('workflow.governed.approve', fn () => $this->governedApprove());
            $this->scenario('workflow.governed.execute', fn () => $this->governedExecute());
            $this->scenario('workflow.governed.validate', fn () => $this->governedValidate());
            $this->scenario('workflow.governed.review', fn () => $this->governedReview());
            $this->scenario('workflow.governed.learn', fn () => $this->governedLearn());
            $this->scenario('recovery.manifest-stale', fn () => $this->manifestRecovery());
            $this->scenario('workflow.governed.close', fn () => $this->governedClose());
        } catch (DogfoodFailure $failure) {
            $this->failed = true;
            $this->friction[] = [
                'scenario' => $this->currentScenario,
                'classification' => $failure->classification,
                'message' => $failure->getMessage(),
            ];
        } catch (Throwable $failure) {
            $this->failed = true;
            $this->friction[] = [
                'scenario' => $this->currentScenario,
                'classification' => 'fixture_error',
                'message' => $failure->getMessage(),
            ];
        } finally {
            $this->writeReport();
        }

        echo 'Release-set dogfood: ' . ($this->failed ? 'FAILED' : 'PASSED') . "\n";
        echo 'Report: ' . $this->reportPath . "\n";
        if ($this->keepWorkspace) {
            echo 'Workspace retained: ' . $this->workspace . "\n";
        }

        return $this->failed ? 1 : 0;
    }

    private function install(): void
    {
        $this->mustRun(['composer', 'update', '--no-interaction', '--prefer-dist', '--no-progress', '--no-ansi']);
        $this->mustRun(['git', 'init', '--initial-branch=main']);
        $this->mustRun(['git', 'config', 'user.name', 'Release Set Gate']);
        $this->mustRun(['git', 'config', 'user.email', 'release-set@example.invalid']);
        $this->mustRun(['git', 'add', 'composer.json', 'composer.lock', '.gitignore', 'src', 'tests', 'tools']);
        $this->mustRun(['git', 'commit', '-m', 'fixture: initial consumer state']);

        $lock = $this->consumerRoot . '/composer.lock';
        $this->assertFile($lock);
        $this->artifact($lock);

        $packages = $this->resolvedAgentPackages();
        foreach ($this->packageNames() as $package) {
            if (!isset($packages[$package])) {
                throw new DogfoodFailure('contract_gap', 'Resolved lock file does not contain ' . $package . '.');
            }
        }
    }

    private function autoloadBoundary(): void
    {
        $result = $this->mustRun([PHP_BINARY, 'tools/autoload-probe.php']);
        $decoded = $this->json($result->stdout, 'autoload probe output');
        if (($decoded['result'] ?? null) !== 'passed') {
            throw new DogfoodFailure('regression', 'Autoload probe did not report passed.');
        }
        $this->artifact($this->consumerRoot . '/vendor/composer/installed.json');
    }

    private function cliNamespaces(): void
    {
        $binary = 'vendor/bin/agent-loop';
        foreach ([
            [],
            ['board', 'help'],
            ['session', 'help'],
            ['map', 'help'],
            ['recall', 'help'],
            ['workflow', 'help'],
            ['edit', 'help'],
            ['review', 'help'],
            ['learn', 'help'],
            ['init', 'help'],
        ] as $arguments) {
            $this->mustRun([$binary, ...$arguments]);
        }
    }

    private function prepareDiscovery(): void
    {
        $this->mustRun([
            'vendor/bin/agent-map',
            'build',
            '--root=.',
            '--paths=src,tests',
            '--out=.agent-map/php-symbols.json',
        ]);
        $this->mustRun([
            'vendor/bin/agent-map',
            'search-index',
            'build',
            '--root=.',
            '--index=.agent-map/php-symbols.json',
            '--database=.agent-map/search.sqlite',
        ]);
        $this->mustRun([
            'vendor/bin/agent-map',
            'search-index',
            'doctor',
            '--root=.',
            '--index=.agent-map/php-symbols.json',
            '--database=.agent-map/search.sqlite',
        ]);
        $this->artifact($this->consumerRoot . '/.agent-map/php-symbols.json');
        $this->artifact($this->consumerRoot . '/.agent-map/search.sqlite');
    }

    private function discoverExact(): void
    {
        $result = $this->mustRun([
            'vendor/bin/agent-map',
            'scope',
            'Fixture\\RetryPolicy::delayMilliseconds',
            '--index=.agent-map/php-symbols.json',
            '--format=json',
        ]);
        $decoded = $this->json($result->stdout, 'exact method scope');
        $target = $decoded['target'] ?? null;
        if (
            !is_array($target)
            || ($target['kind'] ?? null) !== 'method'
            || ($target['label'] ?? null) !== 'Fixture\\RetryPolicy::delayMilliseconds'
        ) {
            throw new DogfoodFailure('regression', 'Exact method scope did not resolve Fixture\\RetryPolicy::delayMilliseconds.');
        }
    }

    private function discoverEnglish(): void
    {
        $result = $this->mustRun([
            'vendor/bin/agent-map',
            'search',
            'How is the delay before retrying a timed out request calculated?',
            '--root=.',
            '--index=.agent-map/php-symbols.json',
            '--database=.agent-map/search.sqlite',
            '--format=json',
            '--limit=5',
        ]);
        $this->assertContains($result->stdout, 'RetryPolicy', 'English behavior search result');
    }

    private function discoverGerman(): void
    {
        $query = 'Wie wird die Wartezeit vor einem erneuten Versuch nach einer Zeitüberschreitung berechnet?';
        $result = $this->mustRun([
            'vendor/bin/agent-map',
            'search',
            $query,
            '--root=.',
            '--index=.agent-map/php-symbols.json',
            '--database=.agent-map/search.sqlite',
            '--format=json',
            '--limit=5',
        ]);
        $this->assertContains($result->stdout, 'RetryPolicy', 'German behavior search result');
        $this->assertContains($result->command[2] ?? '', 'Zeitüberschreitung', 'unmodified German query argument');
    }

    private function discoverNoAnswer(): void
    {
        $result = $this->mustRun([
            'vendor/bin/agent-map',
            'query',
            'NonexistentQuantumLedger',
            '--index=.agent-map/php-symbols.json',
            '--format=json',
        ]);
        if (str_contains($result->stdout, 'Fixture\\')) {
            throw new DogfoodFailure('regression', 'No-answer query returned a known fixture symbol.');
        }
    }

    private function scaffold(): void
    {
        $this->mustRun(['vendor/bin/agent-loop', 'init', 'scaffold']);
        $this->assertFile($this->consumerRoot . '/todo/cards/DEMO-1.md');
        $this->assertFile($this->consumerRoot . '/tasks/DEMO-1.md');
    }

    private function ephemeralWorkflow(): void
    {
        $this->mustRun([
            'vendor/bin/agent-loop',
            'workflow',
            'plan',
            'EXP-1',
            '--by',
            'release-set-gate',
            '--file',
            'src/RetryPolicy.php',
            '--goal',
            'Exercise an isolated experiment.',
            '--validation',
            'composer test',
            '--ephemeral',
        ]);
        $status = $this->mustRun([
            'vendor/bin/agent-loop',
            'workflow',
            'status',
            'EXP-1',
            '--format=json',
        ]);
        $json = $this->json($status->stdout, 'ephemeral workflow status');
        $manifest = $json['manifest'] ?? null;
        if (!is_array($manifest) || ($manifest['mode'] ?? null) !== 'ephemeral') {
            throw new DogfoodFailure('regression', 'Ephemeral task was not projected as ephemeral.');
        }
        $runId = $manifest['run_id'] ?? null;
        if (!is_string($runId) || !str_starts_with($runId, 'session:')) {
            throw new DogfoodFailure('contract_gap', 'Ephemeral status has no session run identity.');
        }

        $this->mustRun(['vendor/bin/agent-loop', 'verify']);
        $this->mustRun([
            'vendor/bin/agent-loop',
            'session',
            'close',
            substr($runId, strlen('session:')),
            '--status',
            'dropped',
        ]);
    }

    private function governedPlan(): void
    {
        $this->mustRun([
            'vendor/bin/agent-loop',
            'workflow',
            'plan',
            'DEMO-1',
            '--by',
            'release-set-gate',
            '--file',
            'src/RetryPolicy.php',
            '--goal',
            'Double the deterministic retry delay.',
            '--behavior-anchor',
            'request timeout -> RetryPolicy delay -> caller-observed wait',
            '--validation',
            'composer test',
        ]);
        $status = $this->status('DEMO-1');
        $this->assertManifestState($status, 'incomplete');
        $this->assertReferenceState($status, 'work_brief', 'candidate');
    }

    private function governedApprove(): void
    {
        $this->mustRun([
            'vendor/bin/agent-loop',
            'workflow',
            'approve',
            'DEMO-1',
            '--by',
            'release-set-gate',
        ]);
        $status = $this->status('DEMO-1');
        $this->assertReferenceState($status, 'approval', 'current');
        $this->assertReferenceState($status, 'recall', 'compiled');
        $this->artifact($this->consumerRoot . '/recall/DEMO-1/meta.json');
    }

    private function governedExecute(): void
    {
        $this->mustRun([PHP_BINARY, 'tools/apply-change.php']);
        $this->mustRun([
            'vendor/bin/agent-map',
            'refresh',
            '--root=.',
            '--index=.agent-map/php-symbols.json',
            '--out=.agent-map/php-symbols.json',
        ]);
        $this->mustRun([
            'vendor/bin/agent-map',
            'search-index',
            'refresh',
            '--root=.',
            '--index=.agent-map/php-symbols.json',
            '--database=.agent-map/search.sqlite',
        ]);
        $this->mustRun([
            'vendor/bin/agent-loop',
            'workflow',
            'approve',
            'DEMO-1',
            '--by',
            'release-set-gate',
        ]);
        $this->artifact($this->consumerRoot . '/src/RetryPolicy.php');
        $this->artifact($this->consumerRoot . '/.agent-map/php-symbols.json');
    }

    private function governedValidate(): void
    {
        $this->mustRun(['composer', 'test']);
        $this->mustRun([
            'vendor/bin/agent-loop',
            'session',
            'validation',
            'record',
            'DEMO-1',
            '--brief-revision',
            '1',
            '--command',
            'composer test',
            '--status',
            'passed',
            '--exit-code',
            '0',
            '--duration-ms',
            '0',
            '--by',
            'release-set-gate',
        ]);
    }

    private function governedReview(): void
    {
        $this->mustRun(
            ['vendor/bin/agent-loop', 'review', 'blindspots', 'DEMO-1'],
            [0, 1],
        );
        $this->mustRun([
            'vendor/bin/agent-loop',
            'session',
            'checkpoint',
            'DEMO-1',
            '--title',
            'Release-set review',
            '--body',
            'The deterministic blind-spot report was inspected by the release-set gate.',
        ]);
        $this->mustRun(['vendor/bin/agent-loop', 'review', 'blindspots', 'DEMO-1']);
        $this->artifact($this->consumerRoot . '/recall/DEMO-1/reviews/DEMO-1.blindspots.json');
    }

    private function governedLearn(): void
    {
        $this->mustRun([
            'vendor/bin/agent-loop',
            'session',
            'learning',
            'decide',
            'DEMO-1',
            '--status',
            'no_durable_learning',
            '--by',
            'release-set-gate',
        ]);
    }

    private function manifestRecovery(): void
    {
        $stale = $this->status('DEMO-1');
        $storage = $stale['storage'] ?? null;
        if (!is_array($storage) || ($storage['state'] ?? null) !== 'stale') {
            throw new DogfoodFailure('regression', 'Direct validation/review/learning mutations did not make the stored manifest visibly stale.');
        }

        $this->mustRun([
            'vendor/bin/agent-loop',
            'workflow',
            'manifest',
            'DEMO-1',
            '--write',
            '--format=json',
        ]);
        $current = $this->status('DEMO-1');
        $storage = $current['storage'] ?? null;
        if (!is_array($storage) || ($storage['state'] ?? null) !== 'current') {
            throw new DogfoodFailure('regression', 'Explicit manifest repair did not restore current state.');
        }
    }

    private function governedClose(): void
    {
        $this->mustRun(['vendor/bin/agent-loop', 'verify']);
        $this->mustRun([
            'vendor/bin/agent-loop',
            'workflow',
            'close',
            'DEMO-1',
            '--status',
            'done',
        ]);
        $status = $this->status('DEMO-1');
        $this->assertManifestState($status, 'complete');
        $this->assertReferenceState($status, 'session', 'done');
        $this->artifact($this->consumerRoot . '/.agent-loop/runs/DEMO-1/manifest.json');
    }

    /** @return array<string, mixed> */
    private function status(string $taskId): array
    {
        $result = $this->mustRun([
            'vendor/bin/agent-loop',
            'workflow',
            'status',
            $taskId,
            '--format=json',
        ]);

        return $this->json($result->stdout, 'workflow status for ' . $taskId);
    }

    /** @param array<string, mixed> $status */
    private function assertManifestState(array $status, string $expected): void
    {
        $manifest = $status['manifest'] ?? null;
        if (!is_array($manifest) || ($manifest['state'] ?? null) !== $expected) {
            throw new DogfoodFailure('regression', 'Expected manifest state ' . $expected . '.');
        }
    }

    /** @param array<string, mixed> $status */
    private function assertReferenceState(array $status, string $reference, string $expected): void
    {
        $manifest = $status['manifest'] ?? null;
        $references = is_array($manifest) ? ($manifest['references'] ?? null) : null;
        $actual = is_array($references) && is_array($references[$reference] ?? null)
            ? ($references[$reference]['state'] ?? null)
            : null;
        if ($actual !== $expected) {
            throw new DogfoodFailure(
                'regression',
                sprintf('Expected %s reference state %s, got %s.', $reference, $expected, is_scalar($actual) ? (string) $actual : get_debug_type($actual)),
            );
        }
    }

    /**
     * @param list<int> $allowedExitCodes
     */
    private function mustRun(array $command, array $allowedExitCodes = [0]): CommandResult
    {
        $result = $this->runCommand($command);
        if (!in_array($result->exitCode, $allowedExitCodes, true)) {
            throw new DogfoodFailure(
                'regression',
                sprintf(
                    'Command failed with exit %d: %s. See %s and %s.',
                    $result->exitCode,
                    $result->toArray()['display'],
                    $result->stdoutLog,
                    $result->stderrLog,
                ),
            );
        }

        return $result;
    }

    /** @param list<string> $command */
    private function runCommand(array $command): CommandResult
    {
        ++$this->commandNumber;
        $base = sprintf('%s-%02d', $this->safe($this->currentScenario), $this->commandNumber);
        $stdoutPath = $this->logRoot . '/' . $base . '.stdout.log';
        $stderrPath = $this->logRoot . '/' . $base . '.stderr.log';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutPath, 'w'],
            2 => ['file', $stderrPath, 'w'],
        ];
        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment['COMPOSER_NO_INTERACTION'] = '1';
        $environment['COMPOSER_ALLOW_SUPERUSER'] = '1';

        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, $this->consumerRoot, $environment);
        if (!is_resource($process)) {
            throw new DogfoodFailure('fixture_error', 'Unable to start command: ' . implode(' ', $command));
        }
        fclose($pipes[0]);

        $deadline = microtime(true) + 900.0;
        $exitCode = null;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                $exitCode = 124;
                break;
            }
            usleep(100_000);
        } while (true);
        $closed = proc_close($process);
        if ($exitCode === null || $exitCode === -1) {
            $exitCode = $closed;
        }

        $stdout = file_get_contents($stdoutPath);
        $stderr = file_get_contents($stderrPath);
        $result = new CommandResult(
            $command,
            is_int($exitCode) ? $exitCode : 1,
            is_string($stdout) ? $stdout : '',
            is_string($stderr) ? $stderr : '',
            $this->relativeWorkspace($stdoutPath),
            $this->relativeWorkspace($stderrPath),
        );
        $this->currentCommands[] = $result->toArray();

        return $result;
    }

    private function scenario(string $id, callable $scenario): void
    {
        $this->currentScenario = $id;
        $this->currentCommands = [];
        $this->currentArtifacts = [];
        $this->commandNumber = 0;

        try {
            $scenario();
            $this->scenarios[] = [
                'id' => $id,
                'status' => 'passed',
                'commands' => $this->currentCommands,
                'artifact_references' => $this->currentArtifacts,
                'failure_classification' => null,
            ];
        } catch (DogfoodFailure $failure) {
            $this->scenarios[] = [
                'id' => $id,
                'status' => 'failed',
                'commands' => $this->currentCommands,
                'artifact_references' => $this->currentArtifacts,
                'failure_classification' => $failure->classification,
                'failure' => $failure->getMessage(),
            ];

            throw $failure;
        } catch (Throwable $failure) {
            $wrapped = new DogfoodFailure('fixture_error', $failure->getMessage());
            $this->scenarios[] = [
                'id' => $id,
                'status' => 'failed',
                'commands' => $this->currentCommands,
                'artifact_references' => $this->currentArtifacts,
                'failure_classification' => $wrapped->classification,
                'failure' => $wrapped->getMessage(),
            ];

            throw $wrapped;
        }
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
        if (is_dir($this->candidateRoot . '/vendor')) {
            throw new DogfoodFailure('fixture_error', 'Candidate staging unexpectedly contains vendor/.');
        }
    }

    private function writeConsumerFiles(): void
    {
        $this->mkdir($this->artifactRoot);
        $this->mkdir($this->logRoot);
        $candidate = str_replace('\\', '/', $this->candidateRoot);
        $composer = [
            'name' => 'voku/release-set-consumer-fixture',
            'description' => 'Repository-neutral installed release-set dogfood fixture.',
            'type' => 'project',
            'require-dev' => [
                'voku/agent-loop' => 'dev-main',
            ],
            'repositories' => [[
                'type' => 'path',
                'url' => $candidate,
                'options' => [
                    'symlink' => false,
                    'versions' => ['voku/agent-loop' => 'dev-main'],
                ],
            ]],
            'autoload' => [
                'psr-4' => ['Fixture\\' => 'src/'],
            ],
            'scripts' => [
                'test' => 'php tests/run.php',
            ],
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
            'config' => [
                'allow-plugins' => false,
                'sort-packages' => true,
            ],
        ];
        $this->writeJson($this->consumerRoot . '/composer.json', $composer);
        file_put_contents(
            $this->consumerRoot . '/.gitignore',
            "/vendor/\n/.agent-map/\n/.agent-loop/\nsession_plan/\n/recall/\n/infra/doc/agent-learning/history/\n",
        );
        file_put_contents($this->consumerRoot . '/tools/autoload-probe.php', $this->autoloadProbe());
    }

    private function autoloadProbe(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$classes = [
    'voku/agent-loop' => voku\AgentLoop\Dispatcher::class,
    'voku/agent-kanban' => voku\AgentKanban\Cli\CliApplication::class,
    'voku/agent-session' => voku\AgentSession\SessionStore::class,
    'voku/agent-map' => voku\AgentMap\Cli\AgentMapApplication::class,
    'voku/agent-recall-compiler' => voku\AgentRecallCompiler\Command\CompileCommand::class,
    'voku/agent-learning' => voku\AgentLearning\LearningRootResolver::class,
];

$vendor = realpath(dirname(__DIR__) . '/vendor');
if (!is_string($vendor)) {
    fwrite(STDERR, "Consumer vendor directory is unavailable.\n");
    exit(1);
}
$vendor = str_replace('\\', '/', $vendor) . '/';
$files = [];
foreach ($classes as $package => $class) {
    if (!class_exists($class)) {
        fwrite(STDERR, "Missing class {$class} for {$package}.\n");
        exit(1);
    }
    $file = (new ReflectionClass($class))->getFileName();
    if (!is_string($file)) {
        fwrite(STDERR, "No source file for {$class}.\n");
        exit(1);
    }
    $normalized = str_replace('\\', '/', $file);
    if (!str_starts_with($normalized, $vendor)) {
        fwrite(STDERR, "Class {$class} loaded outside the consumer vendor tree: {$normalized}.\n");
        exit(1);
    }
    if (preg_match('~/vendor/voku/[^/]+/vendor/~', $normalized) === 1) {
        fwrite(STDERR, "Class {$class} loaded through a nested package vendor tree: {$normalized}.\n");
        exit(1);
    }
    $files[$package] = substr($normalized, strlen($vendor));
}
ksort($files, SORT_STRING);
echo json_encode([
    'schema_version' => '1.0',
    'result' => 'passed',
    'class_files' => $files,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
PHP;
    }

    /** @return array<string, array<string, string|null>> */
    private function resolvedAgentPackages(): array
    {
        $lock = $this->jsonFile($this->consumerRoot . '/composer.lock');
        $packages = [];
        foreach (['packages', 'packages-dev'] as $section) {
            $rows = $lock[$section] ?? [];
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (!is_array($row) || !is_string($row['name'] ?? null) || !str_starts_with($row['name'], 'voku/agent-')) {
                    continue;
                }
                $reference = null;
                if (is_array($row['source'] ?? null) && is_string($row['source']['reference'] ?? null)) {
                    $reference = $row['source']['reference'];
                } elseif (is_array($row['dist'] ?? null) && is_string($row['dist']['reference'] ?? null)) {
                    $reference = $row['dist']['reference'];
                }
                $source = $row['name'] === 'voku/agent-loop'
                    ? 'path-candidate'
                    : (is_array($row['dist'] ?? null) ? 'dist' : 'source');
                $packages[$row['name']] = [
                    'version' => is_string($row['version'] ?? null) ? $row['version'] : 'unknown',
                    'source' => $source,
                    'reference' => $reference,
                ];
            }
        }
        ksort($packages, SORT_STRING);

        return $packages;
    }

    /** @return list<string> */
    private function packageNames(): array
    {
        return [
            'voku/agent-kanban',
            'voku/agent-learning',
            'voku/agent-loop',
            'voku/agent-map',
            'voku/agent-recall-compiler',
            'voku/agent-session',
        ];
    }

    private function writeReport(): void
    {
        $report = [
            'schema_version' => self::REPORT_SCHEMA,
            'release_set' => is_file($this->consumerRoot . '/composer.lock') ? $this->resolvedAgentPackages() : [],
            'platform' => [
                'php' => PHP_VERSION,
                'php_sapi' => PHP_SAPI,
                'os_family' => PHP_OS_FAMILY,
                'composer' => $this->composerVersion(),
                'volatile_fields' => ['php', 'composer', 'os_family'],
            ],
            'scenarios' => $this->scenarios,
            'friction' => $this->friction,
            'result' => $this->failed ? 'failed' : 'passed',
        ];
        $directory = dirname($this->reportPath);
        $this->mkdir($directory);
        file_put_contents($this->reportPath, $this->canonicalJson($report));

        if (!$this->keepWorkspace && !$this->failed) {
            // Keep the report wherever the caller requested it, but remove the
            // potentially large consumer/vendor tree after a successful local run.
            if (!str_starts_with($this->reportPath, rtrim($this->workspace, '/') . '/')) {
                $this->removeTree($this->workspace);
            }
        }
    }

    private function composerVersion(): string
    {
        $temporaryOut = tempnam(sys_get_temp_dir(), 'composer-version-out-');
        $temporaryErr = tempnam(sys_get_temp_dir(), 'composer-version-err-');
        if (!is_string($temporaryOut) || !is_string($temporaryErr)) {
            return 'unknown';
        }
        $pipes = [];
        $process = proc_open(
            ['composer', '--version', '--no-ansi'],
            [0 => ['pipe', 'r'], 1 => ['file', $temporaryOut, 'w'], 2 => ['file', $temporaryErr, 'w']],
            $pipes,
            $this->repositoryRoot,
        );
        if (!is_resource($process)) {
            return 'unknown';
        }
        fclose($pipes[0]);
        proc_close($process);
        $version = trim((string) file_get_contents($temporaryOut));
        @unlink($temporaryOut);
        @unlink($temporaryErr);

        return $version === '' ? 'unknown' : $version;
    }

    private function artifact(string $path): void
    {
        $this->assertFile($path);
        $sha = hash_file('sha256', $path);
        if (!is_string($sha)) {
            throw new DogfoodFailure('fixture_error', 'Unable to hash artifact ' . $path . '.');
        }
        $this->currentArtifacts[] = [
            'path' => $this->relativeConsumer($path),
            'sha256' => 'sha256:' . $sha,
        ];
    }

    private function assertFile(string $path): void
    {
        if (!is_file($path)) {
            throw new DogfoodFailure('regression', 'Expected artifact does not exist: ' . $this->relativeConsumer($path));
        }
    }

    private function assertContains(string $haystack, string $needle, string $description): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new DogfoodFailure('regression', $description . ' does not contain ' . $needle . '.');
        }
    }

    /** @return array<string, mixed> */
    private function json(string $json, string $description): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DogfoodFailure('regression', 'Invalid JSON in ' . $description . ': ' . $exception->getMessage());
        }
        if (!is_array($decoded)) {
            throw new DogfoodFailure('regression', $description . ' is not a JSON object.');
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    private function jsonFile(string $path): array
    {
        $this->assertFile($path);

        return $this->json((string) file_get_contents($path), $this->relativeConsumer($path));
    }

    /** @param array<string, mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        file_put_contents($path, $this->canonicalJson($data));
    }

    /** @param array<string, mixed> $data */
    private function canonicalJson(array $data): string
    {
        return json_encode(
            $this->normalize($data),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    private function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }

    private function resetWorkspace(): void
    {
        if (is_dir($this->workspace)) {
            $this->removeTree($this->workspace);
        }
        $this->mkdir($this->workspace);
    }

    private function copyTree(string $source, string $destination): void
    {
        if (!is_dir($source)) {
            throw new DogfoodFailure('fixture_error', 'Source directory does not exist: ' . $source);
        }
        $this->mkdir($destination);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen(rtrim($source, DIRECTORY_SEPARATOR)) + 1);
            $target = $destination . '/' . str_replace('\\', '/', $relative);
            if ($item->isDir()) {
                $this->mkdir($target);
            } elseif (!copy($item->getPathname(), $target)) {
                throw new DogfoodFailure('fixture_error', 'Unable to copy fixture file: ' . $item->getPathname());
            }
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }

    private function mkdir(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0o775, true) && !is_dir($path)) {
            throw new DogfoodFailure('fixture_error', 'Unable to create directory: ' . $path);
        }
    }

    private function safe(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]+/', '-', $value) ?: 'scenario';
    }

    private function relativeWorkspace(string $path): string
    {
        return str_starts_with($path, rtrim($this->workspace, '/') . '/')
            ? substr($path, strlen(rtrim($this->workspace, '/')) + 1)
            : $path;
    }

    private function relativeConsumer(string $path): string
    {
        return str_starts_with($path, rtrim($this->consumerRoot, '/') . '/')
            ? substr($path, strlen(rtrim($this->consumerRoot, '/')) + 1)
            : $path;
    }
}

/** @return array{workspace: string, report: string, keep: bool} */
function parseOptions(array $argv, string $repositoryRoot): array
{
    $workspace = sys_get_temp_dir() . '/agent-loop-release-set-dogfood';
    $report = $repositoryRoot . '/build/release-set-report.json';
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

        fwrite(STDERR, 'Unknown option: ' . $argument . "\n");
        exit(2);
    }

    if ($workspace === '' || $report === '') {
        fwrite(STDERR, "--workspace and --report require non-empty paths.\n");
        exit(2);
    }

    return [
        'workspace' => $workspace,
        'report' => $report,
        'keep' => $keep,
    ];
}

$repositoryRoot = realpath(dirname(__DIR__));
if (!is_string($repositoryRoot)) {
    fwrite(STDERR, "Unable to resolve repository root.\n");
    exit(2);
}

$gate = new ReleaseSetDogfood($repositoryRoot, parseOptions($argv, $repositoryRoot));
exit($gate->run());
