<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit\Refactor;

use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\AnalysisFingerprint;

/** Snapshot identity decoded from the public agent-map class-move wire contract. */
final readonly class ClassMovePlanProvenanceEvidence
{
    /** Captures the exact Map identity published with the class-move plan. */
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
        if (!is_string($mapDigest) || !preg_match('/\Asha256:[a-f0-9]{64}\z/D', $mapDigest)) {
            throw new RuntimeException('Class move plan does not match the current map identity; rebuild and re-plan.');
        }
        if (!is_string($backend) || $backend === '') {
            throw new RuntimeException('Class move plan provenance requires a backend.');
        }
        if (!is_array($fingerprint)) {
            throw new RuntimeException('Class move plan provenance requires an analysis fingerprint.');
        }

        return new self($mapDigest, $backend, AnalysisFingerprint::fromArray($fingerprint));
    }

    /** Fails closed when current Map identity differs from the frozen plan provenance. */
    public function assertMatches(AgentMapIndex $map): void
    {
        if (
            $this->mapDigest !== $map->mapDigest()
            || $this->backend !== $map->backend
            || $this->analysisFingerprint->toArray() !== $map->fingerprint?->toArray()
        ) {
            throw new RuntimeException('Class move plan does not match the current map identity; rebuild and re-plan.');
        }
    }
}
