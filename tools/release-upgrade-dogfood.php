<?php

declare(strict_types=1);

final readonly class UpgradeCommandResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {
    }
}

final readonly class UpgradeScenario
{
    public function __construct(
        public string $name,
        public string $root,
        public string $projectRoot,
        public string $composerRoot,
    ) {
    }
}

final class ReleaseUpgradeDogfood
{
    /** @var list<array<string, mixed>> */
    private array $scenarios = [];

    /** @var list<array{command: list<string>, cwd: string, exit_code: int, stdout_sha256: non-empty-string, stderr_sha256: non-empty-string}> */
    private array $commands = [];

    public function __construct(
        private readonly string $workspace,
        private readonly string $fromVersion,
        private readonly string $toRef,
        private readonly string $toBranch,
        private readonly string $repositoryUrl,
        private readonly string $reportPath,
        private readonly bool $keep,
    ) {
    }

    public function run(): int
    {
        $this->removeDirectory($this->workspace);
        $this->makeDirectory($this->workspace);

        try {
            $this->scenarios[] = $this->legalResume();
            $this->scenarios[] = $this->prunedSessionResume();
            $this->scenarios[] = $this->staleAuthorityAndSupersession();
            $this->writeReport('passed', null);
            echo sprintf("Release upgrade proof passed: %s -> %s@%s\n", $this->fromVersion, $this->toBranch, $this->toRef);

            return 0;
        } catch (Throwable $exception) {
            $this->writeReport('failed', $exception->getMessage());
            fwrite(STDERR, '[FAIL] release upgrade proof: ' . $exception->getMessage() . "\n");

            return 1;
        } finally {
            if (!$this->keep) {
                $this->removeDirectory($this->workspace);
            }
        }
    }

    /** @return array<string, mixed> */
    private function legalResume(): array
    {
        $scenario = $this->newScenario('legal-resume');
        $this->installBaseline($scenario);
        $before = $this->startTask($scenario, 'UPGRADE-LEGAL-1', 'Resume the same governed Run after upgrading the installed release set.');

        $this->installCandidate($scenario);
        $this->agentLoop($scenario, ['init', 'doctor']);
        $afterUpgrade = $this->identity($scenario, 'UPGRADE-LEGAL-1');
        $this->assertSameIdentity($before, $afterUpgrade, 'status after package upgrade');

        $this->agentLoopJson($scenario, ['enter', 'UPGRADE-LEGAL-1', '--format=json']);
        $afterResume = $this->identity($scenario, 'UPGRADE-LEGAL-1');
        $this->assertSameIdentity($before, $afterResume, 'enter after package upgrade');

        file_put_contents($scenario->projectRoot . '/README.md', "legal resume completed\n");
        $this->completeTask($scenario, 'UPGRADE-LEGAL-1');
        $this->agentLoop($scenario, ['verify', '--task-id=UPGRADE-LEGAL-1']);

        return [
            'scenario' => 'interrupted_run_legal_resume',
            'from' => $before,
            'after_upgrade' => $afterUpgrade,
            'after_resume' => $afterResume,
            'result' => 'passed',
        ];
    }

