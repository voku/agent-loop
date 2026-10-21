<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use DateTimeImmutable;
use InvalidArgumentException;

/** A frozen real-host observation, separate from installed-version discovery. */
final readonly class HostRuntimeEvidence
{
    /**
     * @param non-empty-string $host
     * @param non-empty-string $hostVersion
     * @param non-empty-string $evidenceRef
     * @param non-empty-string $verifiedBy
     */
    public function __construct(
        public string $host,
        public HostCapability $capability,
        public string $hostVersion,
        public DateTimeImmutable $verifiedAt,
        public string $evidenceRef,
        public string $verifiedBy,
    ) {
        foreach ([$host, $hostVersion, $evidenceRef, $verifiedBy] as $identity) {
            if (trim($identity) === '') {
                throw new InvalidArgumentException('Runtime evidence requires version and verification provenance.');
            }
        }
    }

    /** @return 'exact_verified'|'drift_unverified'|'unavailable' */
    public function classification(?string $observedVersion): string
    {
        if ($observedVersion === null) {
            return 'unavailable';
        }

        return $observedVersion === $this->hostVersion ? 'exact_verified' : 'drift_unverified';
    }

    /** @return array<non-empty-string, string> */
    public function facts(?string $observedVersion): array
    {
        return [
            'runtime_evidence_state' => $this->classification($observedVersion),
            'tested_host' => $this->host,
            'tested_capability' => $this->capability->value,
            'tested_version' => $this->hostVersion,
            'verified_at' => $this->verifiedAt->format(DATE_ATOM),
            'evidence_ref' => $this->evidenceRef,
            'verified_by' => $this->verifiedBy,
        ];
    }
}
