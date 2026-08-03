<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

use RuntimeException;

final readonly class CommandEditRunner implements EditRunner
{
    public function run(EditExecution $execution): EditRunResult
    {
        $command = $execution->request->runnerCommand;
        if ($command === null || trim($command) === '') {
            throw new RuntimeException('Command runner has no executable.');
        }

        $argv = [$command, ...$execution->request->runnerArguments];
        /** @var array<int, resource> $pipes */
        $pipes = [];
        $process = proc_open(
            $argv,
            [
                0 => ['file', $execution->promptPath, 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $execution->request->projectRoot,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start edit runner command.');
        }

        /** @var array<int, resource> $openPipes */
        $openPipes = [];
        foreach ([1, 2] as $index) {
            $pipe = $pipes[$index] ?? null;
            if (!is_resource($pipe)) {
                $this->closePipes($openPipes);
                proc_terminate($process);
                proc_close($process);
                throw new RuntimeException('Edit runner did not expose stdout and stderr pipes.');
            }
            stream_set_blocking($pipe, false);
            $openPipes[$index] = $pipe;
        }

        $buffers = [1 => '', 2 => ''];
        $deadline = microtime(true) + $execution->request->runnerTimeoutSeconds;
        $timedOut = false;
        $terminationStartedAt = null;
        $observedExitCode = null;

        while (true) {
            $status = proc_get_status($process);
            $running = $status['running'];
            if (!$running && $observedExitCode === null && is_int($status['exitcode']) && $status['exitcode'] >= 0) {
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
                    throw new RuntimeException('Unable to read edit runner output.');
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
            $buffers[2] .= ($buffers[2] === '' ? '' : "\n") . 'Runner timed out after ' . $execution->request->runnerTimeoutSeconds . ' seconds.';
        }

        return new EditRunResult(
            status: $exitCode === 0 ? 'runner_succeeded' : 'runner_failed',
            exitCode: $exitCode,
            stdout: $buffers[1],
            stderr: $buffers[2],
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