    /** @return array<string, mixed> */
    private function prunedSessionResume(): array
    {
        $scenario = $this->newScenario('pruned-session');
        $this->installBaseline($scenario);
        $before = $this->startTask($scenario, 'UPGRADE-PRUNED-1', 'Rehydrate the exact historical Session after working memory is pruned.');
        $boundSessionId = $before['session_id'];

        $this->agentLoop($scenario, [
            'session', 'close', $boundSessionId,
            '--status', 'done',
            '--reason', 'release-upgrade prune fixture',
        ]);
        $prune = $this->agentLoop($scenario, ['session', 'prune', '--keep-days', '0', '--status', 'done']);
        if (!str_contains($prune->stdout, '- ' . $boundSessionId)) {
            throw new RuntimeException('Session owner did not prune the exact Run-bound Session ' . $boundSessionId . '.');
        }

        $this->installCandidate($scenario);

        $intruder = $this->agentLoop($scenario, [
            'session', 'start',
            '--task', 'UPGRADE-PRUNED-1',
            '--slug', 'unrelated-active-session',
            '--by', 'other-agent',
        ]);
        if (!preg_match('/^Started session: (\S+)$/m', $intruder->stdout, $matches)) {
            throw new RuntimeException('Unable to read the unrelated active Session id from the Session owner CLI.');
        }
        $intruderId = $this->nonEmpty($matches[1] ?? '', 'unrelated Session id');

        $refused = $this->agentLoop(
            $scenario,
            ['enter', 'UPGRADE-PRUNED-1', '--format=json'],
            [1],
        );
        $refusal = $refused->stdout . "\n" . $refused->stderr;
        if (!str_contains($refusal, 'different active Session') && !str_contains($refusal, $intruderId)) {
            throw new RuntimeException('Candidate did not visibly refuse the unrelated active Session stealing the governed Run.');
        }

        $this->agentLoop($scenario, [
            'session', 'close', $intruderId,
            '--status', 'dropped',
            '--reason', 'upgrade fixture cleanup',
        ]);
        $this->agentLoopJson($scenario, ['enter', 'UPGRADE-PRUNED-1', '--format=json']);
        $after = $this->identity($scenario, 'UPGRADE-PRUNED-1');
        $this->assertSameIdentity($before, $after, 'exact Session rehydration after prune');

        file_put_contents($scenario->projectRoot . '/README.md', "pruned session resume completed\n");
        $this->completeTask($scenario, 'UPGRADE-PRUNED-1');
        $this->agentLoop($scenario, ['verify', '--task-id=UPGRADE-PRUNED-1']);

        return [
            'scenario' => 'pruned_session_exact_id_rehydration',
            'before_prune' => $before,
            'after_rehydrate' => $after,
            'steal_refused' => true,
            'result' => 'passed',
        ];
    }

    /** @return array<string, mixed> */
    private function staleAuthorityAndSupersession(): array
    {
        $scenario = $this->newScenario('stale-authority');
        $this->installBaseline($scenario);
        $revisionOne = $this->startTask($scenario, 'UPGRADE-AUTH-1', 'Bind validation, review and Learning evidence to implementation A.');

        file_put_contents($scenario->projectRoot . '/README.md', "implementation A\n");
        $this->completeTask($scenario, 'UPGRADE-AUTH-1');
        $baselineComplete = $this->status($scenario, 'UPGRADE-AUTH-1');
        if (($baselineComplete['manifest']['state'] ?? null) !== 'complete') {
            throw new RuntimeException('Baseline authority scenario did not reach complete before upgrade.');
        }

        $this->installCandidate($scenario);
        $afterUpgrade = $this->status($scenario, 'UPGRADE-AUTH-1');
        if (($afterUpgrade['manifest']['state'] ?? null) !== 'complete') {
            throw new RuntimeException('Package upgrade alone invalidated a current completed Run.');
        }
        $this->assertSameIdentity($revisionOne, $this->identityFromStatus($afterUpgrade), 'completed Run after upgrade');

        file_put_contents($scenario->projectRoot . '/README.md', "implementation B\n");
        $stale = $this->status($scenario, 'UPGRADE-AUTH-1', [0, 2]);
        if (($stale['manifest']['state'] ?? null) === 'complete') {
            throw new RuntimeException('Implementation drift incorrectly preserved complete authority.');
        }
        $verificationState = $stale['manifest']['references']['verification']['state'] ?? null;
        if ($verificationState === 'passed') {
            throw new RuntimeException('Verification for implementation A became current for implementation B.');
        }

        $this->agentLoop($scenario, [
            'workflow', 'plan', 'UPGRADE-AUTH-1', '--supersede',
            '--by', 'upgrade-fixture',
            '--file', 'README.md',
            '--goal', 'Bind a replacement governed Run to approved Contract revision 2.',
            '--validation', $this->validationCommand(),
        ]);
        $this->agentLoop($scenario, ['workflow', 'approve', 'UPGRADE-AUTH-1', '--by', 'upgrade-fixture-human']);
        $this->agentLoopJson($scenario, ['enter', 'UPGRADE-AUTH-1', '--format=json']);
        $revisionTwo = $this->identity($scenario, 'UPGRADE-AUTH-1');

        if ($revisionTwo['contract_revision'] <= $revisionOne['contract_revision']) {
            throw new RuntimeException('Contract supersession did not advance the revision.');
        }
        if ($revisionTwo['run_id'] === $revisionOne['run_id']) {
            throw new RuntimeException('Contract supersession silently reused the old governed Run.');
        }
        $replacement = $this->status($scenario, 'UPGRADE-AUTH-1', [0, 2]);
        if (($replacement['manifest']['references']['verification']['state'] ?? null) === 'passed') {
            throw new RuntimeException('Old verification became current for the replacement Contract.');
        }

        $this->completeTask($scenario, 'UPGRADE-AUTH-1');
        $this->agentLoop($scenario, ['verify', '--task-id=UPGRADE-AUTH-1']);

        return [
            'scenario' => 'stale_authority_and_contract_supersession',
            'revision_one' => $revisionOne,
            'drift_state' => $stale['manifest']['state'] ?? null,
            'drift_verification_state' => $verificationState,
            'revision_two' => $revisionTwo,
            'result' => 'passed',
        ];
    }

