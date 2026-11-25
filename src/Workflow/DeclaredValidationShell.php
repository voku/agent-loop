<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use RuntimeException;

/**
 * Executes one already-approved Contract validation command through the shell and observes its exit code.
 *
 * Shared by finish-owned validation (project checkout) and the deterministic verify stage (governed candidate
 * workspace) so both observe a declared command the same way.
 */
final readonly class DeclaredValidationShell
{
    /** @return array{exitCode: int, output: string} */
    public function executeDeclaredValidationShell(string $command, string $workingDirectory): array
    {
        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        /** @var array<int, resource> $pipes */
        $pipes = [];
        // One pipe for both streams keeps the exact order of the writes; a caller that keeps only the end of the output
        // gets what the command wrote last, whichever stream that was. Two pipes cannot be ordered after the fact.
        $process = proc_open(
            $command,
            // @phpstan-ignore argument.type (the proc_open stub omits the documented ['redirect', int] descriptor)
            [
                0 => ['file', $nullDevice, 'r'],
                1 => ['pipe', 'w'],
                2 => ['redirect', 1],
            ],
            $pipes,
            $workingDirectory,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to execute declared validation command: ' . $command);
        }

        $output = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);

        $exitCode = proc_close($process);
        if ($exitCode < 0) {
            throw new RuntimeException('Declared validation command terminated without an observable exit code: ' . $command);
        }

        return [
            'exitCode' => $exitCode,
            'output' => $output,
        ];
    }
}
