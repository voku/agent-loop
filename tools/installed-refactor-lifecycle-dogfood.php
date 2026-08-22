<?php

declare(strict_types=1);

final class InstalledRefactorDogfoodFailure extends RuntimeException
{
}

final class InstalledRefactorLifecycleDogfood
{
    private string $candidateLoop;
    private string $consumer;

    /** @var list<array{command: string, exit: int, stdout_sha256: string, stderr_sha256: string}> */
    private array $commands = [];

    public function __construct(
        private readonly string $repositoryRoot,
        private readonly string $agentMapCandidate,
        private readonly string $workspace,
        private readonly string $report,
    ) {
        $this->candidateLoop = $workspace . '/candidate-agent-loop';
        $this->consumer = $workspace . '/consumer';
    }

    public function run(): int
    {
        $this->removeTree($this->workspace);
        $this->mkdir($this->workspace);
        $this->stageAgentLoop();
        $this->createConsumer();

        $this->must(['composer', 'update', '--no-interaction', '--prefer-dist', '--no-progress', '--no-ansi']);
        $this->must(['git', 'init', '--initial-branch=main']);
        $this->must(['git', 'config', 'user.name', 'Installed Refactor Dogfood']);
        $this->must(['git', 'config', 'user.email', 'refactor-dogfood@example.invalid']);
        $this->must(['git', 'add', 'composer.json', 'composer.lock', '.gitignore', 'src', 'tests']);
        $this->must(['git', 'commit', '-m', 'fixture: initial refactor consumer']);

        $packages = $this->resolvedPackages();
        $this->assertPathPackage($packages, 'voku/agent-loop', 'dev-main');
        $this->assertPathPackage($packages, 'voku/agent-map', '0.8.999');
        if (!isset($packages['phpstan/phpstan'])) {
            throw new InstalledRefactorDogfoodFailure('Installed consumer is missing PHPStan semantic capability.');
        }

        $this->must(['vendor/bin/agent-loop', 'init', 'scaffold', '--demo', '--agent=codex']);
        $this->must([
            'vendor/bin/agent-loop', 'workflow', 'plan', 'DEMO-1',
            '--by', 'installed-refactor-dogfood',
            '--file', 'src/Greeter.php',
            '--file', 'src/Caller.php',
            '--goal', 'Rename Fixture\\Greeter::oldName to renamedMethod through the governed agent-map plan boundary.',
            '--behavior-anchor', 'Caller nullsafe dispatch -> Greeter method -> caller-observed greeting',
            '--validation', 'composer test',
            '--acceptance', 'No oldName token remains in src and composer test passes.',
        ]);
        $this->must(['vendor/bin/agent-loop', 'workflow', 'approve', 'DEMO-1', '--by', 'installed-refactor-dogfood']);

        $entered = $this->json($this->must([
            'vendor/bin/agent-loop', 'enter', 'DEMO-1', '--format=json', '--max-lines=40', '--max-bytes=4096',
        ])['stdout'], 'enter');
        if (($entered['mutation_ready'] ?? null) !== true) {
            throw new InstalledRefactorDogfoodFailure('Governed enter did not expose mutation readiness.');
        }

        // `enter` intentionally reconciles lightweight structural discovery.
        // Rename planning is an operation-specific semantic capability, so ask
        // the read-only owner for an explicit PHPStan snapshot of this Contract scope.
        $this->must([
            'vendor/bin/agent-map', 'build', '--root=.',
            '--paths=src/Greeter.php,src/Caller.php',
            '--backend=phpstan', '--phpstan-memory-limit=512M',
            '--out=.agent-loop/map/php-symbols.json',
        ]);
        $semanticMap = $this->jsonFile($this->consumer . '/.agent-loop/map/php-symbols.json');
        $semanticBackend = $semanticMap['backend'] ?? null;
        if (!is_string($semanticBackend) || !str_ends_with($semanticBackend, '+phpstan')) {
            throw new InstalledRefactorDogfoodFailure('Explicit semantic Map preparation did not produce a PHPStan-backed snapshot.');
        }

        $capabilities = $this->json($this->must([
            'vendor/bin/agent-map', 'rename-capabilities', '--format=json',
        ])['stdout'], 'rename capabilities');
        $types = [];
        foreach (is_array($capabilities['capabilities'] ?? null) ? $capabilities['capabilities'] : [] as $capability) {
            if (is_array($capability) && is_string($capability['plan_type'] ?? null)) {
                $types[] = $capability['plan_type'];
            }
        }
        sort($types, SORT_STRING);
        $expectedTypes = ['class_rename_plan', 'function_rename_plan', 'method_rename_plan', 'property_rename_plan'];
        if ($types !== $expectedTypes) {
            throw new InstalledRefactorDogfoodFailure('Installed agent-map did not expose the four governed rename contracts.');
        }

        $planResult = $this->must([
            'vendor/bin/agent-map', 'rename-plan', 'Fixture\\Greeter::oldName', 'renamedMethod',
            '--index=.agent-loop/map/php-symbols.json', '--format=json',
        ]);
        $plan = $this->json($planResult['stdout'], 'method rename plan');
        if (($plan['type'] ?? null) !== 'method_rename_plan'
            || ($plan['contract_version'] ?? null) !== '1.0'
            || ($plan['status'] ?? null) !== 'safe'
            || count(is_array($plan['edits'] ?? null) ? $plan['edits'] : []) < 2
        ) {
            throw new InstalledRefactorDogfoodFailure('Installed agent-map did not publish a safe multi-edit method rename plan.');
        }

        $editRoot = $this->consumer . '/.agent-loop/edit/DEMO-1';
        $this->mkdir($editRoot);
        $planPath = $editRoot . '/method-rename-plan.json';
        file_put_contents($planPath, $planResult['stdout']);
        $before = [
            'src/Greeter.php' => $this->sha($this->consumer . '/src/Greeter.php'),
            'src/Caller.php' => $this->sha($this->consumer . '/src/Caller.php'),
        ];

        $this->must([
            'vendor/bin/agent-loop', 'edit', 'refactor', '.agent-loop/edit/DEMO-1/method-rename-plan.json',
            '--task=DEMO-1', '--map-index=.agent-loop/map/php-symbols.json', '--map-root=.',
            '--output-dir=.agent-loop/edit/DEMO-1/dry-run', '--dry-run',
        ]);
        $dryRun = $this->jsonFile($editRoot . '/dry-run/execution.json');
        if (($dryRun['status'] ?? null) !== 'prepared' || ($dryRun['runner']['dry_run'] ?? null) !== true) {
            throw new InstalledRefactorDogfoodFailure('Refactor dry-run did not publish prepared evidence.');
        }
        foreach ($before as $relative => $hash) {
            if ($this->sha($this->consumer . '/' . $relative) !== $hash) {
                throw new InstalledRefactorDogfoodFailure('Refactor dry-run changed ' . $relative . '.');
            }
        }

        $this->must([
            'vendor/bin/agent-loop', 'edit', 'refactor', '.agent-loop/edit/DEMO-1/method-rename-plan.json',
            '--task=DEMO-1', '--map-index=.agent-loop/map/php-symbols.json', '--map-root=.',
            '--output-dir=.agent-loop/edit/DEMO-1/apply',
        ]);
        $applied = $this->jsonFile($editRoot . '/apply/execution.json');
        if (($applied['status'] ?? null) !== 'runner_succeeded' || ($applied['runner']['dry_run'] ?? null) !== false) {
            throw new InstalledRefactorDogfoodFailure('Refactor apply did not publish successful mutation evidence.');
        }
        foreach (['src/Greeter.php', 'src/Caller.php'] as $relative) {
            $source = (string) file_get_contents($this->consumer . '/' . $relative);
            if (str_contains($source, 'oldName') || !str_contains($source, 'renamedMethod')) {
                throw new InstalledRefactorDogfoodFailure('Rename did not fully rewrite ' . $relative . '.');
            }
            $this->must([PHP_BINARY, '-l', $relative]);
        }

        // Rebind derived Map evidence to the rewritten source before close-out.
        $this->must([
            'vendor/bin/agent-map', 'build', '--root=.',
            '--paths=src/Greeter.php,src/Caller.php',
            '--backend=phpstan', '--phpstan-memory-limit=512M',
            '--out=.agent-loop/map/php-symbols.json',
        ]);

        $this->must(['composer', 'test']);
        $this->must([
            'vendor/bin/agent-loop', 'session', 'validation', 'record', 'DEMO-1',
            '--contract-revision', '1', '--command', 'composer test', '--status', 'passed',
            '--exit-code', '0', '--duration-ms', '0', '--by', 'installed-refactor-dogfood',
        ]);
        $this->must(['vendor/bin/agent-loop', 'review', 'blindspots', 'DEMO-1'], [0, 1]);
        $this->must([
            'vendor/bin/agent-loop', 'session', 'checkpoint', 'DEMO-1',
            '--title', 'Installed refactor review',
            '--body', 'Reviewed owner-generated rename evidence, atomic application, and validation output.',
        ]);
        $this->must(['vendor/bin/agent-loop', 'review', 'blindspots', 'DEMO-1']);
        $this->must([
            'vendor/bin/agent-loop', 'workflow', 'learn', 'DEMO-1',
            '--status', 'no_durable_learning', '--by', 'installed-refactor-dogfood',
            '--reason', 'The installed refactor proof added no reusable project-specific guidance.',
        ]);
        $this->must(['vendor/bin/agent-loop', 'verify', '--task-id=DEMO-1']);

        $verified = $this->status('DEMO-1');
        $reviewDigest = $verified['manifest']['references']['review']['source']['sha256'] ?? null;
        if (!is_string($reviewDigest) || !str_starts_with($reviewDigest, 'sha256:')) {
            throw new InstalledRefactorDogfoodFailure('Verified lifecycle did not expose exact review-report identity.');
        }

        $premature = $this->json($this->must([
            'vendor/bin/agent-loop', 'finish', 'DEMO-1', '--format=json',
        ], [1])['stdout'], 'premature finish');
        if (($premature['complete'] ?? null) !== false) {
            throw new InstalledRefactorDogfoodFailure('Lifecycle completed before review acknowledgement.');
        }

        $this->must([
            'vendor/bin/agent-loop', 'finish', 'DEMO-1',
            '--reviewed-report-sha256', $reviewDigest, '--by', 'installed-refactor-dogfood',
        ]);
        $complete = $this->status('DEMO-1', 'complete');
        $final = $this->json($this->must([
            'vendor/bin/agent-loop', 'finish', 'DEMO-1', '--format=json',
        ])['stdout'], 'completed finish');
        if (($complete['manifest']['state'] ?? null) !== 'complete'
            || ($final['complete'] ?? null) !== true
            || ($final['next_action'] ?? null) !== 'none'
        ) {
            throw new InstalledRefactorDogfoodFailure('Governed refactor lifecycle did not converge to complete.');
        }

        $this->writeJson($this->report, [
            'schema_version' => '1.0',
            'result' => 'passed',
            'claim' => 'owner_generated_method_rename_installed_consumer_governed_finish',
            'task_id' => 'DEMO-1',
            'release_set' => $packages,
            'semantic_backend' => $semanticBackend,
            'rename_capabilities' => $types,
            'plan' => [
                'type' => $plan['type'],
                'contract_version' => $plan['contract_version'],
                'target_id' => $plan['target_id'] ?? null,
                'edit_count' => count($plan['edits']),
                'sha256' => 'sha256:' . hash('sha256', $planResult['stdout']),
            ],
            'dry_run' => $dryRun,
            'apply' => $applied,
            'review_sha256' => $reviewDigest,
            'final_state' => $complete['manifest']['state'] ?? null,
            'final_next_action' => $final['next_action'] ?? null,
            'commands' => $this->commands,
        ]);

        echo "Installed refactor lifecycle dogfood: PASSED\n";
        echo 'Report: ' . $this->report . "\n";

        return 0;
    }

