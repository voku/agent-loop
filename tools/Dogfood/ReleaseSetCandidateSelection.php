<?php

declare(strict_types=1);

namespace voku\AgentLoop\Dogfood;

use InvalidArgumentException;

/**
 * Explicit owner candidates for installed release-set dogfood.
 *
 * Candidate checkout presence is deliberately not discovery. A stale build/
 * directory must never change which release set the gate claims to exercise.
 */
final readonly class ReleaseSetCandidateSelection
{
    /** @var array<string, non-empty-string> */
    private const PATHS = [
        'voku/agent-session' => 'build/candidate-agent-session',
        'voku/agent-recall-compiler' => 'build/candidate-agent-recall-compiler',
        'voku/agent-learning' => 'build/candidate-agent-learning',
    ];

    /** @var list<string> */
    private array $packages;

    /** @param list<string> $packages */
    public function __construct(array $packages)
    {
        $unique = [];
        foreach ($packages as $package) {
            if (!isset(self::PATHS[$package])) {
                throw new InvalidArgumentException('Unsupported release-set candidate package: ' . $package);
            }
            $unique[$package] = true;
        }
        $this->packages = array_keys($unique);
    }

    public function includes(string $package): bool
    {
        return in_array($package, $this->packages, true);
    }

    /** @return list<string> */
    public function packages(): array
    {
        return $this->packages;
    }

    /** @return array<string, non-empty-string> package => repository-relative checkout */
    public function paths(): array
    {
        $paths = [];
        foreach ($this->packages as $package) {
            $paths[$package] = self::PATHS[$package];
        }

        return $paths;
    }
}
