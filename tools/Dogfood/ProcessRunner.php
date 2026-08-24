<?php

declare(strict_types=1);

namespace voku\AgentLoop\Dogfood;

use RuntimeException;

/**
 * Runs a child process for the dogfood runners.
 *
 * These runners used to be Bash. Bash is not the portability problem people
 * expect - Git for Windows ships it - but a shell script cannot be unit
 * tested, and both defects found in the self-shape runner this month were
 * exactly the kind a test would have caught: a `git diff` filter that watched
 * one directory, and a review status that was never read.
 *
 * Everything here is argv-shaped on purpose. The project's own PHPStan rule
 * rejects shell-shaped text passed to `proc_open`, because a string command
 * re-introduces quoting rules that differ per platform - which is the real
 * portability trap, not the interpreter.
 */
final readonly class ProcessRunner
{
    public function __construct(private string $workingDirectory)
    {
    }

    /**
     * @param non-empty-list<string> $command
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public function run(array $command): array
    {
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->workingDirectory,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start: ' . implode(' ', $command));
        }

        $stdout = stream_get_contents($pipes[1]);
        // Drained as well: an undrained stderr pipe blocks the child once its
        // buffer fills, which is how a passing command turns into a hang.
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit_code' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    /**
     * @param non-empty-list<string> $command
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public function mustRun(array $command): array
    {
        $result = $this->run($command);
        if ($result['exit_code'] !== 0) {
            throw new RuntimeException(sprintf(
                "Command failed with exit %d: %s\n%s",
                $result['exit_code'],
                implode(' ', $command),
                trim($result['stdout'] . "\n" . $result['stderr']),
            ));
        }

        return $result;
    }

    /**
     * The path to a package binary, spelled for the current platform.
     *
     * Composer installs `vendor/bin/<name>` on every platform, but on Windows
     * the executable entry is `<name>.bat` and the extensionless file is a
     * shell wrapper the OS will not run. Resolving it here is the one place
     * that difference has to be known.
     */
    public function vendorBinary(string $name): string
    {
        $base = $this->workingDirectory . '/vendor/bin/' . $name;
        foreach ([$base . '.bat', $base] as $candidate) {
            if (is_file($candidate) && (DIRECTORY_SEPARATOR === '\\' ? str_ends_with($candidate, '.bat') : true)) {
                return $candidate;
            }
        }

        return $base;
    }
}
