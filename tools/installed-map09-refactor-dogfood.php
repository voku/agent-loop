<?php

declare(strict_types=1);

final class InstalledMap09DogfoodFailure extends RuntimeException
{
}

final class InstalledMap09RefactorDogfood
{
    private string $loopCandidate;
    private string $consumer;

    /** @var list<array{command: string, exit: int}> */
    private array $commands = [];

    public function __construct(
        private readonly string $repositoryRoot,
        private readonly string $scenario,
        private readonly string $workspace,
        private readonly string $reportPath,
    ) {
        if (!in_array($scenario, ['method', 'parameter', 'class-move'], true)) {
            throw new InvalidArgumentException('Unknown scenario: ' . $scenario);
        }
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
        $releaseSet = $this->resolvedPackages();
        $this->assertPathPackage($releaseSet, 'voku/agent-loop', 'dev-main');
        $this->assertReleasedPackage($releaseSet, 'voku/agent-map', '0.10.0', true);
        $this->assertReleasedPackage($releaseSet, 'voku/agent-recall-compiler', '0.13.16', false);
        if (!isset($releaseSet['phpstan/phpstan'])) {
            throw new InstalledMap09DogfoodFailure('Installed consumer is missing PHPStan.');
        }

        $this->runCommand(['git', 'init', '--initial-branch=main']);
        $this->runCommand(['git', 'config', 'user.name', 'Installed Map 0.9 Dogfood']);
        $this->runCommand(['git', 'config', 'user.email', 'map09-dogfood@example.invalid']);
        $this->runCommand(['git', 'add', 'composer.json', 'composer.lock', '.gitignore', 'src', 'tests']);
        $this->runCommand(['git', 'commit', '-m', 'fixture: initial Map 0.9 consumer']);

        $this->runCommand(['vendor/bin/agent-loop', 'init', 'scaffold', '--demo', '--agent=codex']);
        $this->runCommand([
            'vendor/bin/agent-loop', 'workflow', 'plan', 'DEMO-1',
            '--by', 'installed-map09-dogfood',
            '--file', $this->primaryFile(),
            '--file', 'src/Caller.php',
            '--goal', $this->goal(),
            '--behavior-anchor', 'Owner-generated Map plan -> governed Loop mutation -> rebuilt Map verification.',
            '--validation', 'composer test',
            '--acceptance', $this->acceptance(),
        ]);
        $this->runCommand(['vendor/bin/agent-loop', 'workflow', 'approve', 'DEMO-1', '--by', 'installed-map09-dogfood']);
        $enter = $this->json($this->runCommand([
            'vendor/bin/agent-loop', 'enter', 'DEMO-1', '--format=json', '--max-lines=40', '--max-bytes=4096',
        ])['stdout'], 'enter');
        if (($enter['mutation_ready'] ?? null) !== true) {
            throw new InstalledMap09DogfoodFailure('Governed enter did not expose mutation readiness.');
        }

        $this->buildMap();
        $capabilities = $this->planCapabilities();
        $planRun = $this->runCommand($this->planCommand());
        $plan = $this->json($planRun['stdout'], 'owner-generated plan');
        if (($plan['type'] ?? null) !== $this->planType()
            || ($plan['contract_version'] ?? null) !== '1.0'
            || ($plan['status'] ?? null) !== 'safe'
            || !is_array($plan['edits'] ?? null)
            || $plan['edits'] === []
        ) {
            throw new InstalledMap09DogfoodFailure('Owner-generated plan is not safe contract 1.0 evidence.');
        }

        $bundle = $this->consumer . '/.agent-loop/edit/DEMO-1';
        $this->mkdir($bundle);
        $planPath = $bundle . '/plan.json';
        file_put_contents($planPath, $planRun['stdout']);
        $before = $this->sourceSnapshot();
        $this->proveRejectedVariants($plan, $before);

        $this->runCommand([
            'vendor/bin/agent-loop', 'edit', 'refactor', '.agent-loop/edit/DEMO-1/plan.json',
            '--task=DEMO-1', '--map-index=.agent-loop/map/php-symbols.json', '--map-root=.',
            '--output-dir=.agent-loop/edit/DEMO-1/dry-run', '--dry-run',
        ]);
        $dryRun = $this->jsonFile($bundle . '/dry-run/execution.json');
        if (($dryRun['status'] ?? null) !== 'prepared' || ($dryRun['runner']['dry_run'] ?? null) !== true) {
            throw new InstalledMap09DogfoodFailure('Dry-run did not publish prepared evidence.');
        }
        $this->assertSourceSnapshot($before, 'dry-run changed source');

        $this->runCommand([
            'vendor/bin/agent-loop', 'edit', 'refactor', '.agent-loop/edit/DEMO-1/plan.json',
            '--task=DEMO-1', '--map-index=.agent-loop/map/php-symbols.json', '--map-root=.',
            '--output-dir=.agent-loop/edit/DEMO-1',
        ]);
        $apply = $this->jsonFile($bundle . '/execution.json');
        if (($apply['status'] ?? null) !== 'runner_succeeded' || ($apply['runner']['dry_run'] ?? null) !== false) {
            throw new InstalledMap09DogfoodFailure('Apply did not publish successful mutation evidence.');
        }

        $this->assertRewrittenSource();
        $this->buildMap();
        $this->runCommand([
            'vendor/bin/agent-loop', 'edit', 'refactor', 'verify',
            '--bundle=.agent-loop/edit/DEMO-1', '--map-index=.agent-loop/map/php-symbols.json', '--map-root=.',
        ]);
        $verification = $this->jsonFile($bundle . '/verification-result.json');
        if (($verification['status'] ?? null) !== 'passed' || ($verification['plan']['type'] ?? null) !== $this->planType()) {
            throw new InstalledMap09DogfoodFailure('Deterministic refactor verification did not pass.');
        }
        if ($this->scenario === 'class-move') {
            $this->assertClassMoveMap();
        }

        $this->runCommand(['composer', 'test']);
        $this->runCommand([
            'vendor/bin/agent-loop', 'session', 'validation', 'record', 'DEMO-1',
            '--contract-revision', '1', '--command', 'composer test', '--status', 'passed',
            '--exit-code', '0', '--duration-ms', '0', '--by', 'installed-map09-dogfood',
        ]);
        $this->runCommand(['vendor/bin/agent-loop', 'review', 'blindspots', 'DEMO-1'], [0, 1]);
        $this->runCommand([
            'vendor/bin/agent-loop', 'session', 'checkpoint', 'DEMO-1',
            '--title', 'Installed Map 0.9 consumer review',
            '--body', 'Reviewed released owner plan, governed mutation, rebuilt Map verification, and validation evidence.',
        ]);
        $this->runCommand(['vendor/bin/agent-loop', 'review', 'blindspots', 'DEMO-1']);
        $this->runCommand([
            'vendor/bin/agent-loop', 'workflow', 'learn', 'DEMO-1',
            '--status', 'no_durable_learning', '--by', 'installed-map09-dogfood',
            '--reason', 'The clean installed consumer proof added no reusable project-specific guidance.',
        ]);
        $this->runCommand(['vendor/bin/agent-loop', 'verify', '--task-id=DEMO-1']);
        $status = $this->json($this->runCommand(['vendor/bin/agent-loop', 'workflow', 'status', 'DEMO-1', '--format=json'])['stdout'], 'status');
        $reviewDigest = $status['manifest']['references']['review']['source']['sha256'] ?? null;
        if (!is_string($reviewDigest) || !str_starts_with($reviewDigest, 'sha256:')) {
            throw new InstalledMap09DogfoodFailure('Verified lifecycle did not expose exact review identity.');
        }
        $this->runCommand([
            'vendor/bin/agent-loop', 'finish', 'DEMO-1', '--reviewed-report-sha256', $reviewDigest,
            '--by', 'installed-map09-dogfood',
        ]);
        $final = $this->json($this->runCommand(['vendor/bin/agent-loop', 'finish', 'DEMO-1', '--format=json'])['stdout'], 'finish');
        if (($final['complete'] ?? null) !== true || ($final['next_action'] ?? null) !== 'none') {
            throw new InstalledMap09DogfoodFailure('Governed lifecycle did not converge to complete.');
        }

        $this->writeJson($this->reportPath, [
            'schema_version' => '1.0',
            'result' => 'passed',
            'scenario' => $this->scenario,
            'release_set' => $releaseSet,
            'plan_capabilities' => $capabilities,
            'plan' => ['type' => $this->planType(), 'contract_version' => '1.0'],
            'dry_run' => $dryRun,
            'apply' => $apply,
            'verification' => $verification,
            'final_state' => 'complete',
            'commands' => $this->commands,
        ]);

        echo 'Installed Map 0.9 refactor dogfood (' . $this->scenario . "): PASSED\n";
        echo 'Report: ' . $this->reportPath . "\n";

        return 0;
    }

