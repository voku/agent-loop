<?php

declare(strict_types=1);

final class InstalledRefactorDogfoodFailure extends RuntimeException
{
}

final class InstalledRefactorLifecycleDogfood
{
    private string $loopCandidate;
    private string $consumer;

    /** @var list<array{command: string, exit: int, stdout_sha256: string, stderr_sha256: string}> */
    private array $commands = [];

    public function __construct(
        private readonly string $repositoryRoot,
        private readonly string $mapCandidate,
        private readonly string $workspace,
        private readonly string $reportPath,
    ) {
        $this->loopCandidate = $workspace . '/candidate-agent-loop';
        $this->consumer = $workspace . '/consumer';
    }

    public function run(): int
    {
        $this->removeTree($this->workspace);
        $this->mkdir($this->workspace);
        $this->stageLoopCandidate();
        $this->writeConsumer();

        $this->runCommand(['composer', 'update', '--no-interaction', '--prefer-dist', '--no-progress', '--no-ansi']);
        $this->runCommand(['git', 'init', '--initial-branch=main']);
        $this->runCommand(['git', 'config', 'user.name', 'Installed Refactor Dogfood']);
        $this->runCommand(['git', 'config', 'user.email', 'refactor-dogfood@example.invalid']);
        $this->runCommand(['git', 'add', 'composer.json', 'composer.lock', '.gitignore', 'src', 'tests']);
        $this->runCommand(['git', 'commit', '-m', 'fixture: initial refactor consumer']);

        $releaseSet = $this->resolvedPackages();
        $this->assertPathPackage($releaseSet, 'voku/agent-loop', 'dev-main');
        $this->assertPathPackage($releaseSet, 'voku/agent-map', '0.8.999');
        if (!isset($releaseSet['phpstan/phpstan'])) {
            throw new InstalledRefactorDogfoodFailure('Installed consumer is missing PHPStan semantic capability.');
        }

        $this->runCommand(['vendor/bin/agent-loop', 'init', 'scaffold', '--demo', '--agent=codex']);
        $this->runCommand([
            'vendor/bin/agent-loop', 'workflow', 'plan', 'DEMO-1',
            '--by', 'installed-refactor-dogfood',
            '--file', 'src/Greeter.php',
            '--file', 'src/Caller.php',
            '--goal', 'Rename Fixture\\Greeter::oldName to renamedMethod through the governed agent-map plan boundary.',
            '--behavior-anchor', 'Caller nullsafe dispatch -> Greeter method -> caller-observed greeting',
            '--validation', 'composer test',
            '--acceptance', 'No oldName token remains in src and composer test passes.',
        ]);
        $this->runCommand(['vendor/bin/agent-loop', 'workflow', 'approve', 'DEMO-1', '--by', 'installed-refactor-dogfood']);

        $enter = $this->json($this->runCommand([
            'vendor/bin/agent-loop', 'enter', 'DEMO-1', '--format=json', '--max-lines=40', '--max-bytes=4096',
        ])['stdout'], 'enter');
        if (($enter['mutation_ready'] ?? null) !== true) {
            throw new InstalledRefactorDogfoodFailure('Governed enter did not expose mutation readiness.');
        }

        // General enter intentionally prepares structural discovery only. Semantic rename planning is
        // operation-specific read-only owner work and therefore requests PHPStan explicitly here.
        $this->buildSemanticMap();
        $semanticMap = $this->jsonFile($this->consumer . '/.agent-loop/map/php-symbols.json');
        $backend = $semanticMap['backend'] ?? null;
        if (!is_string($backend) || !str_ends_with($backend, '+phpstan')) {
            throw new InstalledRefactorDogfoodFailure('Semantic rename preparation did not produce a PHPStan-backed Map.');
        }

        $capabilities = $this->json(
            $this->runCommand(['vendor/bin/agent-map', 'rename-capabilities', '--format=json'])['stdout'],
            'rename capabilities',
        );
        $types = [];
        foreach (is_array($capabilities['capabilities'] ?? null) ? $capabilities['capabilities'] : [] as $row) {
            if (is_array($row) && is_string($row['plan_type'] ?? null)) {
                $types[] = $row['plan_type'];
            }
        }
        sort($types, SORT_STRING);
        if ($types !== ['class_rename_plan', 'function_rename_plan', 'method_rename_plan', 'property_rename_plan']) {
            throw new InstalledRefactorDogfoodFailure('Installed owner did not expose all four governed rename contracts.');
        }

        $planRun = $this->runCommand([
            'vendor/bin/agent-map', 'rename-plan', 'Fixture\\Greeter::oldName', 'renamedMethod',
            '--index=.agent-loop/map/php-symbols.json', '--format=json',
        ]);
        $plan = $this->json($planRun['stdout'], 'owner-generated method rename plan');
        if (($plan['type'] ?? null) !== 'method_rename_plan'
            || ($plan['contract_version'] ?? null) !== '1.0'
            || ($plan['status'] ?? null) !== 'safe'
            || count(is_array($plan['edits'] ?? null) ? $plan['edits'] : []) < 2
        ) {
            throw new InstalledRefactorDogfoodFailure('Owner-generated method plan was not safe multi-edit contract 1.0 evidence.');
        }

        $bundle = $this->consumer . '/.agent-loop/edit/DEMO-1';
        $this->mkdir($bundle);
        $planPath = $bundle . '/method-rename-plan.json';
        file_put_contents($planPath, $planRun['stdout']);
        $before = [
            'src/Greeter.php' => $this->sha($this->consumer . '/src/Greeter.php'),
            'src/Caller.php' => $this->sha($this->consumer . '/src/Caller.php'),
        ];

        $this->runCommand([
            'vendor/bin/agent-loop', 'edit', 'refactor', '.agent-loop/edit/DEMO-1/method-rename-plan.json',
            '--task=DEMO-1', '--map-index=.agent-loop/map/php-symbols.json', '--map-root=.',
            '--output-dir=.agent-loop/edit/DEMO-1/dry-run', '--dry-run',
        ]);
        $dryRun = $this->jsonFile($bundle . '/dry-run/execution.json');
        if (($dryRun['status'] ?? null) !== 'prepared' || ($dryRun['runner']['dry_run'] ?? null) !== true) {
            throw new InstalledRefactorDogfoodFailure('Refactor dry-run did not publish prepared evidence.');
        }
        foreach ($before as $path => $hash) {
            if ($this->sha($this->consumer . '/' . $path) !== $hash) {
                throw new InstalledRefactorDogfoodFailure('Refactor dry-run changed source: ' . $path);
            }
        }

        $this->runCommand([
            'vendor/bin/agent-loop', 'edit', 'refactor', '.agent-loop/edit/DEMO-1/method-rename-plan.json',
            '--task=DEMO-1', '--map-index=.agent-loop/map/php-symbols.json', '--map-root=.',
            '--output-dir=.agent-loop/edit/DEMO-1',
        ]);
        $apply = $this->jsonFile($bundle . '/execution.json');
        if (($apply['status'] ?? null) !== 'runner_succeeded' || ($apply['runner']['dry_run'] ?? null) !== false) {
            throw new InstalledRefactorDogfoodFailure('Refactor apply did not publish successful mutation evidence.');
        }
        foreach (['src/Greeter.php', 'src/Caller.php'] as $path) {
            $source = file_get_contents($this->consumer . '/' . $path);
            if (!is_string($source) || str_contains($source, 'oldName') || !str_contains($source, 'renamedMethod')) {
                throw new InstalledRefactorDogfoodFailure('Rename did not fully rewrite ' . $path . '.');
            }
            $this->runCommand([PHP_BINARY, '-l', $path]);
        }

        // Derived semantic evidence must describe the rewritten source before edit verification.
        $this->buildSemanticMap();
        $this->runCommand([
            'vendor/bin/agent-loop', 'edit', 'refactor', 'verify',
            '--bundle=.agent-loop/edit/DEMO-1',
            '--map-index=.agent-loop/map/php-symbols.json',
            '--map-root=.',
        ]);
        $refactorVerification = $this->jsonFile($bundle . '/verification-result.json');
        if (($refactorVerification['status'] ?? null) !== 'passed'
            || ($refactorVerification['kind'] ?? null) !== 'rename_plan_verification'
        ) {
            throw new InstalledRefactorDogfoodFailure('Deterministic refactor verification did not pass.');
        }

        $this->runCommand(['composer', 'test']);
        $this->runCommand([
            'vendor/bin/agent-loop', 'session', 'validation', 'record', 'DEMO-1',
            '--contract-revision', '1', '--command', 'composer test', '--status', 'passed',
            '--exit-code', '0', '--duration-ms', '0', '--by', 'installed-refactor-dogfood',
        ]);
        $this->runCommand(['vendor/bin/agent-loop', 'review', 'blindspots', 'DEMO-1'], [0, 1]);
        $this->runCommand([
            'vendor/bin/agent-loop', 'session', 'checkpoint', 'DEMO-1',
            '--title', 'Installed refactor review',
            '--body', 'Reviewed owner-generated rename evidence, deterministic verification, and validation output.',
        ]);
        $this->runCommand(['vendor/bin/agent-loop', 'review', 'blindspots', 'DEMO-1']);
        $this->runCommand([
            'vendor/bin/agent-loop', 'workflow', 'learn', 'DEMO-1',
            '--status', 'no_durable_learning', '--by', 'installed-refactor-dogfood',
            '--reason', 'The installed refactor proof added no reusable project-specific guidance.',
        ]);
        $this->runCommand(['vendor/bin/agent-loop', 'verify', '--task-id=DEMO-1']);

        $verified = $this->status('DEMO-1');
        $reviewDigest = $verified['manifest']['references']['review']['source']['sha256'] ?? null;
        if (!is_string($reviewDigest) || !str_starts_with($reviewDigest, 'sha256:')) {
            throw new InstalledRefactorDogfoodFailure('Verified lifecycle did not expose exact review identity.');
        }

        $premature = $this->json(
            $this->runCommand(['vendor/bin/agent-loop', 'finish', 'DEMO-1', '--format=json'], [1])['stdout'],
            'premature finish',
        );
        if (($premature['complete'] ?? null) !== false) {
            throw new InstalledRefactorDogfoodFailure('Lifecycle completed before review acknowledgement.');
        }

        $this->runCommand([
            'vendor/bin/agent-loop', 'finish', 'DEMO-1',
            '--reviewed-report-sha256', $reviewDigest, '--by', 'installed-refactor-dogfood',
        ]);
        $complete = $this->status('DEMO-1', 'complete');
        $final = $this->json(
            $this->runCommand(['vendor/bin/agent-loop', 'finish', 'DEMO-1', '--format=json'])['stdout'],
            'completed finish',
        );
        if (($complete['manifest']['state'] ?? null) !== 'complete'
            || ($final['complete'] ?? null) !== true
            || ($final['next_action'] ?? null) !== 'none'
        ) {
            throw new InstalledRefactorDogfoodFailure('Governed refactor lifecycle did not converge to complete.');
        }

        $this->writeJson($this->reportPath, [
            'schema_version' => '1.0',
            'result' => 'passed',
            'claim' => 'owner_generated_method_rename_installed_consumer_governed_finish',
            'task_id' => 'DEMO-1',
            'release_set' => $releaseSet,
            'semantic_backend' => $backend,
            'rename_capabilities' => $types,
            'plan' => [
                'type' => $plan['type'],
                'contract_version' => $plan['contract_version'],
                'target_id' => $plan['target_id'] ?? null,
                'edit_count' => count($plan['edits']),
                'sha256' => 'sha256:' . hash('sha256', $planRun['stdout']),
            ],
            'dry_run' => $dryRun,
            'apply' => $apply,
            'refactor_verification' => $refactorVerification,
            'review_sha256' => $reviewDigest,
            'final_state' => $complete['manifest']['state'] ?? null,
            'final_next_action' => $final['next_action'] ?? null,
            'commands' => $this->commands,
        ]);

        echo "Installed refactor lifecycle dogfood: PASSED\n";
        echo 'Report: ' . $this->reportPath . "\n";

        return 0;
    }

