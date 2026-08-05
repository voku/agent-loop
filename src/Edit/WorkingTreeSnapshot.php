<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

/**
 * What the working tree looked like at one point in time, as far as Git can see it.
 *
 * Only the paths Git already reports as dirty are hashed. Hashing the whole repository to find a
 * handful of edited files would cost seconds on a large project, and the interesting set is exactly
 * "paths that differ from HEAD before or after the edit" - a path that is clean in both snapshots
 * cannot have been changed by the runner.
 */
final readonly class WorkingTreeSnapshot
{
    /**
     * @param array<string, string> $entries path => `<git status code>:<content sha256|absent>`
     */
    public function __construct(
        public bool $available,
        public ?string $head,
        public array $entries,
    ) {
    }

    public static function unavailable(): self
    {
        return new self(false, null, []);
    }

    /**
     * Paths whose Git status or content differs between the two snapshots.
     *
     * A file the agent rewrote back to its original bytes is deliberately not reported: the point
     * of the list is what the edit changed in the repository, not what it touched on disk.
     *
     * @return list<string>
     */
    public function changedPathsSince(self $before): array
    {
        if (!$this->available || !$before->available) {
            return [];
        }

        $paths = [];
        foreach ($this->entries as $path => $state) {
            if (($before->entries[$path] ?? null) !== $state) {
                $paths[$path] = true;
            }
        }
        foreach ($before->entries as $path => $state) {
            if (!array_key_exists($path, $this->entries)) {
                $paths[$path] = true;
            }
        }

        $changed = array_keys($paths);
        sort($changed, SORT_STRING);

        return $changed;
    }
}