    private function newScenario(string $name): UpgradeScenario
    {
        $root = $this->workspace . '/' . $name;
        $project = $root . '/project';
        $composer = $root . '/composer';
        $this->makeDirectory($project);
        $this->makeDirectory($composer);
        $this->execute(['git', 'init', '--initial-branch=main'], $project);
        $this->execute(['git', 'config', 'user.name', 'agent-loop upgrade fixture'], $project);
        $this->execute(['git', 'config', 'user.email', 'upgrade-fixture@example.invalid'], $project);
        file_put_contents($project . '/README.md', "upgrade fixture baseline\n");
        file_put_contents($project . '/.gitignore', "/vendor/\n");

        return new UpgradeScenario($name, $root, $project, $composer);
    }

    private function installBaseline(UpgradeScenario $scenario): void
    {
        $this->writeComposer($scenario, $this->fromVersion, false);
        $this->execute([
            'composer', 'update',
            '--working-dir=' . $scenario->composerRoot,
            '--no-interaction', '--prefer-dist', '--no-progress', '--no-ansi',
        ], $scenario->root);
        $this->assertComposerGraph($scenario, false);
        $this->agentLoop($scenario, ['init', 'scaffold', '--prefix=UPGRADE']);
        $this->execute(['git', 'add', '-A'], $scenario->projectRoot);
        $this->execute(['git', 'commit', '-m', 'fixture: baseline repository'], $scenario->projectRoot);
    }

    private function installCandidate(UpgradeScenario $scenario): void
    {
        $this->writeComposer($scenario, 'dev-' . $this->toBranch . '#' . $this->toRef, true);
        $this->execute([
            'composer', 'update', 'voku/agent-loop', '--with-all-dependencies',
            '--working-dir=' . $scenario->composerRoot,
            '--no-interaction', '--prefer-dist', '--no-progress', '--no-ansi',
        ], $scenario->root);
        $this->assertComposerGraph($scenario, true);
    }

    private function writeComposer(UpgradeScenario $scenario, string $constraint, bool $candidate): void
    {
        /** @var array<string, mixed> $config */
        $config = [
            'name' => 'voku/agent-loop-upgrade-' . $scenario->name,
            'type' => 'project',
            'require-dev' => ['voku/agent-loop' => $constraint],
            'config' => [
                'allow-plugins' => false,
                'sort-packages' => true,
                'vendor-dir' => '../project/vendor',
                'bin-dir' => '../project/vendor/bin',
            ],
            'prefer-stable' => true,
        ];
        if ($candidate) {
            $config['repositories'] = [['type' => 'vcs', 'url' => $this->repositoryUrl]];
            $config['minimum-stability'] = 'dev';
        }
        $this->writeJson($scenario->composerRoot . '/composer.json', $config);
    }