    private function buildSemanticMap(): void
    {
        $this->runCommand([
            'vendor/bin/agent-map', 'build', '--root=.',
            '--paths=src/Greeter.php,src/Caller.php',
            '--backend=phpstan', '--phpstan-memory-limit=512M',
            '--out=.agent-loop/map/php-symbols.json',
        ]);
    }

    private function stageLoopCandidate(): void
    {
        $this->mkdir($this->loopCandidate);
        foreach (['composer.json', 'LICENSE'] as $file) {
            $source = $this->repositoryRoot . '/' . $file;
            if (is_file($source) && !copy($source, $this->loopCandidate . '/' . $file)) {
                throw new InstalledRefactorDogfoodFailure('Unable to stage agent-loop file: ' . $file);
            }
        }
        foreach (['src', 'bin', 'docs/agents'] as $directory) {
            $this->copyTree($this->repositoryRoot . '/' . $directory, $this->loopCandidate . '/' . $directory);
        }
    }

    private function writeConsumer(): void
    {
        $this->mkdir($this->consumer . '/src');
        $this->mkdir($this->consumer . '/tests');
        $this->writeJson($this->consumer . '/composer.json', [
            'name' => 'voku/installed-refactor-consumer',
            'type' => 'project',
            'require-dev' => [
                'phpstan/phpstan' => '^2.2',
                'voku/agent-loop' => 'dev-main',
            ],
            'repositories' => [
                [
                    'type' => 'path',
                    'url' => str_replace('\\', '/', $this->loopCandidate),
                    'options' => ['symlink' => false, 'versions' => ['voku/agent-loop' => 'dev-main']],
                ],
                [
                    'type' => 'path',
                    'url' => str_replace('\\', '/', $this->mapCandidate),
                    'options' => ['symlink' => false, 'versions' => ['voku/agent-map' => '0.8.999']],
                ],
            ],
            'autoload' => ['psr-4' => ['Fixture\\' => 'src/']],
            'scripts' => ['test' => 'php tests/run.php'],
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
            'config' => ['allow-plugins' => false, 'sort-packages' => true],
        ]);
        file_put_contents($this->consumer . '/.gitignore', "/vendor/\n/.agent-loop/\n");
        file_put_contents($this->consumer . '/src/Greeter.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Fixture;

final class Greeter
{
    public function oldName(string $name): string
    {
        return 'Hello ' . $name;
    }
}
PHP
        );
        file_put_contents($this->consumer . '/src/Caller.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Fixture;

final class Caller
{
    public function run(?Greeter $greeter): string
    {
        return $greeter?->oldName('map') ?? 'missing';
    }
}
PHP
        );
        file_put_contents($this->consumer . '/tests/run.php', <<<'PHP'
<?php

declare(strict_types=1);

use Fixture\Caller;
use Fixture\Greeter;

require dirname(__DIR__) . '/vendor/autoload.php';

$greeter = new Greeter();
$actual = (new Caller())->run($greeter);
if ($actual !== 'Hello map') {
    fwrite(STDERR, 'Unexpected renamed-method behavior: ' . $actual . "\n");
    exit(1);
}
if (!method_exists($greeter, 'renamedMethod') || method_exists($greeter, 'oldName')) {
    fwrite(STDERR, "Method rename did not publish the expected runtime API.\n");
    exit(1);
}

echo "installed refactor validation passed\n";
PHP
        );
    }

