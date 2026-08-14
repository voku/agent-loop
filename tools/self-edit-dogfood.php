<?php

declare(strict_types=1);

final class SelfEditDogfoodFailure extends RuntimeException
{
}

final readonly class SelfEditDogfood
{
    private const string TARGET = 'voku\\AgentLoop\\Tests\\Fixtures\\SelfShape\\SelfEditProbe::value';
    private const string SOURCE = 'tests/fixtures/self-shape/SelfEditProbe.php';
    private const string TASK = 'SELF-EDIT';

    public function __construct(private string $repositoryRoot)
    {
    }

    public function run(): int
    {
        $worktree = sys_get_temp_dir() . '/agent-loop-self-edit-' . bin2hex(random_bytes(6));

        try {
            $this->runCommand(
                ['git', 'worktree', 'add', '--quiet', '--detach', $worktree, 'HEAD'],
                $this->repositoryRoot,
            );

            if (!symlink($this->repositoryRoot . '/vendor', $worktree . '/vendor')) {
                throw new SelfEditDogfoodFailure('Unable to expose the current Composer vendor tree inside the self-edit worktree.');
            }

            $this->runCommand([
                PHP_BINARY,
                'bin/agent-loop',
                'edit',
                self::TARGET,
                '--task=' . self::TASK,
                '--recall-root=.agent-loop/learning',
                '--map-paths=tests/fixtures/self-shape',
                '--phpstan-memory-limit=512M',
                '--runner=mechanical',
                '--replace-old=return 100 + $input;',
                '--replace-new=return 101 + $input;',
                '--',
                'Dogfood the deterministic edit path against agent-loop itself inside an isolated linked worktree.',
            ], $worktree);

            $source = file_get_contents($worktree . '/' . self::SOURCE);
            if (
                !is_string($source)
                || str_contains($source, 'return 100 + $input;')
                || substr_count($source, 'return 101 + $input;') !== 1
            ) {
                throw new SelfEditDogfoodFailure('Mechanical self-edit did not update the probe source.');
            }

            $bundle = $worktree . '/.agent-loop/edit/' . self::TASK;
            $result = $this->jsonFile($bundle . '/agent-result.json');
            $execution = $this->jsonFile($bundle . '/execution.json');

            if (($result['changed_files_source'] ?? null) !== 'git_status_diff') {
                throw new SelfEditDogfoodFailure('Self-edit change evidence was not observed through Git.');
            }
            if (($result['changed_files'] ?? null) !== [self::SOURCE]) {
                throw new SelfEditDogfoodFailure('Self-edit must observe exactly one changed source file.');
            }
            if (($result['runner']['status'] ?? null) !== 'runner_succeeded') {
                throw new SelfEditDogfoodFailure('Self-edit result does not report a successful runner.');
            }
            if (($execution['runner']['name'] ?? null) !== 'mechanical') {
                throw new SelfEditDogfoodFailure('Self-edit did not use the mechanical runner.');
            }
            if (($execution['routing']['external_model_invoked'] ?? null) !== false) {
                throw new SelfEditDogfoodFailure('Self-edit unexpectedly invoked an external model.');
            }
            if (($execution['runner']['model_input_tokens'] ?? null) !== 0) {
                throw new SelfEditDogfoodFailure('Self-edit unexpectedly reported model input tokens.');
            }
            if (($execution['runner']['model_tool_calls'] ?? null) !== 0) {
                throw new SelfEditDogfoodFailure('Self-edit unexpectedly reported model tool calls.');
            }

            $build = $this->repositoryRoot . '/build';
            if (!is_dir($build) && !mkdir($build, 0o775, true) && !is_dir($build)) {
                throw new SelfEditDogfoodFailure('Unable to create build evidence directory.');
            }
            $this->copyEvidence($bundle . '/agent-result.json', $build . '/self-shape-self-edit-result.json');
            $this->copyEvidence($bundle . '/execution.json', $build . '/self-shape-self-edit-execution.json');

            fwrite(STDOUT, "Self-edit dogfood: PASSED\n");

            return 0;
        } catch (Throwable $throwable) {
            fwrite(STDERR, 'Self-edit dogfood: FAILED - ' . $throwable->getMessage() . "\n");

            return 1;
        } finally {
            if (is_file($worktree . '/.git') || is_dir($worktree . '/.git')) {
                $this->runCommand(
                    ['git', 'worktree', 'remove', '--force', $worktree],
                    $this->repositoryRoot,
                    throwOnFailure: false,
                );
            }
        }
    }

    /** @param non-empty-list<string> $command */
    private function runCommand(
        array $command,
        string $workingDirectory,
        bool $throwOnFailure = true,
    ): int {
        $stdoutPath = tempnam(sys_get_temp_dir(), 'agent-loop-self-edit-stdout-');
        $stderrPath = tempnam(sys_get_temp_dir(), 'agent-loop-self-edit-stderr-');
        if (!is_string($stdoutPath) || !is_string($stderrPath)) {
            if (is_string($stdoutPath)) {
                @unlink($stdoutPath);
            }
            if (is_string($stderrPath)) {
                @unlink($stderrPath);
            }
            if ($throwOnFailure) {
                throw new SelfEditDogfoodFailure('Unable to create temporary command output files.');
            }

            return 1;
        }

        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['file', $stdoutPath, 'w'],
                2 => ['file', $stderrPath, 'w'],
            ],
            $pipes,
            $workingDirectory,
        );
        if (!is_resource($process)) {
            @unlink($stdoutPath);
            @unlink($stderrPath);
            if ($throwOnFailure) {
                throw new SelfEditDogfoodFailure('Unable to start command: ' . implode(' ', $command));
            }

            return 1;
        }

        fclose($pipes[0]);
        $exitCode = proc_close($process);
        $stdout = file_get_contents($stdoutPath);
        $stderr = file_get_contents($stderrPath);
        @unlink($stdoutPath);
        @unlink($stderrPath);

        if ($stdout !== false && $stdout !== '') {
            fwrite(STDOUT, $stdout);
        }
        if ($stderr !== false && $stderr !== '') {
            fwrite(STDERR, $stderr);
        }

        if ($exitCode !== 0 && $throwOnFailure) {
            throw new SelfEditDogfoodFailure(sprintf(
                'Command failed with exit %d: %s',
                $exitCode,
                implode(' ', $command),
            ));
        }

        return $exitCode;
    }

    /** @return array<string, mixed> */
    private function jsonFile(string $path): array
    {
        $json = file_get_contents($path);
        if (!is_string($json)) {
            throw new SelfEditDogfoodFailure('Missing JSON evidence: ' . $path);
        }

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new SelfEditDogfoodFailure('JSON evidence is not an object: ' . $path);
        }

        return $decoded;
    }

    private function copyEvidence(string $source, string $target): void
    {
        if (!copy($source, $target)) {
            throw new SelfEditDogfoodFailure('Unable to copy self-edit evidence: ' . $source);
        }
    }
}

$repositoryRoot = realpath(dirname(__DIR__));
if (!is_string($repositoryRoot)) {
    fwrite(STDERR, "Unable to resolve repository root.\n");
    exit(2);
}

exit((new SelfEditDogfood($repositoryRoot))->run());
