<?php

declare(strict_types=1);

final class LlmL1ExperimentFailure extends RuntimeException
{
}

final readonly class LlmL1Experiment
{
    private const string TASK = 'LLM-L1-EXPERIMENT';
    private const string SOURCE = 'tests/fixtures/self-shape/SelfEditProbe.php';
    private const string POLICY = 'docs/policies/pre-1.0-compatibility.md';

    public function __construct(
        private string $repositoryRoot,
        private string $worktree,
        private string $evidenceDirectory,
        private string $operatingPromptManifest,
    ) {
    }

    public function run(string $phase): int
    {
        try {
            match ($phase) {
                'prepare' => $this->prepare(),
                'bind' => $this->bind(),
                'verify' => $this->verify(),
                'cleanup' => $this->cleanup(),
                default => throw new LlmL1ExperimentFailure('Unknown phase: ' . $phase),
            };

            fwrite(STDOUT, sprintf("LLM L2->L1 experiment %s: OK\n", $phase));

            return 0;
        } catch (Throwable $throwable) {
            fwrite(STDERR, sprintf(
                "LLM L2->L1 experiment %s: FAILED - %s\n",
                $phase,
                $throwable->getMessage(),
            ));

            return 1;
        }
    }

    private function prepare(): void
    {
        $this->cleanup();
        $this->makeDirectory($this->evidenceDirectory);

        $this->runCommand(
            ['git', 'worktree', 'add', '--quiet', '--detach', $this->worktree, 'HEAD'],
            $this->repositoryRoot,
        );

        if (!symlink($this->repositoryRoot . '/vendor', $this->worktree . '/vendor')) {
            throw new LlmL1ExperimentFailure('Unable to expose the candidate Composer vendor tree in the experiment worktree.');
        }

        $this->runCommand([
            PHP_BINARY,
            'vendor/bin/agent-learning',
            'lineage-rebuild',
            '--root=.agent-loop/learning',
            '--project-root=' . $this->worktree,
        ], $this->worktree);

        $this->runCommand([
            PHP_BINARY,
            'bin/agent-loop',
            'map',
            'build',
            '--paths=tests/fixtures/self-shape',
        ], $this->worktree);

        $this->runCommand([
            PHP_BINARY,
            'bin/agent-loop',
            'workflow',
            'plan',
            self::TASK,
            '--by',
            'llm-experiment-planner',
            '--file',
            self::SOURCE,
            '--goal',
            'Change the self-edit probe from 100 + input to 101 + input without widening scope.',
            '--non-goal',
            'Do not modify any file except the self-edit probe.',
            '--validation',
            'php -l ' . self::SOURCE,
            '--validation',
            'vendor/bin/phpunit tests/ExecutionContractStoreTest.php',
            '--operating-prompt-manifest',
            $this->operatingPromptManifest,
            '--operating-prompt',
            '{"id":"breaking-change-review","arguments":{}}',
        ], $this->worktree);

        $this->runCommand([
            PHP_BINARY,
            'bin/agent-loop',
            'workflow',
            'approve',
            self::TASK,
            '--by',
            'llm-experiment-approver',
        ], $this->worktree);

        $enter = $this->runCommand([
            PHP_BINARY,
            'bin/agent-loop',
            'enter',
            self::TASK,
            '--format=json',
        ], $this->worktree, false);
        if ($enter['exit_code'] !== 1) {
            throw new LlmL1ExperimentFailure(
                'The prepared L2 task must remain blocked until an L1 execution contract exists; got enter exit '
                . $enter['exit_code'] . '.',
            );
        }

        $enterPayload = $this->decodeJson($enter['stdout'], 'enter output');
        if (($enterPayload['next_action_kind'] ?? null) !== 'command_template') {
            throw new LlmL1ExperimentFailure('Prepared task did not request the L1 contract as a command_template.');
        }

        $recallRoot = $this->worktree . '/.agent-loop/recall/' . self::TASK;
        $system = $this->read($recallRoot . '/system.md');
        $validationPlan = $this->read($recallRoot . '/validation-plan.md');
        $policy = $this->read($this->worktree . '/' . self::POLICY);
        $source = $this->read($this->worktree . '/' . self::SOURCE);

        foreach ([
            'L2 marker' => '## L2 Operational Prompt Construction',
            'recipe id' => 'breaking-change-review',
            'approved source' => self::SOURCE,
            'task id' => self::TASK,
        ] as $label => $needle) {
            if (!str_contains($system . "\n" . $validationPlan, $needle)) {
                throw new LlmL1ExperimentFailure(sprintf(
                    'Compiled L2 evidence is missing %s (%s).',
                    $label,
                    $needle,
                ));
            }
        }

        $status = $this->status();
        if (($status['manifest']['references']['execution_contract']['state'] ?? null) !== 'missing') {
            throw new LlmL1ExperimentFailure('Execution contract must be missing before the model constructs L1.');
        }
        if ($this->changedPaths() !== []) {
            throw new LlmL1ExperimentFailure('Experiment worktree changed before L1 construction.');
        }

        $this->writeEvidence('l2-system.md', $system);
        $this->writeEvidence('validation-plan.md', $validationPlan);
        $this->writeEvidence('project-policy.md', $policy);
        $this->writeEvidence('source-before.php', $source);
        $this->writeEvidence('enter-before-l1.json', $this->prettyJson($enterPayload));
        $this->writeEvidence('status-before-l1.json', $this->prettyJson($status));

        $constructorPrompt = <<<'PROMPT'
You are the L1 constructor in a controlled workflow experiment.

The approved task and its L2 construction briefing are supplied below. Produce the concrete project-specific L1 execution contract that an implementation agent should receive.

Rules:
- Output Markdown only. No preface, explanation, commentary, or fenced code block.
- Use exactly these top-level sections, in this order:
  ## Goal
  ## Context
  ## Constraints
  ## Verification
  ## Done When
- Preserve the approved scope and non-goals exactly.
- Ground every instruction in the supplied L2/project evidence. Do not invent files, commands, compatibility promises, or additional work.
- The requested private fixture change is not a public compatibility obligation. Apply the supplied pre-1.0 policy rather than inventing a compatibility layer.
- Verification must include the exact declared validation commands and a changed-file scope check.
- This is construction of L1 only. Do not implement the code change.

PROMPT;

        $constructorPrompt .= "\n\n# L2 system.md\n\n" . $system;
        $constructorPrompt .= "\n\n# validation-plan.md\n\n" . $validationPlan;
        $constructorPrompt .= "\n\n# Project policy\n\n" . $policy;
        $constructorPrompt .= "\n\n# Current approved source\n\n```php\n" . $source . "\n```\n";

        $this->writeEvidence('constructor-prompt.md', $constructorPrompt);
        $this->writeState([
            'schema_version' => '1.0',
            'task_id' => self::TASK,
            'phase' => 'prepared',
            'base_commit' => trim($this->runCommand(['git', 'rev-parse', 'HEAD'], $this->worktree)['stdout']),
            'source_before_sha256' => 'sha256:' . hash('sha256', $source),
            'l2_system_sha256' => 'sha256:' . hash('sha256', $system),
            'validation_plan_sha256' => 'sha256:' . hash('sha256', $validationPlan),
        ]);
    }

    private function bind(): void
    {
        $l1Path = $this->evidenceDirectory . '/l1.md';
        $l1 = $this->read($l1Path);

        if (str_contains($l1, '```')) {
            throw new LlmL1ExperimentFailure('Constructor returned a fenced response instead of raw L1 Markdown.');
        }

        foreach (['Goal', 'Context', 'Constraints', 'Verification', 'Done When'] as $section) {
            if (substr_count($l1, '## ' . $section) !== 1) {
                throw new LlmL1ExperimentFailure('Generated L1 must contain exactly one ## ' . $section . ' section.');
            }
        }

        foreach ([
            'approved source' => self::SOURCE,
            'requested old behavior' => '100 + input',
            'requested new behavior' => '101 + input',
            'lint validation' => 'php -l ' . self::SOURCE,
            'PHPUnit validation' => 'vendor/bin/phpunit tests/ExecutionContractStoreTest.php',
        ] as $label => $needle) {
            if (!str_contains($l1, $needle)) {
                throw new LlmL1ExperimentFailure(sprintf('Generated L1 lost %s (%s).', $label, $needle));
            }
        }

        $this->runCommand([
            PHP_BINARY,
            'bin/agent-loop',
            'workflow',
            'contract',
            self::TASK,
            '--status',
            'ready',
            '--from',
            $l1Path,
            '--by',
            'copilot-l1-constructor',
        ], $this->worktree);

        $status = $this->status();
        if (($status['manifest']['references']['execution_contract']['state'] ?? null) !== 'ready') {
            throw new LlmL1ExperimentFailure('Generated L1 was not accepted as the current ready execution contract.');
        }
        if (($status['policy']['mutation_allowed'] ?? false) !== true) {
            throw new LlmL1ExperimentFailure('Lifecycle did not authorize host-native mutation after binding generated L1.');
        }

        $persisted = $this->read($this->worktree . '/.agent-loop/recall/' . self::TASK . '/execution-contract.md');
        if (!hash_equals(hash('sha256', $l1), hash('sha256', $persisted))) {
            throw new LlmL1ExperimentFailure('Persisted execution contract does not match the model-produced L1 bytes.');
        }

        $source = $this->read($this->worktree . '/' . self::SOURCE);
        $executorPrompt = <<<'PROMPT'
Execute the exact governed L1 contract below.

Rules:
- The L1 is authoritative for this task.
- Make only the requested implementation change.
- Modify no file except tests/fixtures/self-shape/SelfEditProbe.php.
- Do not create compatibility aliases, adapters, fallbacks, documentation, tests, or unrelated refactors.
- Do not rewrite or reinterpret the task.
- You have no shell permission in this experiment. Apply the source edit only; deterministic validation runs after you exit.
- When the file edit is complete, return a short factual summary only.

# Governed L1

PROMPT;
        $executorPrompt .= "\n" . $persisted;
        $executorPrompt .= "\n\n# Current source\n\n```php\n" . $source . "\n```\n";

        $this->writeEvidence('persisted-l1.md', $persisted);
        $this->writeEvidence('status-after-l1.json', $this->prettyJson($status));
        $this->writeEvidence('executor-prompt.md', $executorPrompt);

        $state = $this->readState();
        $state['phase'] = 'l1_ready';
        $state['l1_sha256'] = 'sha256:' . hash('sha256', $l1);
        $state['persisted_l1_sha256'] = 'sha256:' . hash('sha256', $persisted);
        $this->writeState($state);
    }

    private function verify(): void
    {
        $sourceAfter = $this->read($this->worktree . '/' . self::SOURCE);
        if (str_contains($sourceAfter, 'return 100 + $input;')) {
            throw new LlmL1ExperimentFailure('Executor left the old implementation in place.');
        }
        if (substr_count($sourceAfter, 'return 101 + $input;') !== 1) {
            throw new LlmL1ExperimentFailure('Executor did not produce exactly one requested 101 + input implementation.');
        }

        $changedPaths = $this->changedPaths();
        if ($changedPaths !== [self::SOURCE]) {
            throw new LlmL1ExperimentFailure(
                'Executor escaped approved scope: ' . ($changedPaths === [] ? '<no changes>' : implode(', ', $changedPaths)),
            );
        }

        $lint = $this->runCommand([PHP_BINARY, '-l', self::SOURCE], $this->worktree);
        $phpunit = $this->runCommand([
            PHP_BINARY,
            'vendor/bin/phpunit',
            'tests/ExecutionContractStoreTest.php',
        ], $this->worktree);

        $diff = $this->runCommand(['git', 'diff', '--', self::SOURCE], $this->worktree)['stdout'];
        $status = $this->status();
        if (($status['manifest']['references']['execution_contract']['state'] ?? null) !== 'ready') {
            throw new LlmL1ExperimentFailure('Execution contract stopped being current after implementation.');
        }

        $l1 = $this->read($this->evidenceDirectory . '/persisted-l1.md');
        $state = $this->readState();
        $report = [
            'schema_version' => '1.0',
            'experiment' => 'real_llm_l2_to_l1_to_execution',
            'task_id' => self::TASK,
            'base_commit' => $state['base_commit'] ?? null,
            'constructor_model' => getenv('LLM_CONSTRUCTOR_MODEL') ?: null,
            'executor_model' => getenv('LLM_EXECUTOR_MODEL') ?: null,
            'copilot_version' => getenv('COPILOT_VERSION') ?: null,
            'l2_system_sha256' => $state['l2_system_sha256'] ?? null,
            'validation_plan_sha256' => $state['validation_plan_sha256'] ?? null,
            'l1_sha256' => 'sha256:' . hash('sha256', $l1),
            'source_before_sha256' => $state['source_before_sha256'] ?? null,
            'source_after_sha256' => 'sha256:' . hash('sha256', $sourceAfter),
            'changed_paths' => $changedPaths,
            'checks' => [
                'l1_bound_ready' => true,
                'mutation_authorized_before_execution' => true,
                'executor_scope_exact' => true,
                'requested_change_present' => true,
                'php_lint_exit' => $lint['exit_code'],
                'phpunit_exit' => $phpunit['exit_code'],
                'execution_contract_still_current' => true,
            ],
            'result' => 'passed',
        ];

        $this->writeEvidence('source-after.php', $sourceAfter);
        $this->writeEvidence('executor.diff', $diff);
        $this->writeEvidence('status-after-execution.json', $this->prettyJson($status));
        $this->writeEvidence('report.json', $this->prettyJson($report));
    }

    private function cleanup(): void
    {
        if (is_dir($this->worktree) || is_file($this->worktree . '/.git')) {
            $this->runCommand(
                ['git', 'worktree', 'remove', '--force', $this->worktree],
                $this->repositoryRoot,
                false,
            );
        }
    }

    /** @return array<string, mixed> */
    private function status(): array
    {
        $result = $this->runCommand([
            PHP_BINARY,
            'bin/agent-loop',
            'workflow',
            'status',
            self::TASK,
            '--format=json',
        ], $this->worktree);

        return $this->decodeJson($result['stdout'], 'workflow status');
    }

    /** @return list<string> */
    private function changedPaths(): array
    {
        $result = $this->runCommand(['git', 'diff', '--name-only'], $this->worktree);
        $paths = preg_split('/\R/', trim($result['stdout'])) ?: [];

        return array_values(array_filter($paths, static fn (string $path): bool => $path !== ''));
    }

    /** @param list<string> $command
     * @return array{exit_code:int, stdout:string, stderr:string}
     */
    private function runCommand(array $command, string $workingDirectory, bool $throwOnFailure = true): array
    {
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $workingDirectory,
        );
        if (!is_resource($process)) {
            throw new LlmL1ExperimentFailure('Unable to start command: ' . implode(' ', $command));
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $stdout = is_string($stdout) ? $stdout : '';
        $stderr = is_string($stderr) ? $stderr : '';

        if ($stdout !== '') {
            fwrite(STDOUT, $stdout);
        }
        if ($stderr !== '') {
            fwrite(STDERR, $stderr);
        }

        if ($throwOnFailure && $exitCode !== 0) {
            throw new LlmL1ExperimentFailure(sprintf(
                'Command failed with exit %d: %s',
                $exitCode,
                implode(' ', $command),
            ));
        }

        return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $json, string $label): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LlmL1ExperimentFailure($label . ' is not valid JSON: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new LlmL1ExperimentFailure($label . ' must decode to an object.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $data */
    private function prettyJson(array $data): string
    {
        return json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    private function read(string $path): string
    {
        $content = file_get_contents($path);
        if (!is_string($content) || trim($content) === '') {
            throw new LlmL1ExperimentFailure('Unable to read non-empty experiment artifact: ' . $path);
        }

        return $content;
    }

    private function writeEvidence(string $name, string $content): void
    {
        $this->makeDirectory($this->evidenceDirectory);
        if (file_put_contents($this->evidenceDirectory . '/' . $name, $content) === false) {
            throw new LlmL1ExperimentFailure('Unable to write experiment evidence: ' . $name);
        }
    }

    /** @param array<string, mixed> $state */
    private function writeState(array $state): void
    {
        $this->writeEvidence('state.json', $this->prettyJson($state));
    }

    /** @return array<string, mixed> */
    private function readState(): array
    {
        return $this->decodeJson($this->read($this->evidenceDirectory . '/state.json'), 'experiment state');
    }

    private function makeDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0o775, true) && !is_dir($path)) {
            throw new LlmL1ExperimentFailure('Unable to create directory: ' . $path);
        }
    }
}