    /** @return array<string, array{version: string, source_type: string}> */
    private function resolvedPackages(): array
    {
        $lock = $this->jsonFile($this->consumer . '/composer.lock');
        $packages = [];
        foreach (['packages', 'packages-dev'] as $section) {
            foreach (is_array($lock[$section] ?? null) ? $lock[$section] : [] as $row) {
                if (!is_array($row) || !is_string($row['name'] ?? null)) {
                    continue;
                }
                $dist = is_array($row['dist'] ?? null) ? $row['dist'] : [];
                $source = is_array($row['source'] ?? null) ? $row['source'] : [];
                $packages[$row['name']] = [
                    'version' => is_string($row['version'] ?? null) ? $row['version'] : 'unknown',
                    'source_type' => is_string($dist['type'] ?? null)
                        ? $dist['type']
                        : (is_string($source['type'] ?? null) ? $source['type'] : 'unknown'),
                ];
            }
        }
        ksort($packages, SORT_STRING);

        return $packages;
    }

    /** @param array<string, array{version: string, source_type: string}> $packages */
    private function assertPathPackage(array $packages, string $name, string $version): void
    {
        $package = $packages[$name] ?? null;
        if (!is_array($package) || $package['version'] !== $version || $package['source_type'] !== 'path') {
            throw new InstalledRefactorDogfoodFailure('Expected path candidate ' . $name . '@' . $version . '.');
        }
    }

