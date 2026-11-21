<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;
use voku\AgentLoop\Run\CanonicalJson;

/**
 * Cryptographic seal for an already verified owner decision.
 *
 * This class does NOT authenticate the operator. Only a trusted, isolated
 * control-plane process may hold the signing key and issue certificates.
 * Never expose the signing key to a coding provider, workspace or CI artifact.
 */
final readonly class TaskAutonomyGrantSealer
{
    private const string DOMAIN = "agent-loop.task-autonomy-grant.v1\n";

    public function __construct(
        #[SensitiveParameter]
        private string $key,
    ) {
        if (strlen($key) < 32) {
            throw new InvalidArgumentException('Task autonomy signing key must contain at least 32 bytes.');
        }
    }

    public function seal(TaskAutonomyGrant $request): TaskAutonomyGrantCertificate
    {
        if ($request->revokedAt !== null) {
            throw new RuntimeException('A revoked autonomy request cannot be newly sealed.');
        }
        $nonce = bin2hex(random_bytes(16));

        return new TaskAutonomyGrantCertificate($request, $nonce, $this->signatureFor($request, $nonce));
    }

    /**
     * Checks integrity and signing-key provenance only. The caller MUST still
     * check fresh task scope, expiry and authenticated revocation state.
     */
    public function hasValidSeal(TaskAutonomyGrantCertificate $certificate): bool
    {
        return hash_equals(
            $this->signatureFor($certificate->grant, $certificate->nonce),
            $certificate->signature,
        );
    }

    private function signatureFor(TaskAutonomyGrant $grant, string $nonce): string
    {
        // The signer never serializes an uncontrolled object graph. This is a
        // fixed, canonical owner data shape with domain separation.
        $unsigned = new TaskAutonomyGrantCertificate($grant, $nonce, str_repeat('0', 64));

        return hash_hmac(
            'sha256',
            self::DOMAIN . CanonicalJson::pretty($unsigned->signedClaims()),
            $this->key,
        );
    }
}
