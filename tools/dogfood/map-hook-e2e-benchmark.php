<?php

declare(strict_types=1);

/**
 * Research-only, real Git-hook end-to-end latency harness.
 *
 * Runs inside GitHub Actions with an installed, pinned agent-map checkout.
 * It is not part of the shipped agent-loop CLI or default CI.
 */
final class AgentMapHookEndToEndBenchmark
{
    private const int MODULE_COUNT = 120;
    private const int REPETITIONS = 3;

    private string $trace;
    private string $index;
    private string $searchDatabase;

    /** @var array<string, string> */
    private array $revisions = [];

    public function __construct(
        private readonly string $fixture,
        private readonly string $loopCheckout,
        private readonly string $mapCommit,
    ) {
        $this->trace = $this->fixture . '/map-hook-trace.jsonl';
        $this->index = $this->fixture . '/.agent-loop/map/php-symbols.json';
        $this->searchDatabase = $this->fixture . '/.agent-loop/map/search.sqlite';
    }

    public function run(): void
    {
        $this->prepareGitFixture();
        $this->prepareGitTransitions();
        $this->installRealHooksAndTimingProxy();
        $this->runCommand(
            [PHP_BINARY, $this->realMapBinary(), 'build', '--root=.', '--paths=src', '--backend=phpstan', '--out=.agent-loop/map/php-symbols.json'],
        );
        $this->runCommand(
            [PHP_BINARY, $this->realMapBinary(), 'search-index', 'build', '--root=.', '--index=.agent-loop/map/php-symbols.json', '--database=.agent-loop/map/search.sqlite'],
        );
        $this->assertCurrent('base');
        $this->clearTrace();

        $samples = [
            'noop' => [],
            'docs_only' => [],
            'php_edit' => [],
            'php_delete' => [],
            'composer_lock_only' => [],
        ];

        for ($round = 0; $round < self::REPETITIONS; ++$round) {
            // Alternate order to reduce cache warming and CI host time drift.
            $cases = $round % 2 === 0
                ? ['noop', 'docs_only', 'php_edit', 'php_delete', 'composer_lock_only']
                : ['composer_lock_only', 'php_delete', 'php_edit', 'docs_only', 'noop'];

            foreach ($cases as $case) {
                $this->clearTrace();
                $target = $case === 'noop' ? 'base' : $case;
                $elapsed = $this->timedCheckout($this->revisions[$target]);
                $events = $this->readTrace();
                $expectedEvents = in_array($case, ['noop', 'docs_only'], true)
                    ? []
                    : ['refresh', 'search-index refresh'];
                $actualEvents = array_column($events, 'stage');
                if ($actualEvents !== $expectedEvents) {
                    throw new RuntimeException($case . ': wrong real hook pipeline: ' . json_encode($actualEvents));
                }
                foreach ($events as $event) {
                    if ($event['exit'] !== 0) {
                        throw new RuntimeException($case . ': a real map/search step failed: ' . json_encode($event));
                    }
                }

                $this->assertCurrent($target);
                $mapMs = $this->stageMs($events, 'refresh');
                $searchMs = $this->stageMs($events, 'search-index refresh');
                $samples[$case][] = [
                    'git_checkout_plus_real_hook_ms' => $elapsed,
                    'map_refresh_ms' => $mapMs,
                    'search_index_refresh_ms' => $searchMs,
                    'git_and_hook_overhead_ms' => round(max(0.0, $elapsed - $mapMs - $searchMs), 3),
                    'semantic_and_search_parity' => true,
                ];
                fwrite(STDERR, sprintf(
                    "PHP %s round %d %s: checkout+hook %.1f ms; real map %.1f ms; search %.1f ms; parity PASS\n",
                    PHP_VERSION,
                    $round + 1,
                    $case,
                    $elapsed,
                    $mapMs,
                    $searchMs,
                ));

                if ($case !== 'noop') {
                    // Restoration is deliberately not counted in the forward checkout.
                    // It runs the same real hook and restores canonical base state.
                    $this->clearTrace();
                    $this->timedCheckout($this->revisions['base']);
                    $this->assertCurrent('base');
                }
            }
        }

        $cases = [];
        foreach ($samples as $case => $observations) {
            $checkout = array_column($observations, 'git_checkout_plus_real_hook_ms');
            $map = array_column($observations, 'map_refresh_ms');
            $search = array_column($observations, 'search_index_refresh_ms');
            $overhead = array_column($observations, 'git_and_hook_overhead_ms');
            $cases[$case] = [
                'median_checkout_plus_hook_ms' => $this->median($checkout),
                'median_map_refresh_ms' => $this->median($map),
                'median_search_index_refresh_ms' => $this->median($search),
                'median_git_and_hook_overhead_ms' => $this->median($overhead),
                'samples' => $observations,
            ];
        }

        $launchOverhead = $this->measureStartupOverhead();

        $report = [
            'schema_version' => 1,
            'experiment' => 'real git post-checkout hook, real PHPStan agent-map refresh and FTS search-index refresh',
            'php_version' => PHP_VERSION,
            'agent_loop_head' => getenv('GITHUB_SHA') ?: 'local',
            'agent_map_pinned_head' => $this->mapCommit,
            'fixture' => [
                'php_source_files' => self::MODULE_COUNT,
                'phpstan_backend_required' => true,
                'git_commits_are_real' => true,
                'post_checkout_hook_executed_by_git' => true,
                'composer_lock_valid_json' => true,
                'search_index_fts5_enabled' => true,
            ],
            'repetitions' => self::REPETITIONS,
            'cases' => $cases,
            'startup_microprobes_median_ms' => $launchOverhead,
            'invariants' => [
                'missing_or_unexpected_hook_calls_fail' => true,
                'current_php_file_hashes_verified' => true,
                'current_composer_lock_hash_verified' => true,
                'index_file_count_checked' => true,
                'real_search_index_doctor_run_after_each_transition' => true,
            ],
            'limitations' => [
                'Small synthetic 120-file project, not IT-Portal; these timings must not be sold as production speedups.',
                'Changing composer.lock content-hash exercises semantic invalidation without updating installed dependencies.',
                'PHPStan and Composer dependencies are real; branch transitions change only fixture files.',
                'Each checkout is paired with an unmeasured restorative checkout; host filesystem cache may remain warm.',
                'The wrapper adds a small fixed PHP-process overhead and measures map/search subprocesses separately.',
            ],
        ];
        $reportPath = $this->fixture . '/map-hook-e2e-report.json';
        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        $this->writeGitHubSummary($cases, $launchOverhead);
    }

