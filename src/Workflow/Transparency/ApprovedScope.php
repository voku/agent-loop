<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow\Transparency;

use voku\AgentLoop\Workflow\TaskContract;

/**
 * The one owner of "is this repository-relative path inside approved Contract scope".
 *
 * `WorkflowReportCommand` answered this privately, which made every other
 * projection that needed the same answer either call the report or write a
 * second matcher. Two matchers agree only until one of them learns about
 * trailing slashes.
 */
final readonly class ApprovedScope
{
    /** @param list<string> $entries */
    private function __construct(public array $entries)
    {
    }

    public static function fromContract(?TaskContract $contract): self
    {
        return self::fromEntries($contract === null ? [] : $contract->scope);
    }

    /** @param list<string> $entries */
    public static function fromEntries(array $entries): self
    {
        return new self($entries);
    }

    /**
     * An empty Contract scope contains nothing. That is deliberate: a missing
     * Contract must not silently approve every observed change.
     */
    public function contains(string $path): bool
    {
        $path = self::normalize($path);
        if ($path === '') {
            return false;
        }

        foreach ($this->entries as $entry) {
            $entry = self::normalize($entry);
            if ($entry === '.' || $path === $entry || str_starts_with($path, $entry . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $paths
     * @return array{inside: list<string>, outside: list<string>}
     */
    public function partition(array $paths): array
    {
        $inside = [];
        $outside = [];
        foreach ($paths as $path) {
            if ($this->contains($path)) {
                $inside[] = $path;
                continue;
            }
            $outside[] = $path;
        }

        return ['inside' => $inside, 'outside' => $outside];
    }

    private static function normalize(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }
}