    private function assertComposerGraph(UpgradeScenario $scenario, bool $candidate): void
    {
        $lock = $this->readJson($scenario->composerRoot . '/composer.lock');
        /** @var array<string, array<string, mixed>> $packages */
        $packages = [];
        foreach (['packages', 'packages-dev'] as $section) {
            $rows = $lock[$section] ?? null;
            if (!is_array($rows)) {
                throw new RuntimeException('Composer lock section ' . $section . ' is missing.');
            }
            foreach ($rows as $row) {
                if (!is_array($row) || !is_string($row['name'] ?? null)) {
                    continue;
                }
                /** @var non-empty-string $name */
                $name = $row['name'];
                $packages[$name] = $row;
            }
        }

        $loop = $packages['voku/agent-loop'] ?? null;
        if (!is_array($loop)) {
            throw new RuntimeException('Composer lock does not contain voku/agent-loop.');
        }
        if ($candidate) {
            $source = $loop['source'] ?? null;
            $reference = is_array($source) ? ($source['reference'] ?? null) : null;
            if ($reference !== $this->toRef) {
                throw new RuntimeException('Candidate lock is not bound to exact head ' . $this->toRef . '.');
            }
        } elseif (($loop['version'] ?? null) !== $this->fromVersion) {
            throw new RuntimeException('FROM lock did not resolve exact release ' . $this->fromVersion . '.');
        }

        foreach ($packages as $name => $package) {
            if (!str_starts_with($name, 'voku/agent-') || $name === 'voku/agent-loop') {
                continue;
            }
            $version = $package['version'] ?? null;
            if (!is_string($version) || str_starts_with($version, 'dev-')) {
                throw new RuntimeException('Focused dependency is not released: ' . $name . ' ' . (string) $version . '.');
            }
            $dist = $package['dist'] ?? null;
            if (is_array($dist) && ($dist['type'] ?? null) === 'path') {
                throw new RuntimeException('Path package detected in lock: ' . $name . '.');
            }
        }

        $composer = $this->readJson($scenario->composerRoot . '/composer.json');
        $repositories = $composer['repositories'] ?? [];
        if (!is_array($repositories)) {
            throw new RuntimeException('Composer repositories must be an array.');
        }
        foreach ($repositories as $repository) {
            if (is_array($repository) && ($repository['type'] ?? null) === 'path') {
                throw new RuntimeException('Path repository detected in upgrade consumer.');
            }
        }
    }

    /** @return array{run_id: non-empty-string, session_id: non-empty-string, contract_revision: int} */
    private function startTask(UpgradeScenario $scenario, string $taskId, string $goal): array
    {
        $this->agentLoop($scenario, [
            'workflow', 'plan', $taskId,
            '--by', 'upgrade-fixture',
            '--file', 'README.md',
            '--goal', $goal,
            '--validation', $this->validationCommand(),
        ]);
        $this->agentLoop($scenario, ['workflow', 'approve', $taskId, '--by', 'upgrade-fixture-human']);
        $enter = $this->agentLoopJson($scenario, ['enter', $taskId, '--format=json']);
        if (($enter['mutation_ready'] ?? false) !== true) {
            throw new RuntimeException($taskId . ' did not become mutation-ready before interruption.');
        }

        return $this->identity($scenario, $taskId);
    }

    private function completeTask(UpgradeScenario $scenario, string $taskId): void
    {
        $first = $this->agentLoopJson($scenario, ['finish', $taskId, '--format=json'], [0, 1]);
        if (($first['complete'] ?? false) === true) {
            return;
        }
        $presentation = $first['review_presentation'] ?? null;
        $reviewSha = is_array($presentation) ? ($presentation['review_sha256'] ?? null) : null;
        if (!is_string($reviewSha) || $reviewSha === '') {
            throw new RuntimeException($taskId . ' did not expose a current review SHA during close-out.');
        }
        $complete = $this->agentLoopJson($scenario, [
            'finish', $taskId, '--format=json',
            '--reviewed-report-sha256', $reviewSha,
            '--learning', 'no_durable_learning',
            '--learning-reason', 'Upgrade dogfood produced no reusable project guidance.',
            '--by', 'upgrade-fixture-reviewer',
        ]);
        if (($complete['complete'] ?? false) !== true || ($complete['next_action'] ?? null) !== 'none') {
            throw new RuntimeException($taskId . ' did not complete through the installed finish boundary.');
        }
    }