    /** @param array<string, mixed> $plan @param array<string, string> $before */
    private function proveRejectedVariants(array $plan, array $before): void
    {
        $variants = [];
        $review = $plan;
        $review['status'] = 'review_required';
        $variants['review'] = $review;
        $unsupported = $plan;
        $unsupported['type'] = 'method_move_plan';
        $variants['unsupported'] = $unsupported;
        $tampered = $plan;
        if (is_array($tampered['provenance'] ?? null)) {
            $tampered['provenance']['map_digest'] = 'sha256:' . str_repeat('0', 64);
        }
        $variants['tampered'] = $tampered;

        foreach ($variants as $name => $variant) {
            $path = '.agent-loop/edit/DEMO-1/' . $name . '.json';
            $this->writeJson($this->consumer . '/' . $path, $variant);
            $this->runCommand([
                'vendor/bin/agent-loop', 'edit', 'refactor', $path,
                '--task=DEMO-1', '--map-index=.agent-loop/map/php-symbols.json', '--map-root=.',
                '--output-dir=.agent-loop/edit/DEMO-1/' . $name . '-rejected', '--dry-run',
            ], [1]);
            $this->assertSourceSnapshot($before, $name . ' plan mutated source');
        }
    }

    /** @return list<array<string, mixed>> */
    private function planCapabilities(): array
    {
        $payload = $this->json(
            $this->runCommand(['vendor/bin/agent-map', 'plan-capabilities', '--format=json'])['stdout'],
            'plan capabilities',
        );
        $rows = is_array($payload['capabilities'] ?? null) ? $payload['capabilities'] : [];
        $types = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['plan_type'] ?? null) && is_string($row['contract_version'] ?? null)) {
                $types[$row['plan_type']] = $row['contract_version'];
            }
        }
        ksort($types, SORT_STRING);
        $expected = [
            'class_constant_removal_plan' => '1.0',
            'class_constant_rename_plan' => '1.0',
            'class_move_plan' => '1.0',
            'class_rename_plan' => '1.0',
            'function_rename_plan' => '1.0',
            'method_removal_plan' => '1.0',
            'method_rename_plan' => '1.0',
            'parameter_rename_plan' => '1.0',
            'property_removal_plan' => '1.0',
            'property_rename_plan' => '1.0',
        ];
        if ($types !== $expected) {
            throw new InstalledMap09DogfoodFailure('Released Map 0.9 plan registry does not match the supported consumer contract.');
        }

        return $rows;
    }

    private function buildMap(): void
    {
        $backend = $this->scenario === 'class-move' ? 'structural' : 'phpstan';
        $command = [
            'vendor/bin/agent-map', 'build', '--root=.', '--paths=src', '--backend=' . $backend,
            '--out=.agent-loop/map/php-symbols.json',
        ];
        if ($backend === 'phpstan') {
            $command[] = '--phpstan-memory-limit=512M';
        }
        $this->runCommand($command);
    }

    /** @return list<string> */
    private function planCommand(): array
    {
        $base = match ($this->scenario) {
            'method' => ['vendor/bin/agent-map', 'rename-plan', 'Fixture\\Greeter::oldName', 'renamedMethod'],
            'parameter' => ['vendor/bin/agent-map', 'parameter-rename-plan', 'Fixture\\Greeter::format', '$name', '$person'],
            'class-move' => ['vendor/bin/agent-map', 'class-move-plan', 'Fixture\\Legacy\\Greeter', 'Fixture\\Modern\\Greeter'],
        };
        $base[] = '--index=.agent-loop/map/php-symbols.json';
        $base[] = '--format=json';

        return $base;
    }

    private function planType(): string
    {
        return match ($this->scenario) {
            'method' => 'method_rename_plan',
            'parameter' => 'parameter_rename_plan',
            'class-move' => 'class_move_plan',
        };
    }

    private function primaryFile(): string
    {
        return $this->scenario === 'class-move' ? 'src' : 'src/Greeter.php';
    }

    private function goal(): string
    {
        return match ($this->scenario) {
            'method' => 'Rename Fixture\\Greeter::oldName to renamedMethod through released agent-map 0.9.',
            'parameter' => 'Rename Fixture\\Greeter::format parameter $name to $person through released agent-map 0.9.',
            'class-move' => 'Move Fixture\\Legacy\\Greeter to Fixture\\Modern\\Greeter through released agent-map 0.9.',
        };
    }

    private function acceptance(): string
    {
        return match ($this->scenario) {
            'method' => 'oldName is absent, renamedMethod is callable, and deterministic refactor verification passes.',
            'parameter' => 'The private binding and named argument use $person, positional calls remain valid, and verification passes.',
            'class-move' => 'The legacy class path/identity is absent, the modern path/identity exists, and verification passes.',
        };
    }

    private function stageLoopCandidate(): void
    {
        $this->mkdir($this->loopCandidate);
        foreach (['composer.json', 'LICENSE'] as $file) {
            $source = $this->repositoryRoot . '/' . $file;
            if (is_file($source) && !copy($source, $this->loopCandidate . '/' . $file)) {
                throw new InstalledMap09DogfoodFailure('Unable to stage Loop file: ' . $file);
            }
        }
        foreach (['src', 'bin', 'resources'] as $directory) {
            $this->copyTree($this->repositoryRoot . '/' . $directory, $this->loopCandidate . '/' . $directory);
        }
    }

    private function writeConsumer(): void
    {
        $this->mkdir($this->consumer . '/src');
        $this->mkdir($this->consumer . '/tests');
        $this->writeJson($this->consumer . '/composer.json', [
            'name' => 'voku/installed-map09-consumer',
            'type' => 'project',
            'require-dev' => [
                'phpstan/phpstan' => '^2.2',
                'voku/agent-loop' => 'dev-main',
            ],
            'repositories' => [[
                'type' => 'path',
                'url' => str_replace('\\', '/', $this->loopCandidate),
                'options' => ['symlink' => false, 'versions' => ['voku/agent-loop' => 'dev-main']],
            ]],
            'autoload' => ['psr-4' => ['Fixture\\' => 'src/']],
            'scripts' => ['test' => 'php tests/run.php'],
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
            'config' => ['allow-plugins' => false, 'sort-packages' => true],
        ]);
        file_put_contents($this->consumer . '/.gitignore', "/vendor/\n/.agent-loop/\n");

        match ($this->scenario) {
            'method' => $this->writeMethodFixture(),
            'parameter' => $this->writeParameterFixture(),
            'class-move' => $this->writeClassMoveFixture(),
        };
    }

    private function writeMethodFixture(): void
    {
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
PHP);
        file_put_contents($this->consumer . '/src/Caller.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Fixture;

final class Caller
{
    public function run(Greeter $greeter): string
    {
        return $greeter->oldName('Map');
    }
}
PHP);
        file_put_contents($this->consumer . '/tests/run.php', <<<'PHP'
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$greeter = new \Fixture\Greeter();
if (!method_exists($greeter, 'renamedMethod') || method_exists($greeter, 'oldName')) {
    throw new RuntimeException('Method rename did not publish the expected API.');
}
if ((new \Fixture\Caller())->run($greeter) !== 'Hello Map') {
    throw new RuntimeException('Method rename changed behavior.');
}
PHP);
    }

    private function writeParameterFixture(): void
    {
        file_put_contents($this->consumer . '/src/Greeter.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Fixture;

final class Greeter
{
    public function greet(string $name): string
    {
        return $this->format(name: $name);
    }

    public function positional(): string
    {
        return $this->format('Map');
    }

    private function format(string $name): string
    {
        return 'Hello ' . $name;
    }
}
PHP);
        file_put_contents($this->consumer . '/src/Caller.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Fixture;

final class Caller
{
    public function run(Greeter $greeter): string
    {
        return $greeter->greet('Map');
    }
}
PHP);
        file_put_contents($this->consumer . '/tests/run.php', <<<'PHP'
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$greeter = new \Fixture\Greeter();
if ((new \Fixture\Caller())->run($greeter) !== 'Hello Map' || $greeter->positional() !== 'Hello Map') {
    throw new RuntimeException('Parameter rename changed behavior or positional calling.');
}
$source = (string) file_get_contents(dirname(__DIR__) . '/src/Greeter.php');
if (!str_contains($source, 'format(string $person)') || !str_contains($source, 'format(person: $name)') || !str_contains($source, "format('Map')")) {
    throw new RuntimeException('Parameter binding/named-argument rewrite is incomplete.');
}
PHP);
    }

    private function writeClassMoveFixture(): void
    {
        $this->mkdir($this->consumer . '/src/Legacy');
        file_put_contents($this->consumer . '/src/Legacy/Greeter.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Fixture\Legacy;

final class Greeter
{
    public function greet(): string
    {
        return 'Hello Map';
    }
}
PHP);
        file_put_contents($this->consumer . '/src/Caller.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Fixture;

use Fixture\Legacy\Greeter;

final class Caller
{
    public function run(Greeter $greeter): string
    {
        return $greeter->greet();
    }

    public function className(): string
    {
        return \Fixture\Legacy\Greeter::class;
    }
}
PHP);
        file_put_contents($this->consumer . '/tests/run.php', <<<'PHP'
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$greeter = new \Fixture\Modern\Greeter();
$caller = new \Fixture\Caller();
if ($caller->run($greeter) !== 'Hello Map' || $caller->className() !== \Fixture\Modern\Greeter::class) {
    throw new RuntimeException('Class move changed behavior or identity references.');
}
if (is_file(dirname(__DIR__) . '/src/Legacy/Greeter.php') || !is_file(dirname(__DIR__) . '/src/Modern/Greeter.php')) {
    throw new RuntimeException('Class move did not publish the expected paths.');
}
PHP);
    }

    private function assertRewrittenSource(): void
    {
        if ($this->scenario === 'method') {
            $greeter = (string) file_get_contents($this->consumer . '/src/Greeter.php');
            $caller = (string) file_get_contents($this->consumer . '/src/Caller.php');
            if (str_contains($greeter . $caller, 'oldName') || !str_contains($greeter . $caller, 'renamedMethod')) {
                throw new InstalledMap09DogfoodFailure('Method rename did not rewrite exact source scope.');
            }
            return;
        }
        if ($this->scenario === 'parameter') {
            $greeter = (string) file_get_contents($this->consumer . '/src/Greeter.php');
            if (!str_contains($greeter, 'format(string $person)')
                || !str_contains($greeter, "'Hello ' . \$person")
                || !str_contains($greeter, 'format(person: $name)')
                || !str_contains($greeter, "format('Map')")
            ) {
                throw new InstalledMap09DogfoodFailure('Parameter rename did not preserve binding/named/positional semantics.');
            }
            return;
        }
        if (is_file($this->consumer . '/src/Legacy/Greeter.php') || !is_file($this->consumer . '/src/Modern/Greeter.php')) {
            throw new InstalledMap09DogfoodFailure('Class move paths were not published atomically.');
        }
        $caller = (string) file_get_contents($this->consumer . '/src/Caller.php');
        if (str_contains($caller, 'Fixture\\Legacy\\Greeter') || !str_contains($caller, 'Fixture\\Modern\\Greeter')) {
            throw new InstalledMap09DogfoodFailure('Class move did not rewrite class references.');
        }
    }

    private function assertClassMoveMap(): void
    {
        $map = $this->jsonFile($this->consumer . '/.agent-loop/map/php-symbols.json');
        $legacy = false;
        $modern = false;
        foreach (is_array($map['files'] ?? null) ? $map['files'] : [] as $file) {
            if (!is_array($file)) {
                continue;
            }
            if (($file['path'] ?? null) === 'src/Legacy/Greeter.php') {
                $legacy = true;
            }
            if (($file['path'] ?? null) !== 'src/Modern/Greeter.php') {
                continue;
            }
            foreach (is_array($file['symbols'] ?? null) ? $file['symbols'] : [] as $symbol) {
                if (is_array($symbol) && ($symbol['fqn'] ?? null) === 'Fixture\\Modern\\Greeter') {
                    $modern = true;
                }
            }
        }
        if ($legacy || !$modern) {
            throw new InstalledMap09DogfoodFailure('Rebuilt Map did not expose only the relocated class identity.');
        }
    }

    /** @return array<string, string> */
    private function sourceSnapshot(): array
    {
        $paths = $this->scenario === 'class-move'
            ? ['src/Legacy/Greeter.php', 'src/Caller.php']
            : ['src/Greeter.php', 'src/Caller.php'];
        $snapshot = [];
        foreach ($paths as $path) {
            $hash = hash_file('sha256', $this->consumer . '/' . $path);
            if (!is_string($hash)) {
                throw new InstalledMap09DogfoodFailure('Unable to hash fixture source: ' . $path);
            }
            $snapshot[$path] = $hash;
        }

        return $snapshot;
    }

    /** @param array<string, string> $snapshot */
    private function assertSourceSnapshot(array $snapshot, string $message): void
    {
        foreach ($snapshot as $path => $hash) {
            $current = hash_file('sha256', $this->consumer . '/' . $path);
            if (!is_string($current) || !hash_equals($hash, $current)) {
                throw new InstalledMap09DogfoodFailure($message . ': ' . $path);
            }
        }
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
            throw new InstalledMap09DogfoodFailure('Expected path candidate ' . $name . '@' . $version . '.');
        }
    }

    /** @param array<string, array{version: string, source_type: string}> $packages */
    private function assertReleasedPackage(array $packages, string $name, string $version, bool $exact): void
    {
        $package = $packages[$name] ?? null;
        if (!is_array($package) || $package['source_type'] === 'path') {
            throw new InstalledMap09DogfoodFailure('Expected released non-path package ' . $name . '.');
        }
        $ok = $exact ? $package['version'] === $version : version_compare(ltrim($package['version'], 'v'), $version, '>=');
        if (!$ok) {
            throw new InstalledMap09DogfoodFailure('Released package version is outside the required boundary: ' . $name . '@' . $package['version']);
        }
    }

    /** @param list<string> $command @param list<int> $allowed @return array{exit: int, stdout: string, stderr: string} */
    private function runCommand(array $command, array $allowed = [0]): array
    {
        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
        $environment['COMPOSER_NO_INTERACTION'] = '1';
        $environment['COMPOSER_ALLOW_SUPERUSER'] = '1';
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->consumer, $environment);
        if (!is_resource($process)) {
            throw new InstalledMap09DogfoodFailure('Unable to start command: ' . implode(' ', $command));
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $stdout = is_string($stdout) ? $stdout : '';
        $stderr = is_string($stderr) ? $stderr : '';
        $this->commands[] = ['command' => implode(' ', $command), 'exit' => $exit];
        if (!in_array($exit, $allowed, true)) {
            throw new InstalledMap09DogfoodFailure(sprintf("Command failed (%d): %s\nSTDOUT:\n%s\nSTDERR:\n%s", $exit, implode(' ', $command), $stdout, $stderr));
        }

        return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @return array<string, mixed> */
    private function json(string $raw, string $label): array
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InstalledMap09DogfoodFailure('Malformed JSON for ' . $label . ': ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new InstalledMap09DogfoodFailure('Expected JSON object for ' . $label . '.');
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    private function jsonFile(string $path): array
    {
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            throw new InstalledMap09DogfoodFailure('Expected JSON file is missing: ' . $path);
        }

        return $this->json($raw, $path);
    }

    /** @param array<string, mixed> $payload */
    private function writeJson(string $path, array $payload): void
    {
        $this->mkdir(dirname($path));
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }

    private function mkdir(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0o775, true) && !is_dir($path)) {
            throw new InstalledMap09DogfoodFailure('Unable to create directory: ' . $path);
        }
    }

    private function copyTree(string $source, string $destination): void
    {
        $this->mkdir($destination);
        foreach (new DirectoryIterator($source) as $item) {
            if ($item->isDot()) {
                continue;
            }
            $to = $destination . '/' . $item->getFilename();
            if ($item->isDir()) {
                $this->copyTree($item->getPathname(), $to);
            } elseif (!copy($item->getPathname(), $to)) {
                throw new InstalledMap09DogfoodFailure('Unable to copy candidate file: ' . $item->getPathname());
            }
        }
    }

    private function removeTree(string $path): void
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

function map09Option(array $argv, string $name, ?string $default = null): string
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
$scenario = map09Option($argv, 'scenario');
$workspace = map09Option($argv, 'workspace', sys_get_temp_dir() . '/agent-loop-map09-' . $scenario . '-' . bin2hex(random_bytes(4)));
$report = map09Option($argv, 'report', $root . '/build/installed-map09-' . $scenario . '.json');

try {
    exit((new InstalledMap09RefactorDogfood($root, $scenario, $workspace, $report))->run());
} catch (Throwable $exception) {
    fwrite(STDERR, 'Installed Map 0.9 refactor dogfood failed: ' . $exception->getMessage() . "\n");
    exit(1);
}