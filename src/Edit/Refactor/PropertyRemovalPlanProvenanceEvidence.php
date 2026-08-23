<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit\Refactor;

use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\AnalysisFingerprint;

/** Snapshot identity decoded from one agent-map property removal plan. */
final readonly class PropertyRemovalPlanProvenanceEvidence
{
    public function __construct(
        public string $mapDigest,
        public string $backend,
        public AnalysisFingerprint $analysisFingerprint,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $mapDigest = $data['map_digest'] ?? null;
        $backend = $data['backend'] ?? null;
        $fingerprint = $data['analysis_fingerprint'] ?? null;
        if (!is_string($mapDigest) || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $mapDigest) !== 1) {
            throw new RuntimeException('Property removal plan does not contain a valid map identity.');
        }
        if (!is_string($backend) || $backend === '') {
            throw new RuntimeException('Property removal plan provenance requires a backend.');
        }
        if (!is_array($fingerprint)) {
            throw new RuntimeException('Property removal plan provenance requires an analysis fingerprint.');
        }

        return new self($mapDigest, $backend, AnalysisFingerprint::fromArray($fingerprint));
    }

    public function assertMatches(AgentMapIndex $map): void
    {
        if (!str_ends_with($map->backend, '+phpstan')) {
            throw new RuntimeException('Governed property removal requires a PHPStan-backed current map.');
        }
        if (
            $this->mapDigest !== $map->mapDigest()
            || $this->backend !== $map->backend
            || $this->analysisFingerprint->toArray() !== $map->fingerprint?->toArray()
        ) {
            throw new RuntimeException('Property removal plan does not match the current map identity; rebuild and re-plan.');
        }
    }
}