    private function stageAgentLoop(): void
    {
        $this->mkdir($this->candidateLoop);
        foreach (['composer.json', 'LICENSE'] as $file) {
            $source = $this->repositoryRoot . '/' . $file;
            if (is_file($source) && !copy($source, $this->candidateLoop . '/' . $file)) {
                throw new InstalledRefactorDogfoodFailure('Unable to stage agent-loop file: ' . $file);
            }
        }
        foreach (['src', 'bin', 'docs/agents'] as $directory) {
            $this->copyTree($this->repositoryRoot . '/' . $directory, $this->candidateLoop . '/' . $directory);
        }
    }

    private function createConsumer(): void
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
                    'url' => str_replace('\\', '/', $this->candidateLoop),
                    'options' => ['symlink' => false, 'versions' => ['voku/agent-loop' => 'dev-main']],
                ],
                [
                    'type' => 'path',
                    'url' => str_replace('\\', '/', $this->agentMapCandidate),
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
        $result = [];
        foreach (['packages', 'packages-dev'] as $section) {
            foreach (is_array($lock[$section] ?? null) ? $lock[$section] : [] as $package) {
                if (!is_array($package) || !is_string($package['name'] ?? null)) {
                    continue;
                }
                $dist = is_array($package['dist'] ?? null) ? $package['dist'] : [];
                $source = is_array($package['source'] ?? null) ? $package['source'] : [];
                $result[$package['name']] = [
                    'version' => is_string($package['version'] ?? null) ? $package['version'] : 'unknown',
                    'source_type' => is_string($dist['type'] ?? null)
                        ? $dist['type']
                        : (is_string($source['type'] ?? null) ? $source['type'] : 'unknown'),
                ];
            }
        }
        ksort($result, SORT_STRING);

        return $result;
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
    private function must(array $command, array $allowed = [0]): array
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
    private function status(string $task, ?string $expect = null): array
    {
        $command = ['vendor/bin/agent-loop', 'workflow', 'status', $task, '--format=json'];
        if ($expect !== null) {
            $command[] = '--expect=' . $expect;
        }

        return $this->json($this->must($command)['stdout'], 'workflow status');
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
    private function json(string $content, string $label): array
    {
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
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

function option(array $argv, string $name, ?string $default = null): string
{
    foreach (array_slice($argv, 1) as $argument) {
        $prefix = '--' . $name . '=';
        if (str_starts_with($argument, $prefix)) {
            $value = substr($argument, strlen($prefix));
            if ($value !== '') {
                return $value;
            }
        }
    }
    if ($default !== null) {
        return $default;
    }
    throw new InvalidArgumentException('--' . $name . ' is required.');
}

$repositoryRoot = dirname(__DIR__);
$workspace = option($argv, 'workspace', sys_get_temp_dir() . '/agent-loop-installed-refactor-' . bin2hex(random_bytes(4)));
$report = option($argv, 'report', $repositoryRoot . '/build/installed-refactor-lifecycle.json');
$agentMap = option($argv, 'agent-map');

try {
    exit((new InstalledRefactorLifecycleDogfood($repositoryRoot, $agentMap, $workspace, $report))->run());
} catch (Throwable $exception) {
    fwrite(STDERR, 'Installed refactor lifecycle dogfood failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