    /** @param list<string> $command @param list<int> $allowed @return array{exit: int, stdout: string, stderr: string} */
    private function runCommand(array $command, array $allowed = [0]): array
    {
        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
        $environment['COMPOSER_NO_INTERACTION'] = '1';
        $environment['COMPOSER_ALLOW_SUPERUSER'] = '1';
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->consumer,
            $environment,
        );
        if (!is_resource($process)) {
            throw new InstalledRefactorDogfoodFailure('Unable to start command: ' . implode(' ', $command));
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $stdout = is_string($stdout) ? $stdout : '';
        $stderr = is_string($stderr) ? $stderr : '';
        $this->commands[] = [
            'command' => implode(' ', $command),
            'exit' => $exit,
            'stdout_sha256' => 'sha256:' . hash('sha256', $stdout),
            'stderr_sha256' => 'sha256:' . hash('sha256', $stderr),
        ];
        if (!in_array($exit, $allowed, true)) {
            throw new InstalledRefactorDogfoodFailure(sprintf(
                "Command failed (%d): %s\nSTDOUT:\n%s\nSTDERR:\n%s",
                $exit,
                implode(' ', $command),
                $stdout,
                $stderr,
            ));
        }

        return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @return array<string, mixed> */
    private function status(string $taskId, ?string $expect = null): array
    {
        $command = ['vendor/bin/agent-loop', 'workflow', 'status', $taskId, '--format=json'];
        if ($expect !== null) {
            $command[] = '--expect=' . $expect;
        }

        return $this->json($this->runCommand($command)['stdout'], 'workflow status');
    }

    /** @return array<string, mixed> */
    private function jsonFile(string $path): array
    {
        if (!is_file($path)) {
            throw new InstalledRefactorDogfoodFailure('Expected JSON file is missing: ' . $path);
        }

        return $this->json((string) file_get_contents($path), $path);
    }

    /** @return array<string, mixed> */
    private function json(string $raw, string $label): array
    {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new InstalledRefactorDogfoodFailure($label . ' did not decode to an object.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $payload */
    private function writeJson(string $path, array $payload): void
    {
        $this->mkdir(dirname($path));
        file_put_contents($path, json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n");
    }

    private function sha(string $path): string
    {
        $hash = hash_file('sha256', $path);
        if (!is_string($hash)) {
            throw new InstalledRefactorDogfoodFailure('Unable to hash file: ' . $path);
        }

        return 'sha256:' . $hash;
    }

    private function mkdir(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0o775, true) && !is_dir($path)) {
            throw new InstalledRefactorDogfoodFailure('Unable to create directory: ' . $path);
        }
    }

    private function copyTree(string $source, string $destination): void
    {
        if (!is_dir($source)) {
            throw new InstalledRefactorDogfoodFailure('Source directory is missing: ' . $source);
        }
        $source = rtrim($source, '/\\');
        $this->mkdir($destination);
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        ) as $item) {
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $target = $destination . '/' . str_replace('\\', '/', $relative);
            if ($item->isDir()) {
                $this->mkdir($target);
                continue;
            }
            $this->mkdir(dirname($target));
            if (!copy($item->getPathname(), $target)) {
                throw new InstalledRefactorDogfoodFailure('Unable to copy: ' . $item->getPathname());
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
}

function dogfoodOption(array $argv, string $name, ?string $default = null): string
{
    $prefix = '--' . $name . '=';
    foreach (array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, $prefix)) {
            continue;
        }
        $value = substr($argument, strlen($prefix));
        if ($value !== '') {
            return $value;
        }
    }
    if ($default !== null) {
        return $default;
    }

    throw new InvalidArgumentException('--' . $name . ' is required.');
}

$root = dirname(__DIR__);
$workspace = dogfoodOption($argv, 'workspace', sys_get_temp_dir() . '/agent-loop-installed-refactor-' . bin2hex(random_bytes(4)));
$report = dogfoodOption($argv, 'report', $root . '/build/installed-refactor-lifecycle.json');
$mapCandidate = dogfoodOption($argv, 'agent-map');

try {
    exit((new InstalledRefactorLifecycleDogfood($root, $mapCandidate, $workspace, $report))->run());
} catch (Throwable $exception) {
    fwrite(STDERR, 'Installed refactor lifecycle dogfood failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
