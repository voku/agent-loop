<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

use RuntimeException;

final class MechanicalEditRunner implements EditRunner
{
    public function run(EditExecution $execution): EditRunResult
    {
        $old = $execution->request->replacementOld;
        $new = $execution->request->replacementNew;
        if ($old === null || $new === null) {
            throw new RuntimeException('Mechanical edit runner has no replacement literals.');
        }
        $target = $execution->target;
        if ($target === null) {
            throw new RuntimeException('Mechanical edit runner requires a resolved target method.');
        }

        $path = $this->targetPath($execution, $target);
        $content = file_get_contents($path);
        if (!is_string($content)) {
            throw new RuntimeException('Unable to read mechanical edit target: ' . $path);
        }
        $actualHash = hash('sha256', $content);
        if (!hash_equals($target->file->sha256, 'sha256:' . $actualHash)) {
            throw new RuntimeException('Mechanical edit target changed after agent-map validation: ' . $target->file->path);
        }

        $lines = preg_split('/(?<=\n)/', $content);
        if (!is_array($lines)) {
            throw new RuntimeException('Unable to split mechanical edit target into lines.');
        }
        $start = $target->method->lineStart - 1;
        $length = $target->method->lineEnd - $target->method->lineStart + 1;
        $method = implode('', array_slice($lines, $start, $length));
        if (substr_count($method, $old) !== 1) {
            throw new RuntimeException('Mechanical replacement must match exactly once inside ' . $execution->request->target . '.');
        }

        $updated = implode('', array_slice($lines, 0, $start))
            . str_replace($old, $new, $method)
            . implode('', array_slice($lines, $start + $length));
        $this->write($path, $updated);

        $lint = $this->lint($path);
        if ($lint['exit_code'] !== 0) {
            $this->write($path, $content);

            return new EditRunResult(
                status: 'runner_failed',
                exitCode: $lint['exit_code'],
                stdout: $lint['stdout'],
                stderr: $lint['stderr'] . "\nMechanical replacement was reverted because PHP lint failed.",
            );
        }

        return new EditRunResult(
            status: 'runner_succeeded',
            exitCode: 0,
            stdout: 'Mechanical replacement applied exactly once inside ' . $execution->request->target . ".\n" . $lint['stdout'],
            stderr: $lint['stderr'],
        );
    }

    private function targetPath(EditExecution $execution, \voku\AgentMap\Index\ResolvedMethod $target): string
    {
        $root = realpath($execution->request->mapRoot);
        $path = realpath($execution->request->mapRoot . '/' . $target->file->path);
        if (!is_string($root) || !is_string($path) || !str_starts_with(str_replace('\\', '/', $path), rtrim(str_replace('\\', '/', $root), '/') . '/')) {
            throw new RuntimeException('Mechanical edit target escapes map root: ' . $target->file->path);
        }

        return $path;
    }

    /** @return array{exit_code: int, stdout: string, stderr: string} */
    private function lint(string $path): array
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start PHP lint for mechanical edit.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'exit_code' => $exitCode,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function write(string $path, string $content): void
    {
        $temporary = $path . '.tmp-' . getmypid();
        if (file_put_contents($temporary, $content) === false) {
            throw new RuntimeException('Unable to write mechanical edit target: ' . $temporary);
        }
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to publish mechanical edit target: ' . $path);
        }
    }
}