    /**
     * Diagnose the otherwise unattributed Git/hook/process envelope without
     * changing shipped hook behavior. These shell startup probes are supporting
     * evidence, not an A/B proof that a different shell mode is safe.
     *
     * @return array<string, float>
     */
    private function measureStartupOverhead(): array
    {
        $php = escapeshellarg(PHP_BINARY);
        $noopPhp = escapeshellarg('exit(0);');
        $commands = [
            'bash_login_empty' => ['bash', '-lc', ':'],
            'bash_nonlogin_empty' => ['bash', '-c', ':'],
            'php_empty' => [PHP_BINARY, '-r', 'exit(0);'],
            'bash_login_php' => ['bash', '-lc', $php . ' -r ' . $noopPhp],
            'bash_nonlogin_php' => ['bash', '-c', $php . ' -r ' . $noopPhp],
        ];
        $observations = [];
        for ($round = 0; $round < 9; ++$round) {
            $order = $round % 2 === 0 ? array_keys($commands) : array_reverse(array_keys($commands));
            foreach ($order as $name) {
                $started = hrtime(true);
                $this->runCommand($commands[$name]);
                $observations[$name][] = round((hrtime(true) - $started) / 1_000_000, 3);
            }
        }

        $results = [];
        foreach ($observations as $name => $samples) {
            $results[$name] = $this->median($samples);
        }
        fwrite(STDERR, 'Process startup probes (median ms, diagnostics only): '
            . json_encode($results, JSON_THROW_ON_ERROR) . "\n");

        return $results;
    }

