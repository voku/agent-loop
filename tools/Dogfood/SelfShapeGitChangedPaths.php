<?php

declare(strict_types=1);

namespace voku\AgentLoop\Dogfood;

/**
 * All paths in a Git diff, including deletions and both sides of renames.
 * The maintenance-only approval gate must not omit disallowed changes.
 */
final readonly class SelfShapeGitChangedPaths
{
    public function __construct(private ProcessRunner $runner)
    {
    }

    /**
     * @return list<string>
     */
    public function between(string $base, string $head): array
    {
        // --no-renames exposes both old and new paths. NUL separation preserves
        // legitimate spaces and newlines instead of normalizing path identities.
        $stdout = $this->runner->mustRun([
            'git', 'diff', '--name-only', '-z', '--no-renames', $base, $head, '--',
        ])['stdout'];

        return array_values(array_filter(
            explode("\0", $stdout),
            static fn (string $path): bool => $path !== '',
        ));
    }
}
