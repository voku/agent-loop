<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

final readonly class HumanReviewDiffCollector
{
    public function __construct(private string $rootPath)
    {
    }

    public function collect(TaskContract $contract): HumanReviewDiff
    {
        if ($contract->baseCommit === null) {
            return HumanReviewDiff::unavailable(
                null,
                'Contract has no base commit; Git change orientation cannot be derived without guessing a baseline.',
            );
        }

        $root = realpath($this->rootPath);
        if ($root === false || !is_dir($root)) {
            return HumanReviewDiff::unavailable($contract->baseCommit, 'Project root is unavailable.');
        }

        $base = $contract->baseCommit;
        $verified = $this->run($root, ['git', 'rev-parse', '--verify', $base . '^{commit}']);
        if ($verified['exit'] !== 0) {
            return HumanReviewDiff::unavailable($base, 'Contract base commit is not available in this Git checkout.');
        }

        $scope = $contract->scope;
        $allTrackedNames = $this->run($root, [
            'git', 'diff', '--name-only', '-z', '--no-ext-diff', '--find-renames', $base, '--',
        ]);
        $allUntrackedNames = $this->run($root, [
            'git', 'ls-files', '--others', '--exclude-standard', '-z', '--',
        ]);
        $trackedPatch = $this->run($root, [
            'git', 'diff', '--no-ext-diff', '--no-color', '--find-renames', $base, '--', ...$scope,
        ]);
        $scopedUntrackedNames = $this->run($root, [
            'git', 'ls-files', '--others', '--exclude-standard', '-z', '--', ...$scope,
        ]);

        if (
            $allTrackedNames['exit'] !== 0
            || $allUntrackedNames['exit'] !== 0
            || $trackedPatch['exit'] !== 0
            || $scopedUntrackedNames['exit'] !== 0
        ) {
            return HumanReviewDiff::unavailable($base, 'Git could not derive the review change orientation.');
        }

        $tracked = self::nulList($allTrackedNames['stdout']);
        $untracked = self::nulList($allUntrackedNames['stdout']);
        $scopedUntracked = self::nulList($scopedUntrackedNames['stdout']);
        $changed = array_values(array_unique([...$tracked, ...$untracked]));
        sort($changed, SORT_STRING);
        sort($untracked, SORT_STRING);

        $patch = rtrim($trackedPatch['stdout'], "\n");
        foreach ($scopedUntracked as $path) {
            $untrackedPatch = $this->untrackedPatch($root, $path);
            if ($untrackedPatch === '') {
                continue;
            }
            $patch .= ($patch === '' ? '' : "\n\n") . $untrackedPatch;
        }

        return new HumanReviewDiff(
            available: true,
            baseCommit: $base,
            changedFiles: $changed,
            untrackedFiles: $untracked,
            patch: $patch,
        );
    }

    /** @return list<string> */
    private static function nulList(string $value): array
    {
        $items = array_values(array_filter(
            explode("\0", $value),
            static fn (string $item): bool => $item !== '',
        ));
        sort($items, SORT_STRING);

        return $items;
    }

    private function untrackedPatch(string $root, string $path): string
    {
        $absolute = $root . '/' . $path;
        if (!is_file($absolute) || is_link($absolute)) {
            return '';
        }

        $content = file_get_contents($absolute);
        if ($content === false) {
            return '';
        }

        $displayPath = self::patchPath($path);
        $header = "diff --git a/{$displayPath} b/{$displayPath}\n"
            . "new file mode untracked\n"
            . "--- /dev/null\n"
            . "+++ b/{$displayPath}\n";

        if (str_contains($content, "\0") || preg_match('//u', $content) !== 1) {
            return $header . "Binary files /dev/null and b/{$displayPath} differ";
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = $normalized === '' ? [] : explode("\n", $normalized);
        $endsWithNewline = $normalized !== '' && str_ends_with($normalized, "\n");
        if ($endsWithNewline) {
            array_pop($lines);
        }

        $body = $header . sprintf("@@ -0,0 +1,%d @@\n", count($lines));
        foreach ($lines as $line) {
            $body .= '+' . $line . "\n";
        }
        if (!$endsWithNewline && $normalized !== '') {
            $body .= "\\ No newline at end of file\n";
        }

        return rtrim($body, "\n");
    }

    private static function patchPath(string $path): string
    {
        if (preg_match('/^[A-Za-z0-9._\/-]+$/', $path) === 1) {
            return $path;
        }

        $encoded = json_encode($path, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '[unprintable-path]';
    }

    /**
     * @param non-empty-list<string> $command
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function run(string $workingDirectory, array $command): array
    {
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workingDirectory,
        );
        if (!is_resource($process)) {
            return ['exit' => 127, 'stdout' => '', 'stderr' => 'Unable to start process.'];
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }
}