    /** @return array{run_id: non-empty-string, session_id: non-empty-string, contract_revision: int} */
    private function identity(UpgradeScenario $scenario, string $taskId): array
    {
        return $this->identityFromStatus($this->status($scenario, $taskId));
    }

    /**
     * @param array<string, mixed> $status
     * @return array{run_id: non-empty-string, session_id: non-empty-string, contract_revision: int}
     */
    private function identityFromStatus(array $status): array
    {
        $manifest = $status['manifest'] ?? null;
        if (!is_array($manifest)) {
            throw new RuntimeException('Workflow status has no manifest object.');
        }
        $runId = $this->nonEmpty(is_string($manifest['run_id'] ?? null) ? $manifest['run_id'] : '', 'Run id');
        $references = $manifest['references'] ?? null;
        if (!is_array($references)) {
            throw new RuntimeException('Workflow status has no references object.');
        }
        $session = $references['session'] ?? null;
        $contract = $references['contract'] ?? null;
        $sessionId = is_array($session) && is_string($session['session_id'] ?? null) ? $session['session_id'] : '';
        $revision = is_array($contract) ? ($contract['revision'] ?? $contract['contract_revision'] ?? null) : null;
        if (!is_int($revision)) {
            throw new RuntimeException('Workflow status has no integer Contract revision.');
        }

        return [
            'run_id' => $runId,
            'session_id' => $this->nonEmpty($sessionId, 'Session id'),
            'contract_revision' => $revision,
        ];
    }

    /** @param list<int> $allowedExitCodes @return array<string, mixed> */
    private function status(UpgradeScenario $scenario, string $taskId, array $allowedExitCodes = [0]): array
    {
        return $this->agentLoopJson($scenario, ['workflow', 'status', $taskId, '--format=json'], $allowedExitCodes);
    }

