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
        $process = proc_open(
            $command,
            [
                0 => ['file', $nullDevice, 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $workingDirectory,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to execute declared validation command: ' . $command);
        }

        /** @var array<int, resource> $openPipes */
        $openPipes = [];
        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                stream_set_blocking($pipes[$index], false);
                $openPipes[$index] = $pipes[$index];
            }
        }

        $buffers = [1 => '', 2 => ''];

        while ($openPipes !== []) {
            $read = array_values($openPipes);
            $write = null;
            $except = null;
            $selected = stream_select($read, $write, $except, 0, 200000);
            if ($selected === false) {
                break;
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
        }

        foreach ($openPipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $exitCode = proc_close($process);
        if ($exitCode < 0) {
            throw new RuntimeException('Declared validation command terminated without an observable exit code: ' . $command);
        }

        $output = trim($buffers[1] . "\n" . $buffers[2]);

        return [
            'exitCode' => $exitCode,
            'output' => $output,
        ];
    }
}