    private function prepareGitFixture(): void
    {
        if (!is_file($this->fixture . '/vendor/voku/agent-map/bin/agent-map')
            || !is_file($this->fixture . '/vendor/phpstan/phpstan/phpstan.phar')
            || !is_file($this->fixture . '/composer.lock')) {
            throw new RuntimeException('Real Composer-installed PHPStan and agent-map dependencies are required.');
        }

        mkdir($this->fixture . '/src', 0775, true);
        mkdir($this->fixture . '/.agent-loop/map', 0775, true);
        file_put_contents($this->fixture . '/.gitignore', "vendor/\n.agent-loop/\n.githooks/\nmap-hook-trace.jsonl\nmap-hook-e2e-report.json\n");
        file_put_contents($this->fixture . '/README.md', "# Real Git-hook benchmark fixture\n");
        for ($i = 0; $i < self::MODULE_COUNT; ++$i) {
            $code = "<?php\n\ndeclare(strict_types=1);\n\nnamespace HookBenchmark;\n\n";
            $code .= "final class Service{$i}\n{\n    public function run(int \$value): int\n    {\n        return \$value + {$i};\n    }\n}\n\n";
            $code .= "final class Consumer{$i}\n{\n    public function call(Service{$i} \$service): int\n    {\n        return \$service->run(1);\n    }\n}\n";
            file_put_contents($this->fixture . '/src/Module' . $i . '.php', $code);
        }
        $this->runCommand(['git', 'init', '--quiet', '-b', 'base']);
        $this->commit('benchmark base with installed dependency lock');
        $this->revisions['base'] = trim($this->runCommand(['git', 'rev-parse', 'HEAD'])['stdout']);
    }