    /**
     * @param array{run_id: non-empty-string, session_id: non-empty-string, contract_revision: int} $expected
     * @param array{run_id: non-empty-string, session_id: non-empty-string, contract_revision: int} $actual
     */
    private function assertSameIdentity(array $expected, array $actual, string $context): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(sprintf(
                'Authority identity changed during %s: expected %s, got %s.',
                $context,
                json_encode($expected, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ));
        }
    }

    private function validationCommand(): string
    {
        return 'php -r "exit(is_file(\'README.md\') ? 0 : 1);"';
    }

    /** @param list<string> $args @param list<int> $allowedExitCodes */
    private function agentLoop(UpgradeScenario $scenario, array $args, array $allowedExitCodes = [0]): UpgradeCommandResult
    {
        return $this->execute([$scenario->projectRoot . '/vendor/bin/agent-loop', ...$args], $scenario->projectRoot, $allowedExitCodes);
    }

    /** @param list<string> $args @param list<int> $allowedExitCodes @return array<string, mixed> */
    private function agentLoopJson(UpgradeScenario $scenario, array $args, array $allowedExitCodes = [0]): array
    {
        $result = $this->agentLoop($scenario, $args, $allowedExitCodes);
        $decoded = json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('agent-loop JSON output did not decode to an object.');
        }

        return $decoded;
    }

    /** @param list<string> $command @param list<int> $allowedExitCodes */
    private function execute(array $command, string $cwd, array $allowedExitCodes = [0]): UpgradeCommandResult
    {
        /** @var array<int, resource> $pipes */
        $pipes = [];
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start command: ' . implode(' ', $command));
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if (!is_string($stdout) || !is_string($stderr)) {
            throw new RuntimeException('Unable to capture command output: ' . implode(' ', $command));
        }

        $this->commands[] = [
            'command' => $command,
            'cwd' => $cwd,
            'exit_code' => $exitCode,
            'stdout_sha256' => 'sha256:' . hash('sha256', $stdout),
            'stderr_sha256' => 'sha256:' . hash('sha256', $stderr),
        ];
        if (!in_array($exitCode, $allowedExitCodes, true)) {
            throw new RuntimeException(sprintf(
                "Command failed with exit %d: %s\nSTDOUT:\n%s\nSTDERR:\n%s",
                $exitCode,
                implode(' ', $command),
                $stdout,
                $stderr,
            ));
        }

        return new UpgradeCommandResult($exitCode, $stdout, $stderr);
    }

    /** @param array<string, mixed> $value */
    private function writeJson(string $path, array $value): void
    {
        $this->makeDirectory(dirname($path));
        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $encoded . "\n") === false) {
            throw new RuntimeException('Unable to write JSON file: ' . $path);
        }
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read JSON file: ' . $path);
        }
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('JSON file did not decode to an object: ' . $path);
        }

        return $decoded;
    }

    private function writeReport(string $result, ?string $error): void
    {
        $this->writeJson($this->reportPath, [
            'schema_version' => '1.0',
            'result' => $result,
            'from_version' => $this->fromVersion,
            'to' => [
                'repository' => $this->repositoryUrl,
                'branch' => $this->toBranch,
                'ref' => $this->toRef,
            ],
            'scenarios' => $this->scenarios,
            'commands' => $this->commands,
            'error' => $error,
        ]);
    }

    /** @return non-empty-string */
    private function nonEmpty(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new RuntimeException($label . ' must not be empty.');
        }

        return $value;
    }

    private function makeDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (!mkdir($path, 0o775, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create directory: ' . $path);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        if ($entries === false) {
            throw new RuntimeException('Unable to list directory: ' . $path);
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            if (is_dir($child) && !is_link($child)) {
                $this->removeDirectory($child);
                continue;
            }
            if (!unlink($child)) {
                throw new RuntimeException('Unable to remove file: ' . $child);
            }
        }
        if (!rmdir($path)) {
            throw new RuntimeException('Unable to remove directory: ' . $path);
        }
    }
}

/**
 * @param list<string> $argv
 * @return array<string, string|bool>
 */
function parseUpgradeOptions(array $argv): array
{
    $options = ['keep' => false];
    foreach (array_slice($argv, 1) as $token) {
        if ($token === '--keep') {
            $options['keep'] = true;
            continue;
        }
        if (!str_starts_with($token, '--') || !str_contains($token, '=')) {
            throw new InvalidArgumentException('Unknown argument: ' . $token);
        }
        [$key, $value] = explode('=', substr($token, 2), 2);
        if (!in_array($key, ['workspace', 'from-version', 'to-ref', 'to-branch', 'repository-url', 'report'], true)) {
            throw new InvalidArgumentException('Unknown option: --' . $key);
        }
        if ($value === '') {
            throw new InvalidArgumentException('--' . $key . ' requires a non-empty value.');
        }
        $options[$key] = $value;
    }

    return $options;
}

/** @param array<string, string|bool> $options @return non-empty-string */
function requiredUpgradeOption(array $options, string $key): string
{
    $value = $options[$key] ?? null;
    if (!is_string($value) || trim($value) === '') {
        throw new InvalidArgumentException('Missing required option --' . $key . '=...');
    }

    return trim($value);
}

try {
    $options = parseUpgradeOptions($argv);
    exit((new ReleaseUpgradeDogfood(
        requiredUpgradeOption($options, 'workspace'),
        requiredUpgradeOption($options, 'from-version'),
        requiredUpgradeOption($options, 'to-ref'),
        requiredUpgradeOption($options, 'to-branch'),
        requiredUpgradeOption($options, 'repository-url'),
        requiredUpgradeOption($options, 'report'),
        ($options['keep'] ?? false) === true,
    ))->run());
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAIL] release upgrade proof setup: ' . $exception->getMessage() . "\n");
    exit(1);
}
