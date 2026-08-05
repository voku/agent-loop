<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit\Verify;

/** Runs a gate command and reports its exit code; it never interprets what the command meant. */
final readonly class CommandRunner
{
    /**
     * @param non-empty-list<string> $command
     *
     * @return array{exit_code: int|null, stdout: string, stderr: string}
     */
    public function run(array $command, string $workingDirectory): array
    {
        return $this->open($command, $workingDirectory);
    }

    /**
     * A declared validation command arrives as one string written by a human in the task brief, so
     * it is handed to a shell as-is rather than guessed apart into arguments. Only reached when the
     * caller explicitly opted in with --run-commands.
     *
     * @return array{exit_code: int|null, stdout: string, stderr: string}
     */
    public function runShell(string $command, string $workingDirectory): array
    {
        return $this->open(['/bin/sh', '-c', $command], $workingDirectory);
    }

    /**
     * @param non-empty-list<string> $command
     *
     * @return array{exit_code: int|null, stdout: string, stderr: string}
     */
    private function open(array $command, string $workingDirectory): array
    {
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workingDirectory,
        );
        if (!is_resource($process)) {
            return ['exit_code' => null, 'stdout' => '', 'stderr' => 'Unable to start command.'];
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit_code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
