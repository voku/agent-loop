<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

/**
 * Takes a before/after picture of the working tree so `changed_files` is observed, not claimed.
 *
 * Asking the runner what it changed would make the answer sheet self-reported at exactly the point
 * where a verifier needs an independent fact. Git already knows, so this asks Git.
 *
 * A repository without Git, or a Git that fails for any reason, yields an unavailable snapshot: an
 * edit must not fail because its bookkeeping could not run.
 */
final readonly class WorkingTreeSnapshotter
{
    public function capture(string $repositoryRoot): WorkingTreeSnapshot
    {
        $root = realpath($repositoryRoot);
        if ($root === false || !is_dir($root . '/.git')) {
            return WorkingTreeSnapshot::unavailable();
        }

        $head = $this->run($root, ['git', 'rev-parse', 'HEAD']);
        $status = $this->run($root, ['git', 'status', '--porcelain=v1', '-z', '--untracked-files=all']);
        if ($status === null) {
            return WorkingTreeSnapshot::unavailable();
        }

        $entries = [];
        foreach ($this->parseStatus($status) as $path => $code) {
            $absolute = $root . '/' . $path;
            $hash = is_file($absolute) ? hash_file('sha256', $absolute) : false;
            $entries[$path] = $code . ':' . (is_string($hash) ? $hash : 'absent');
        }
        ksort($entries, SORT_STRING);

        return new WorkingTreeSnapshot(true, $head === null ? null : trim($head), $entries);
    }

    /**
     * `-z` output is NUL-separated with no trailing newline, and a rename entry carries its origin
     * path as a second record - which must be consumed, not read as a status line of its own.
     *
     * @return array<string, string>
     */
    private function parseStatus(string $status): array
    {
        $records = explode("\0", $status);
        $entries = [];
        for ($i = 0, $count = count($records); $i < $count; ++$i) {
            $record = $records[$i];
            if (strlen($record) < 4) {
                continue;
            }

            $code = substr($record, 0, 2);
            $path = substr($record, 3);
            if (str_contains($code, 'R') || str_contains($code, 'C')) {
                $origin = $records[$i + 1] ?? null;
                ++$i;
                if (is_string($origin) && $origin !== '') {
                    $entries[$origin] = $code . '<-';
                }
            }

            $entries[$path] = $code;
        }

        return $entries;
    }

    /** @param non-empty-list<string> $command */
    private function run(string $workingDirectory, array $command): ?string
    {
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workingDirectory,
        );
        if (!is_resource($process)) {
            return null;
        }

        $stdout = stream_get_contents($pipes[1]);
        // Drained as well: an undrained stderr pipe can block the child once its buffer fills.
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 && is_string($stdout) ? $stdout : null;
    }
}