/** @return non-empty-string */
function requiredOption(array $argv, string $name): string
{
    $prefix = '--' . $name . '=';
    foreach ($argv as $token) {
        if (str_starts_with($token, $prefix)) {
            $value = trim(substr($token, strlen($prefix)));
            if ($value !== '') {
                return $value;
            }
        }
    }

    throw new LlmL1ExperimentFailure('Missing required option --' . $name . '=...');
}

$repositoryRoot = realpath(dirname(__DIR__));
if (!is_string($repositoryRoot)) {
    fwrite(STDERR, "Unable to resolve repository root.\n");
    exit(2);
}

try {
    $phase = $argv[1] ?? '';
    if (!is_string($phase) || $phase === '') {
        throw new LlmL1ExperimentFailure(
            'Usage: php tools/llm-l1-experiment.php <prepare|bind|verify|cleanup> --worktree=... --evidence-dir=... --manifest=...',
        );
    }

    $worktree = requiredOption($argv, 'worktree');
    $evidenceDirectory = requiredOption($argv, 'evidence-dir');
    $manifest = requiredOption($argv, 'manifest');

    if (!str_starts_with($evidenceDirectory, '/')) {
        $evidenceDirectory = $repositoryRoot . '/' . ltrim($evidenceDirectory, '/');
    }
    if (!str_starts_with($manifest, '/')) {
        $manifest = $repositoryRoot . '/' . ltrim($manifest, '/');
    }

    exit((new LlmL1Experiment(
        repositoryRoot: $repositoryRoot,
        worktree: $worktree,
        evidenceDirectory: $evidenceDirectory,
        operatingPromptManifest: $manifest,
    ))->run($phase));
} catch (Throwable $throwable) {
    fwrite(STDERR, 'LLM L2->L1 experiment bootstrap: FAILED - ' . $throwable->getMessage() . "\n");
    exit(2);
}