    private function prepareGitTransitions(): void
    {
        foreach (['docs_only', 'php_edit', 'php_delete', 'composer_lock_only'] as $case) {
            $this->runCommand(['git', 'checkout', '-q', '-b', $case, $this->revisions['base']]);
            switch ($case) {
                case 'docs_only':
                    file_put_contents($this->fixture . '/README.md', "# Benchmark documentation update\n");
                    break;
                case 'php_edit':
                    file_put_contents($this->fixture . '/src/Module1.php', "\n// Changed indexed source in a real Git branch\n", FILE_APPEND);
                    break;
                case 'php_delete':
                    unlink($this->fixture . '/src/Module2.php');
                    break;
                case 'composer_lock_only':
                    $lock = json_decode((string) file_get_contents($this->fixture . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($lock)) {
                        throw new RuntimeException('Invalid Composer fixture lock.');
                    }
                    $lock['content-hash'] = str_repeat('a', 64);
                    file_put_contents(
                        $this->fixture . '/composer.lock',
                        json_encode($lock, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n",
                    );
                    break;
            }
            $this->commit($case . ' transition');
            $this->revisions[$case] = trim($this->runCommand(['git', 'rev-parse', 'HEAD'])['stdout']);
            $this->runCommand(['git', 'checkout', '-q', 'base']);
        }
    }

    private function installRealHooksAndTimingProxy(): void
    {
        mkdir($this->fixture . '/.githooks/lib', 0775, true);
        $from = $this->loopCheckout . '/resources/githooks';
        foreach (['post-checkout', 'agent-map-refresh.sh', 'lib/agent-loop-hooks.sh'] as $path) {
            $to = $this->fixture . '/.githooks/' . $path;
            if (!copy($from . '/' . $path, $to)) {
                throw new RuntimeException('Unable to install real shipped Git hook ' . $path);
            }
            chmod($to, 0755);
        }

        $proxy = $this->fixture . '/vendor/bin/agent-map';
        if (is_link($proxy) || is_file($proxy)) {
            unlink($proxy);
        }
        // Only timing is instrumented; the installed agent-map CLI runs in a
        // separate real PHP process with identical arguments.
        $wrapper = <<<'PHP'
<?php

declare(strict_types=1);

$stage = (($argv[1] ?? '') === 'search-index')
    ? 'search-index ' . ($argv[2] ?? '')
    : ($argv[1] ?? 'unknown');

$started = hrtime(true);
$command = [PHP_BINARY, __DIR__ . '/../voku/agent-map/bin/agent-map', ...array_slice($argv, 1)];
$process = proc_open(
    $command,
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    getcwd(),
);
if (!is_resource($process)) {
    fwrite(STDERR, "Cannot execute installed agent-map.\n");
    exit(127);
}
fclose($pipes[0]);
$out = (string) stream_get_contents($pipes[1]);
fclose($pipes[1]);
$err = (string) stream_get_contents($pipes[2]);
fclose($pipes[2]);
$status = proc_close($process);
echo $out;
fwrite(STDERR, $err);
$ms = round((hrtime(true) - $started) / 1_000_000, 3);
$trace = getenv('MAP_BENCH_TRACE');
if (is_string($trace) && $trace !== '') {
    file_put_contents(
        $trace,
        json_encode(['stage' => $stage, 'ms' => $ms, 'exit' => $status], JSON_THROW_ON_ERROR) . "\n",
        FILE_APPEND,
    );
}
exit($status);
PHP;
        file_put_contents($proxy, $wrapper);
        chmod($proxy, 0755);
        $this->runCommand(['git', 'config', 'core.hooksPath', '.githooks']);
    }

    private function commit(string $message): void
    {
        $this->runCommand(['git', 'add', '-A']);
        $this->runCommand([
            'git',
            '-c', 'user.name=AgentMapHookBenchmark',
            '-c', 'user.email=agent-map-hook-benchmark@example.invalid',
            'commit', '--quiet', '-m', $message,
        ]);
    }

    private function timedCheckout(string $rev): float
    {
        $start = hrtime(true);
        $this->runCommand(['git', 'checkout', '--quiet', $rev]);
        return round((hrtime(true) - $start) / 1_000_000, 3);
    }

    /**
     * @param list<string> $argv
     * @return array{stdout: string, stderr: string}
     */
    private function runCommand(array $argv): array
    {
        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment['MAP_BENCH_TRACE'] = $this->trace;
        $environment['AGENT_LOOP_CONTAINER_WORKDIR'] = '';
        $environment['AGENT_LOOP_CONTAINER_SERVICE'] = '';
        $environment['AGENT_LOOP_CONTAINER_IMAGE'] = '';
        $environment['AGENT_LOOP_SKIP_MAP_REFRESH'] = '0';

        $proc = proc_open(
            $argv,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->fixture,
            $environment,
        );
        if (!is_resource($proc)) {
            throw new RuntimeException('Unable to execute ' . implode(' ', $argv));
        }
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        if ($exit !== 0) {
            throw new RuntimeException('Command failed (' . $exit . '): ' . implode(' ', $argv) . "\n" . $out . "\n" . $err);
        }
        return ['stdout' => $out, 'stderr' => $err];
    }

    private function assertCurrent(string $case): void
    {
        require_once $this->fixture . '/vendor/autoload.php';
        $index = (new \voku\AgentMap\Index\IndexReader())->readForRoot($this->index, $this->fixture);
        if ($index->backend !== 'simple-php-code-parser+phpstan' || $index->staleEntries() !== []) {
            throw new RuntimeException($case . ': PHPStan map not current or backend downgraded.');
        }
        $expectedCount = $case === 'php_delete' ? self::MODULE_COUNT - 1 : self::MODULE_COUNT;
        if (count($index->files) !== $expectedCount) {
            throw new RuntimeException($case . ': unexpected indexed file count ' . count($index->files));
        }
        $currentLock = 'sha256:' . hash_file('sha256', $this->fixture . '/composer.lock');
        if ($index->fingerprint === null || $index->fingerprint->composerLockSha256 !== $currentLock) {
            throw new RuntimeException($case . ': Composer lock fingerprint is stale after Git transition.');
        }
        $this->runCommand([
            PHP_BINARY, $this->realMapBinary(),
            'search-index', 'doctor', '--root=.',
            '--index=.agent-loop/map/php-symbols.json',
            '--database=.agent-loop/map/search.sqlite',
        ]);
    }

    private function realMapBinary(): string
    {
        return $this->fixture . '/vendor/voku/agent-map/bin/agent-map';
    }

    private function clearTrace(): void
    {
        file_put_contents($this->trace, '');
    }

    /** @return list<array{stage: string, ms: float, exit: int}> */
    private function readTrace(): array
    {
        $events = [];
        $lines = file($this->trace, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }
        foreach ($lines as $line) {
            $item = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($item) || !is_string($item['stage'] ?? null) || !is_numeric($item['ms'] ?? null)
                || !is_int($item['exit'] ?? null)) {
                throw new RuntimeException('Malformed hook trace event.');
            }
            $events[] = ['stage' => $item['stage'], 'ms' => (float) $item['ms'], 'exit' => $item['exit']];
        }
        return $events;
    }

    /** @param list<array{stage: string, ms: float, exit: int}> $events */
    private function stageMs(array $events, string $stage): float
    {
        foreach ($events as $event) {
            if ($event['stage'] === $stage) {
                return $event['ms'];
            }
        }
        return 0.0;
    }

    /** @param list<float> $numbers */
    private function median(array $numbers): float
    {
        sort($numbers, SORT_NUMERIC);
        return round($numbers[intdiv(count($numbers), 2)], 3);
    }

    /**
     * @param array<string, array<string, mixed>> $cases
     * @param array<string, float> $launchOverhead
     */
    private function writeGitHubSummary(array $cases, array $launchOverhead): void
    {
        $summary = getenv('GITHUB_STEP_SUMMARY');
        if (!is_string($summary) || $summary === '') {
            return;
        }
        $text = "## Real Git post-checkout → agent-map → search-index: PHP " . PHP_VERSION . "\n\n"
            . "Three paired transitions per scenario; exact real hook, real Composer-installed map and PHPStan.\n\n"
            . "| Transition | Checkout + hook | Map refresh | Search refresh | Git/hook overhead | Correctness |\n"
            . "| --- | ---: | ---: | ---: | ---: | --- |\n";
        foreach ($cases as $case => $result) {
            $text .= sprintf(
                "| %s | %.1f ms | %.1f ms | %.1f ms | %.1f ms | PASS |\n",
                $case,
                $result['median_checkout_plus_hook_ms'],
                $result['median_map_refresh_ms'],
                $result['median_search_index_refresh_ms'],
                $result['median_git_and_hook_overhead_ms'],
            );
        }
        $text .= "\nEvery Git transition passed semantic fingerprint, source freshness, and real search-index doctor checks.\n";
        $text .= "\n### Diagnostic shell/process startup probes (not a production A/B)\n\n";
        foreach ($launchOverhead as $name => $ms) {
            $text .= sprintf("- %s: %.3f ms\n", $name, $ms);
        }
        file_put_contents($summary, $text, FILE_APPEND);
    }
}

$fixture = getenv('MAP_BENCH_FIXTURE');
$loop = getenv('MAP_BENCH_LOOP_CHECKOUT');
$map = getenv('MAP_BENCH_MAP_COMMIT');
if (!is_string($fixture) || $fixture === '' || !is_string($loop) || $loop === '' || !is_string($map) || $map === '') {
    throw new RuntimeException('MAP_BENCH_FIXTURE, MAP_BENCH_LOOP_CHECKOUT and MAP_BENCH_MAP_COMMIT are required.');
}
(new AgentMapHookEndToEndBenchmark($fixture, $loop, $map))->run();
