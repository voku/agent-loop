<?php

declare(strict_types=1);

final class ExecutionContractDogfoodFailure extends RuntimeException
{
}

final readonly class ExecutionContractDogfood
{
    private const string TASK = 'EXECUTION-CONTRACT-DOGFOOD';
    private const string TARGET = 'voku\\AgentLoop\\Tests\\Fixtures\\SelfShape\\SelfEditProbe::value';
    private const string SOURCE = 'tests/fixtures/self-shape/SelfEditProbe.php';

    public function __construct(
        private string $repositoryRoot,
        private string $operatingPromptManifest,
    ) {
    }

    public function run(): int
    {
        $worktree = sys_get_temp_dir() . '/agent-loop-execution-contract-' . bin2hex(random_bytes(6));

        try {
            $this->runCommand(['git', 'worktree', 'add', '--quiet', '--detach', $worktree, 'HEAD'], $this->repositoryRoot);
            if (!symlink($this->repositoryRoot . '/vendor', $worktree . '/vendor')) {
                throw new ExecutionContractDogfoodFailure('Unable to expose the candidate Composer vendor tree in the dogfood worktree.');
            }

            $sourceBefore = $this->source($worktree);
            if (!str_contains($sourceBefore, 'return 100 + $input;')) {
                throw new ExecutionContractDogfoodFailure('Dogfood fixture is not at the expected validated starting state.');
            }

            $this->runCommand([
                PHP_BINARY, 'bin/agent-loop', 'workflow', 'plan', self::TASK,
                '--by', 'dogfood-planner',
                '--file', self::SOURCE,
                '--goal', 'Change the self-edit probe from 100 + input to 101 + input without widening scope.',
                '--non-goal', 'Do not modify any file except the self-edit probe.',
                '--validation', 'php -l ' . self::SOURCE,
                '--validation', 'vendor/bin/phpunit tests/ExecutionContractStoreTest.php',
                '--operating-prompt-manifest', $this->operatingPromptManifest,
                '--operating-prompt', '{"id":"multi-pass-correctness-simplify","arguments":{}}',
            ], $worktree);

            $this->runCommand([
                PHP_BINARY, 'bin/agent-loop', 'workflow', 'approve', self::TASK,
                '--by', 'dogfood-approver',
            ], $worktree);

            $system = $this->read($worktree . '/.agent-loop/recall/' . self::TASK . '/system.md');
            if (!str_contains($system, '## L2 Operational Prompt Construction')) {
                throw new ExecutionContractDogfoodFailure('Approved recall did not compile an L2 construction contract.');
            }
            if (!str_contains($system, 'multi-pass-correctness-simplify')) {
                throw new ExecutionContractDogfoodFailure('Approved recall lost the selected operating-prompt recipe.');
            }
            foreach (['**Goal**', '**Context**', '**Constraints**', '**Verification**', '**Done When**'] as $section) {
                if (!str_contains($system, $section)) {
                    throw new ExecutionContractDogfoodFailure('Compiled L2 contract is missing canonical section instruction: ' . $section);
                }
            }

            // Everything asserted above ships with the recipe template, so a
            // compiler that emitted pure boilerplate would pass it. L2 is a
            // meta-prompt: it tells the agent to build L1 from "exact project
            // anchors already supported by recall evidence". That instruction is
            // only honest if the briefing actually handed over carries those
            // anchors, so assert against the files approve tells you to pass in.
            $briefing = $system . "\n" . $this->read(
                $worktree . '/.agent-loop/recall/' . self::TASK . '/validation-plan.md',
            );
            $projectSpecific = [
                'task id' => self::TASK,
                'approved scope' => self::SOURCE,
                'contract goal' => 'without widening scope',
                'declared non-goal' => 'Do not modify any file except the self-edit probe.',
                'required validation' => 'vendor/bin/phpunit tests/ExecutionContractStoreTest.php',
            ];
            $absent = [];
            foreach ($projectSpecific as $label => $needle) {
                if (!str_contains($briefing, $needle)) {
                    $absent[] = $label . ' (' . $needle . ')';
                }
            }
            if ($absent !== []) {
                throw new ExecutionContractDogfoodFailure(
                    'Compiled briefing is generic: it carries the recipe template but not this project\'s '
                    . implode(', ', $absent)
                    . '. An L2 meta-prompt that says "use exact project anchors" cannot be honoured '
                    . 'when the briefing handed to the agent never states them.',
                );
            }

            $missing = $this->status($worktree);
            if (($missing['manifest']['references']['execution_contract']['state'] ?? null) !== 'missing') {
                throw new ExecutionContractDogfoodFailure('Approved L2 task must expose a missing execution-contract gate before implementation.');
            }

            $blockedEdit = $this->runCommand($this->editCommand(), $worktree, throwOnFailure: false);
            if ($blockedEdit['exit_code'] === 0) {
                throw new ExecutionContractDogfoodFailure('Mechanical mutation succeeded before the L1 execution contract existed.');
            }
            if (!str_contains($blockedEdit['stderr'], 'execution contract is missing')) {
                throw new ExecutionContractDogfoodFailure('Pre-contract mutation failed for the wrong reason: ' . trim($blockedEdit['stderr']));
            }
            if ($this->source($worktree) !== $sourceBefore) {
                throw new ExecutionContractDogfoodFailure('Source changed even though the execution-contract gate rejected mutation.');
            }

            $contractPath = $worktree . '/build/execution-contract-dogfood.md';
            $this->makeDirectory(dirname($contractPath));
            file_put_contents($contractPath, <<<'MD'
## Goal
Change `tests/fixtures/self-shape/SelfEditProbe.php` from `100 + input` to `101 + input` and change nothing else.

## Context
The approved WorkBrief scopes the task to `tests/fixtures/self-shape/SelfEditProbe.php`. The selected L2 recipe requires implementation, correctness review, then simplification against current repository evidence.

## Constraints
Keep the method signature and public behavior outside the requested constant change unchanged. Do not modify unrelated files or add abstractions.

## Verification
Run `php -l tests/fixtures/self-shape/SelfEditProbe.php`. Run `vendor/bin/phpunit tests/ExecutionContractStoreTest.php`. Inspect Git changed-file evidence and require exactly the approved source file.

## Done When
The probe contains exactly one `return 101 + $input;`, no `return 100 + $input;` remains, the exact verification commands pass, and Git reports no unrelated changed source file.
MD
            );

            $this->runCommand([
                PHP_BINARY, 'bin/agent-loop', 'workflow', 'contract', self::TASK,
                '--status', 'ready', '--from', $contractPath, '--by', 'dogfood-constructor',
            ], $worktree);

            $ready = $this->status($worktree);
            if (($ready['manifest']['references']['execution_contract']['state'] ?? null) !== 'ready') {
                throw new ExecutionContractDogfoodFailure('Persisted L1 contract did not satisfy the governed contract gate.');
            }

            $acceptedEdit = $this->runCommand($this->editCommand(), $worktree);
            if ($acceptedEdit['exit_code'] !== 0) {
                throw new ExecutionContractDogfoodFailure('Mechanical mutation failed after a current execution contract was recorded.');
            }

            $sourceAfter = $this->source($worktree);
            if (str_contains($sourceAfter, 'return 100 + $input;') || substr_count($sourceAfter, 'return 101 + $input;') !== 1) {
                throw new ExecutionContractDogfoodFailure('Post-contract mutation did not produce the expected bounded source change.');
            }

            $changed = $this->runCommand(['git', 'diff', '--name-only'], $worktree);
            $changedPaths = array_values(array_filter(preg_split('/\R/', trim($changed['stdout'])) ?: []));
            if ($changedPaths !== [self::SOURCE]) {
                throw new ExecutionContractDogfoodFailure('Dogfood mutation escaped approved source scope: ' . implode(', ', $changedPaths));
            }

            $report = [
                'schema_version' => '1.0',
                'task_id' => self::TASK,
                'pre_contract_state' => 'missing',
                'pre_contract_mutation_exit' => $blockedEdit['exit_code'],
                'pre_contract_source_sha256' => hash('sha256', $sourceBefore),
                'post_contract_state' => 'ready',
                'post_contract_mutation_exit' => $acceptedEdit['exit_code'],
                'post_contract_source_sha256' => hash('sha256', $sourceAfter),
                'changed_paths' => $changedPaths,
                'result' => 'passed',
            ];
            $build = $this->repositoryRoot . '/build';
            $this->makeDirectory($build);
            file_put_contents(
                $build . '/execution-contract-dogfood.json',
                json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            );

            fwrite(STDOUT, "Execution-contract dogfood: PASSED\n");

            return 0;
        } catch (Throwable $throwable) {
            fwrite(STDERR, 'Execution-contract dogfood: FAILED - ' . $throwable->getMessage() . "\n");

            return 1;
        } finally {
            if (is_file($worktree . '/.git') || is_dir($worktree . '/.git')) {
                $this->runCommand(['git', 'worktree', 'remove', '--force', $worktree], $this->repositoryRoot, throwOnFailure: false);
            }
        }
    }

    /** @return list<string> */
    private function editCommand(): array
    {
        return [
            PHP_BINARY,
            'bin/agent-loop',
            'edit',
            self::TARGET,
            '--task=' . self::TASK,
            '--map-paths=tests/fixtures/self-shape',
            '--phpstan-memory-limit=512M',
            '--runner=mechanical',
            '--replace-old=return 100 + $input;',
            '--replace-new=return 101 + $input;',
            '--',
            'Execute only the approved project-specific L1 contract.',
        ];
    }

    /** @return array<string, mixed> */
    private function status(string $worktree): array
    {
        $result = $this->runCommand([
            PHP_BINARY, 'bin/agent-loop', 'workflow', 'status', self::TASK, '--format=json',
        ], $worktree);
        $decoded = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new ExecutionContractDogfoodFailure('workflow status did not return a JSON object.');
        }

        return $decoded;
    }

    /**
     * @param non-empty-list<string> $command
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
            throw new ExecutionContractDogfoodFailure('Unable to start command: ' . implode(' ', $command));
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
        if ($exitCode !== 0 && $throwOnFailure) {
            throw new ExecutionContractDogfoodFailure(sprintf(
                'Command failed with exit %d: %s',
                $exitCode,
                implode(' ', $command),
            ));
        }

        return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function source(string $worktree): string
    {
        return $this->read($worktree . '/' . self::SOURCE);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new ExecutionContractDogfoodFailure('Unable to read dogfood artifact: ' . $path);
        }

        return $contents;
    }

    private function makeDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new ExecutionContractDogfoodFailure('Unable to create dogfood directory: ' . $path);
        }
    }
}

$repositoryRoot = realpath(dirname(__DIR__));
$manifest = getenv('AGENT_LOOP_OPERATING_PROMPT_MANIFEST');
if (!is_string($repositoryRoot) || !is_string($manifest) || trim($manifest) === '' || !is_file($manifest)) {
    fwrite(STDERR, "Execution-contract dogfood requires repository root and AGENT_LOOP_OPERATING_PROMPT_MANIFEST.\n");
    exit(2);
}

exit((new ExecutionContractDogfood($repositoryRoot, $manifest))->run());
