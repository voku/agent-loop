<?php

declare(strict_types=1);

final class ExternalL1HandoffFailure extends RuntimeException
{
}

final readonly class ExternalL1HandoffExperiment
{
    private const string TASK = 'ISSUE-533-TYPED-INVOCATION';
    private const string SOURCE = 'src/Run/RunPolicyEvaluation.php';
    private const string POLICY = 'AGENTS.md';

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
                'cleanup' => $this->cleanup(),
                default => throw new ExternalL1HandoffFailure('Unknown phase: ' . $phase),
            };

            fwrite(STDOUT, sprintf("External L1 handoff %s: OK\n", $phase));

            return 0;
        } catch (Throwable $throwable) {
            fwrite(STDERR, sprintf(
                "External L1 handoff %s: FAILED - %s\n",
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
            throw new ExternalL1HandoffFailure('Unable to expose the candidate Composer vendor tree in the experiment worktree.');
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
            '--paths=src',
        ], $this->worktree);

        $this->runCommand([
            PHP_BINARY,
            'bin/agent-loop',
            'workflow',
            'plan',
            self::TASK,
            '--by',
            'handoff-experiment-planner',
            '--file',
            self::SOURCE,
            '--file',
            'src/Run/RunPolicyEvaluator.php',
            '--file',
            'src/Run/RunManifest.php',
            '--file',
            'src/Run/RunManifestProjector.php',
            '--file',
            'src/Workflow/WorkflowStatusCommand.php',
            '--file',
            'src/Workflow/HostFrontDoorCommand.php',
            '--file',
            'tests/RunPolicyEvaluatorTest.php',
            '--file',
            'tests/RunPolicyRecoveryConvergenceTest.php',
            '--goal',
            'Close issue #533 with the smallest owner-produced typed invocation projection beside next_action so runtime-bound consumers can execute Loop-owned continuations without parsing rendered command strings.',
            '--non-goal',
            'Do not add Docker semantics, runtime transport, lifecycle-to-Make target mappings, a shell-prefix DSL, or parsing of rendered next_action strings.',
            '--acceptance',
            'Existing next_action and next_action_kind remain backward-compatible human/presentation contracts.',
            '--acceptance',
            'Loop-owned continuations can expose executable identity, argv tokens, and template status from owner-known structure.',
            '--acceptance',
            'Opaque project-owned validation commands remain deliberately untyped instead of being shell-split.',
            '--acceptance',
            'The nullable typed invocation survives manifest and host/status JSON projection without changing lifecycle authority.',
            '--validation',
            'vendor/bin/phpunit tests/RunPolicyEvaluatorTest.php tests/RunPolicyRecoveryConvergenceTest.php',
            '--validation',
            'composer ci',
            '--operating-prompt-manifest',
            $this->operatingPromptManifest,
            '--operating-prompt',
            '{"id":"missingness-audit","arguments":{}}',
        ], $this->worktree);

        $this->runCommand([
            PHP_BINARY,
            'bin/agent-loop',
            'workflow',
            'approve',
            self::TASK,
            '--by',
            'handoff-experiment-approver',
        ], $this->worktree);

        $enter = $this->runCommand([
            PHP_BINARY,
            'bin/agent-loop',
            'enter',
            self::TASK,
            '--format=json',
        ], $this->worktree, false);
        if ($enter['exit_code'] !== 1) {
            throw new ExternalL1HandoffFailure(
                'The prepared L2 task must remain blocked until an L1 execution contract exists; got enter exit '
                . $enter['exit_code'] . '.',
            );
        }

        $enterPayload = $this->decodeJson($enter['stdout'], 'enter output');
        if (($enterPayload['next_action_kind'] ?? null) !== 'command_template') {
            throw new ExternalL1HandoffFailure('Prepared task did not request the L1 contract as a command_template.');
        }

        $nextAction = $enterPayload['next_action'] ?? null;
        if (!is_string($nextAction) || !str_contains($nextAction, 'workflow contract ' . self::TASK)) {
            throw new ExternalL1HandoffFailure('Prepared task did not expose the expected execution-contract continuation.');
        }

        $recallRoot = $this->worktree . '/.agent-loop/recall/' . self::TASK;
        $system = $this->read($recallRoot . '/system.md');
        $validationPlan = $this->read($recallRoot . '/validation-plan.md');
        $policy = $this->read($this->worktree . '/' . self::POLICY);
        $source = $this->read($this->worktree . '/' . self::SOURCE);

        foreach ([
            'L2 marker' => '## L2 Operational Prompt Construction',
            'recipe id' => 'missingness-audit',
            'approved source' => self::SOURCE,
            'task id' => self::TASK,
        ] as $label => $needle) {
            if (!str_contains($system . "\n" . $validationPlan, $needle)) {
                throw new ExternalL1HandoffFailure(sprintf(
                    'Compiled L2 evidence is missing %s (%s).',
                    $label,
                    $needle,
                ));
            }
        }

        $status = $this->status();
        if (($status['manifest']['references']['execution_contract']['state'] ?? null) !== 'missing') {
            throw new ExternalL1HandoffFailure('Execution contract must be missing at the external-reasoning handoff.');
        }
        if ($this->changedPaths() !== []) {
            throw new ExternalL1HandoffFailure('Experiment worktree changed before the external-reasoning handoff.');
        }

        $baseCommit = trim($this->runCommand(['git', 'rev-parse', 'HEAD'], $this->worktree)['stdout']);
        if (preg_match('/^[a-f0-9]{40}$/', $baseCommit) !== 1) {
            throw new ExternalL1HandoffFailure('Unable to establish immutable base commit.');
        }

        $this->writeEvidence('l2-system.md', $system);
        $this->writeEvidence('validation-plan.md', $validationPlan);
        $this->writeEvidence('project-policy.md', $policy);
        $this->writeEvidence('source-before.php', $source);
        $this->writeEvidence('enter.json', $this->prettyJson($enterPayload));
        $this->writeEvidence('status.json', $this->prettyJson($status));

        $handoff = $this->handoffPrompt(
            baseCommit: $baseCommit,
            nextAction: $nextAction,
            system: $system,
            validationPlan: $validationPlan,
            policy: $policy,
            source: $source,
        );
        $this->writeEvidence('handoff.md', $handoff);

        $repository = getenv('GITHUB_REPOSITORY') ?: 'voku/agent-loop';
        $runId = getenv('GITHUB_RUN_ID') ?: null;
        $runAttempt = getenv('GITHUB_RUN_ATTEMPT') ?: null;
        $runUrl = is_string($runId) && ctype_digit($runId)
            ? 'https://github.com/' . $repository . '/actions/runs/' . $runId
            : null;
        $artifactName = is_string($runId) && ctype_digit($runId) && is_string($runAttempt) && ctype_digit($runAttempt)
            ? 'external-l1-handoff-' . $runId . '-' . $runAttempt
            : null;

        $metadata = [
            'schema_version' => '1.0',
            'kind' => 'external_l1_construction_handoff',
            'repository' => $repository,
            'task_id' => self::TASK,
            'base_commit' => $baseCommit,
            'github' => [
                'run_id' => $runId,
                'run_attempt' => $runAttempt,
                'run_url' => $runUrl,
                'artifact_name' => $artifactName,
                'workflow' => getenv('GITHUB_WORKFLOW') ?: null,
                'ref' => getenv('GITHUB_REF') ?: null,
                'sha' => $baseCommit,
                'event_sha' => getenv('GITHUB_SHA') ?: null,
            ],
            'state' => [
                'next_action' => $nextAction,
                'next_action_kind' => $enterPayload['next_action_kind'] ?? null,
                'execution_contract_state' => $status['manifest']['references']['execution_contract']['state'] ?? null,
                'mutation_allowed' => $status['policy']['mutation_allowed'] ?? null,
            ],
            'evidence' => [
                'handoff' => $this->identity($this->evidenceDirectory . '/handoff.md'),
                'l2_system' => $this->identity($this->evidenceDirectory . '/l2-system.md'),
                'validation_plan' => $this->identity($this->evidenceDirectory . '/validation-plan.md'),
                'project_policy' => $this->identity($this->evidenceDirectory . '/project-policy.md'),
                'source_before' => $this->identity($this->evidenceDirectory . '/source-before.php'),
                'enter' => $this->identity($this->evidenceDirectory . '/enter.json'),
                'status' => $this->identity($this->evidenceDirectory . '/status.json'),
            ],
            'output_contract' => [
                'format' => 'markdown',
                'required_sections' => ['Goal', 'Context', 'Constraints', 'Verification', 'Done When'],
                'artifact_name' => 'l1.md',
                'reasoning_host_must_not_implement' => true,
            ],
            'resume' => [
                'requires_prepared_state' => true,
                'prepared_state_archive' => 'prepared-state.tar.gz',
                'bind_command_template' => 'php bin/agent-loop workflow contract ' . self::TASK
                    . ' --status ready --from <l1.md> --by external-l1-constructor',
                'note' => 'Restore the archived prepared state on this exact base commit before binding the returned L1.',
            ],
        ];
        $this->writeEvidence('handoff.json', $this->prettyJson($metadata));
    }

    private function handoffPrompt(
        string $baseCommit,
        string $nextAction,
        string $system,
        string $validationPlan,
        string $policy,
        string $source,
    ): string {
        $repository = getenv('GITHUB_REPOSITORY') ?: 'voku/agent-loop';
        $runId = getenv('GITHUB_RUN_ID') ?: 'unknown';
        $task = self::TASK;

        return <<<MD
# External L1 construction handoff

You are the external reasoning host at the intentional L2 -> L1 boundary of a governed agent-loop task.

Repository: {$repository}
Base commit: {$baseCommit}
GitHub Actions run: {$runId}
Task: {$task}

The deterministic workflow has already planned, approved, entered, and compiled Recall. Mutation is intentionally blocked because the L2 task still requires a concrete L1 execution contract.

Current owner continuation:

{$nextAction}

## Your job

Construct the concrete project-specific L1 execution contract from the bounded evidence below.

Return **only** the L1 Markdown. Do not implement the code change, modify repository state, approve anything, claim verification, or invent additional context.

The returned Markdown must contain exactly these top-level sections, in this order:

## Goal
## Context
## Constraints
## Verification
## Done When

Preserve the approved scope, acceptance criteria, and non-goals. Ground instructions only in supplied evidence. Keep the exact validation commands. Require a changed-file scope check. Respect the supplied repository ownership and lifecycle guidance; do not invent runtime transport, lifecycle choreography, or string-parsing fallbacks that are not evidenced by the handoff.

If the supplied evidence is insufficient to construct a safe L1, do not guess. Return a blocked L1 using the same five sections and state the missing evidence concretely in Context and Done When.

# Compiled Recall L2: system.md

{$system}

# Recall validation plan

{$validationPlan}

# Project guidance

{$policy}

# Current primary approved source

```php
{$source}
```
MD;
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

    /** @return array{path:string, sha256:string} */
    private function identity(string $path): array
    {
        $sha = hash_file('sha256', $path);
        if ($sha === false) {
            throw new ExternalL1HandoffFailure('Unable to hash experiment artifact: ' . $path);
        }

        return [
            'path' => basename($path),
            'sha256' => 'sha256:' . $sha,
        ];
    }

    /**
     * @param list<string> $command
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
            throw new ExternalL1HandoffFailure('Unable to start command: ' . implode(' ', $command));
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
            throw new ExternalL1HandoffFailure(sprintf(
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
            throw new ExternalL1HandoffFailure($label . ' is not valid JSON: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new ExternalL1HandoffFailure($label . ' must decode to an object.');
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
            throw new ExternalL1HandoffFailure('Unable to read non-empty experiment artifact: ' . $path);
        }

        return $content;
    }

    private function writeEvidence(string $name, string $content): void
    {
        $this->makeDirectory($this->evidenceDirectory);
        if (file_put_contents($this->evidenceDirectory . '/' . $name, $content) === false) {
            throw new ExternalL1HandoffFailure('Unable to write experiment evidence: ' . $name);
        }
    }

    private function makeDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0o775, true) && !is_dir($path)) {
            throw new ExternalL1HandoffFailure('Unable to create directory: ' . $path);
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

    throw new ExternalL1HandoffFailure('Missing required option --' . $name . '=...');
}

$repositoryRoot = realpath(dirname(__DIR__));
if (!is_string($repositoryRoot)) {
    fwrite(STDERR, "Unable to resolve repository root.\n");
    exit(2);
}

try {
    $phase = $argv[1] ?? '';
    if (!is_string($phase) || $phase === '') {
        throw new ExternalL1HandoffFailure(
            'Usage: php tools/llm-l1-experiment.php <prepare|cleanup> --worktree=... --evidence-dir=... --manifest=...',
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

    exit((new ExternalL1HandoffExperiment(
        repositoryRoot: $repositoryRoot,
        worktree: $worktree,
        evidenceDirectory: $evidenceDirectory,
        operatingPromptManifest: $manifest,
    ))->run($phase));
} catch (Throwable $throwable) {
    fwrite(STDERR, 'External L1 handoff bootstrap: FAILED - ' . $throwable->getMessage() . "\n");
    exit(2);
}
