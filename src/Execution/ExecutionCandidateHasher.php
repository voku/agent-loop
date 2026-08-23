<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use HashContext;
use RuntimeException;

final readonly class ExecutionCandidateHasher
{
    public function __construct(private string $repositoryRoot)
    {
    }

    public function candidateRevision(string $workspacePath, string $baseCommit): string
    {
        $this->assertWorkspace($workspacePath, $baseCommit);
        if ($this->git($workspacePath, ['status', '--porcelain=v1', '--untracked-files=all']) === '') {
            return $baseCommit;
        }

        return $this->hash($workspacePath, $baseCommit);
    }

    public function workspaceIdentity(string $workspacePath): string
    {
        $canonical = realpath($workspacePath);
        if (!is_string($canonical)) {
            throw new RuntimeException('Execution workspace cannot be resolved.');
        }
        $this->assertSameRepository($canonical);
        $gitDirectory = $this->gitDirectory($canonical);

        return 'workspace:sha256:' . hash('sha256', implode("\0", [
            str_replace('\\', '/', $canonical),
            $gitDirectory,
        ]));
    }

    private function hash(string $workspacePath, string $baseCommit): string
    {
        $trackedDiff = $this->git($workspacePath, ['diff', '--binary', '--no-ext-diff', '--full-index', 'HEAD', '--'], false);
        $untracked = $this->nulList($this->git($workspacePath, ['ls-files', '--others', '--exclude-standard', '-z'], false));
        sort($untracked, SORT_STRING);

        $hash = hash_init('sha256');
        hash_update($hash, "agent-loop-runner-candidate-v1\0");
        hash_update($hash, $baseCommit . "\0");
        hash_update($hash, strlen($trackedDiff) . "\0" . $trackedDiff);
        foreach ($untracked as $relativePath) {
            $absolute = $this->inside($workspacePath, $relativePath);
            hash_update($hash, "\0U\0" . strlen($relativePath) . "\0" . $relativePath);
            $this->updateFileEvidence($hash, $absolute);
        }

        return 'git-worktree-v1:' . $baseCommit . ':sha256:' . hash_final($hash);
    }

    private function assertWorkspace(string $workspacePath, string $baseCommit): void
    {
        if (preg_match('/^[0-9a-f]{40,64}$/', $baseCommit) !== 1) {
            throw new RuntimeException('Execution candidate verification requires an exact Git base commit.');
        }
        $canonical = realpath($workspacePath);
        if (!is_string($canonical) || !is_dir($canonical)) {
            throw new RuntimeException('Execution workspace cannot be resolved.');
        }
        $this->assertSameRepository($canonical);
        $head = $this->git($canonical, ['rev-parse', '--verify', 'HEAD']);
        if (!hash_equals($baseCommit, $head)) {
            throw new RuntimeException(sprintf(
                'Execution workspace HEAD does not match governed base %s; got %s.',
                $baseCommit,
                $head,
            ));
        }
    }

    private function assertSameRepository(string $workspacePath): void
    {
        $expected = $this->commonDirectory($this->repositoryRoot);
        $actual = $this->commonDirectory($workspacePath);
        if (!hash_equals($expected, $actual)) {
            throw new RuntimeException('Execution workspace belongs to a different Git repository.');
        }
    }

    private function commonDirectory(string $workingDirectory): string
    {
        $value = $this->git($workingDirectory, ['rev-parse', '--git-common-dir']);
        $path = str_starts_with($value, '/') ? $value : $workingDirectory . '/' . $value;
        $canonical = realpath($path);
        if (!is_string($canonical)) {
            throw new RuntimeException('Git common directory cannot be resolved for execution workspace.');
        }

        return str_replace('\\', '/', $canonical);
    }

    private function gitDirectory(string $workingDirectory): string
    {
        $value = $this->git($workingDirectory, ['rev-parse', '--git-dir']);
        $path = str_starts_with($value, '/') ? $value : $workingDirectory . '/' . $value;
        $canonical = realpath($path);
        if (!is_string($canonical)) {
            throw new RuntimeException('Git worktree directory cannot be resolved for execution workspace.');
        }

        return str_replace('\\', '/', $canonical);
    }

    /** @param list<string> $arguments */
    private function git(string $workingDirectory, array $arguments, bool $trim = true): string
    {
        $process = proc_open(
            ['git', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workingDirectory,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to execute Git for execution candidate verification.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || !is_string($stdout)) {
            throw new RuntimeException('Git candidate verification failed: ' . trim(is_string($stderr) ? $stderr : 'unknown error'));
        }

        return $trim ? trim($stdout) : $stdout;
    }

    /** @return list<non-empty-string> */
    private function nulList(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $parts = explode("\0", $raw);
        if (end($parts) === '') {
            array_pop($parts);
        }
        $result = [];
        foreach ($parts as $part) {
            if ($part === '') {
                throw new RuntimeException('Git returned an invalid empty untracked path.');
            }
            $result[] = $part;
        }

        return $result;
    }

    private function inside(string $workspacePath, string $relativePath): string
    {
        if ($relativePath === '' || str_starts_with($relativePath, '/') || str_contains($relativePath, "\0")) {
            throw new RuntimeException('Git returned an invalid untracked path.');
        }
        $root = realpath($workspacePath);
        if (!is_string($root)) {
            throw new RuntimeException('Execution workspace cannot be resolved.');
        }
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $candidate = $root . '/' . $relativePath;
        $parent = realpath(dirname($candidate));
        if (!is_string($parent)) {
            throw new RuntimeException('Untracked candidate parent cannot be resolved: ' . $relativePath);
        }
        $parent = rtrim(str_replace('\\', '/', $parent), '/');
        if ($parent !== $root && !str_starts_with($parent, $root . '/')) {
            throw new RuntimeException('Untracked candidate path escapes the execution workspace: ' . $relativePath);
        }

        return $candidate;
    }

    private function updateFileEvidence(HashContext $hash, string $path): void
    {
        if (is_link($path)) {
            $target = readlink($path);
            if (!is_string($target)) {
                throw new RuntimeException('Unable to read candidate symlink: ' . $path);
            }
            $evidence = 'symlink:' . $target;
            hash_update($hash, "\0" . strlen($evidence) . "\0" . $evidence);
            return;
        }
        if (!is_file($path)) {
            throw new RuntimeException('Untracked candidate is not a regular file or symlink: ' . $path);
        }
        $size = filesize($path);
        $handle = fopen($path, 'rb');
        if (!is_int($size) || $handle === false) {
            throw new RuntimeException('Unable to read untracked candidate file: ' . $path);
        }
        hash_update($hash, "\0" . ($size + strlen('file:')) . "\0file:");
        $read = 0;
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('Unable to read untracked candidate file: ' . $path);
                }
                $read += strlen($chunk);
                hash_update($hash, $chunk);
            }
        } finally {
            fclose($handle);
        }
        if ($read !== $size) {
            throw new RuntimeException('Untracked candidate changed while hashing: ' . $path);
        }
    }
}
