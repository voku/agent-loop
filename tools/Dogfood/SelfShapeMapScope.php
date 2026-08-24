<?php

declare(strict_types=1);

namespace voku\AgentLoop\Dogfood;

/**
 * Selects the PHP scope that must be indexed before self-shape approval.
 *
 * The stable code/test/tool roots keep Recall useful, while changed PHP files
 * outside those roots are added explicitly so the approval gate never sees a
 * governed implementation file that the fresh map omitted.
 */
final readonly class SelfShapeMapScope
{
    /** @var non-empty-list<string> */
    private const array BASE_PATHS = ['src', 'tests', 'tools'];

    /**
     * @param list<string> $changedFiles
     *
     * @return non-empty-list<string>
     */
    public function paths(array $changedFiles): array
    {
        $paths = self::BASE_PATHS;

        foreach ($changedFiles as $file) {
            if (!str_ends_with($file, '.php') || $this->coveredByBasePath($file)) {
                continue;
            }

            $paths[] = $file;
        }

        /** @var non-empty-list<string> $paths */
        $paths = array_values(array_unique($paths));

        return $paths;
    }

    private function coveredByBasePath(string $file): bool
    {
        foreach (self::BASE_PATHS as $path) {
            if ($file === $path || str_starts_with($file, $path . '/')) {
                return true;
            }
        }

        return false;
    }
}
