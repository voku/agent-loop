<?php

declare(strict_types=1);

namespace voku\AgentLoop\Process;

use InvalidArgumentException;
use RuntimeException;

final readonly class CommandProcessRunner
{
    /**
     * @param non-empty-list<string> $command
     */
    public function run(
        array $command,
        string $workingDirectory,
        int $timeoutSeconds,
        ?string $stdinPath = null,
    ): CommandProcessResult {
        if ($timeoutSeconds < 1) {
            throw new InvalidArgumentException('Process timeout must be at least one second.');
        }

        /** @var array<int, resource> $pipes */
        $pipes = [];
        $descriptors = [
            0 => $stdinPath === null ? ['pipe', 'r'] : ['file', $stdinPath, 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $workingDirectory);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start command process.');
        }

        if ($stdinPath === null && isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        /** @var array<int, resource> $openPipes */
        $openPipes = [];
        foreach ([1, 2] as $index) {
            $pipe = $pipes[$index] ?? null;
            if (!is_resource($pipe)) {
                $this->closePipes($openPipes);
                proc_terminate($process);
                proc_close($process);
                throw new RuntimeException('Command process did not expose stdout and stderr pipes.');
            }
            stream_set_blocking($pipe, false);
            $openPipes[$index] = $pipe;
        }

        $buffers = [1 => '', 2 => ''];
        $deadline = microtime(true) + $timeoutSeconds;
        $timedOut = false;
        $terminationStartedAt = null;
        $observedExitCode = null;

        while (true) {
            $status = proc_get_status($process);
            $running = $status['running'];
            if (!$running && $observedExitCode === null && $status['exitcode'] >= 0) {
                $observedExitCode = $status['exitcode'];
            }

            $now = microtime(true);
            if (!$timedOut && $running && $now >= $deadline) {
                $timedOut = true;
                $terminationStartedAt = $now;
                proc_terminate($process);
            } elseif (
                $timedOut
                && $running
                && $terminationStartedAt !== null
                && $now >= $terminationStartedAt + 2.0
            ) {
                proc_terminate($process, 9);
                $this->closePipes($openPipes);
            }

            if ($openPipes !== []) {
                $read = array_values($openPipes);
                $write = null;
                $except = null;
                $selected = stream_select($read, $write, $except, 0, 200000);
                if ($selected === false) {
                    $this->closePipes($openPipes);
                    if ($running) {
                        proc_terminate($process);
                    }
                    proc_close($process);
                    throw new RuntimeException('Unable to read command process output.');
                }

                foreach ($read as $stream) {
                    $index = array_search($stream, $openPipes, true);
                    if (!is_int($index)) {
                        continue;
                    }
                    $chunk = fread($stream, 8192);
                    if (is_string($chunk) && $chunk !== '') {
                        $buffers[$index] .= $chunk;
                    }
                    if (($chunk === false || $chunk === '') && feof($stream)) {
                        fclose($stream);
                        unset($openPipes[$index]);
                    }
                }
            } elseif ($running) {
                usleep(200000);
            }

            if (!$running && $openPipes === []) {
                break;
            }
        }

        $closeExitCode = proc_close($process);
        $exitCode = $closeExitCode >= 0 ? $closeExitCode : ($observedExitCode ?? $closeExitCode);
        if ($timedOut) {
            $exitCode = 124;
        }

        return new CommandProcessResult(
            exitCode: $exitCode,
            stdout: $buffers[1],
            stderr: $buffers[2],
            timedOut: $timedOut,
        );
    }

    /** @param array<int, resource> $pipes */
    private function closePipes(array &$pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $pipes = [];
    }
}
