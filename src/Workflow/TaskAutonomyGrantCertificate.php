<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Tamper-evident task grant evidence. A valid signature is not, by itself,
 * proof that the issuing operator was authenticated or that a grant remains
 * unrevoked; both checks belong to a separate trusted owner boundary.
 */
final readonly class TaskAutonomyGrantCertificate
{
    private const string DATE_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    public function __construct(
        public TaskAutonomyGrant $grant,
        public string $nonce,
        public string $signature,
    ) {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $nonce) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $signature) !== 1) {
            throw new InvalidArgumentException('Task autonomy certificate requires a 128-bit nonce and SHA-256 signature.');
        }
        if ($grant->revokedAt !== null) {
            throw new InvalidArgumentException('Revocation cannot be embedded as a new issuance; it needs a trusted current revocation record.');
        }
    }

    /**
     * @return array{
     *   schema_version: '1.0', kind: 'task_autonomy_grant',
     *   task_id: string, contract_revision: int, contract_digest: string,
     *   requested_by: string, issued_at: string, expires_at: string,
     *   nonce: string, signature: string
     * }
     */
    public function toArray(): array
    {
        return [
            'schema_version' => '1.0',
            'kind' => 'task_autonomy_grant',
            'task_id' => $this->grant->taskId,
            'contract_revision' => $this->grant->contractRevision,
            'contract_digest' => $this->grant->contractDigest,
            'requested_by' => $this->grant->requestedBy,
            'issued_at' => self::timestamp($this->grant->issuedAt),
            'expires_at' => self::timestamp($this->grant->expiresAt),
            'nonce' => $this->nonce,
            'signature' => $this->signature,
        ];
    }

    /** @return array<string, string|int> */
    public function signedClaims(): array
    {
        $claims = $this->toArray();
        unset($claims['signature']);

        return $claims;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $fields = [
            'schema_version', 'kind', 'task_id', 'contract_revision',
            'contract_digest', 'requested_by', 'issued_at', 'expires_at',
            'nonce', 'signature',
        ];
        $keys = array_keys($data);
        sort($keys);
        $expected = $fields;
        sort($expected);
        if ($keys !== $expected || $data['schema_version'] !== '1.0' || $data['kind'] !== 'task_autonomy_grant') {
            throw new InvalidArgumentException('Unknown or incomplete task autonomy certificate schema.');
        }
        foreach (['task_id', 'contract_digest', 'requested_by', 'issued_at', 'expires_at', 'nonce', 'signature'] as $field) {
            if (!is_string($data[$field])) {
                throw new InvalidArgumentException('Task autonomy certificate requires string field: ' . $field);
            }
        }
        if (!is_int($data['contract_revision'])) {
            throw new InvalidArgumentException('Task autonomy certificate requires an integer Contract revision.');
        }

        return new self(
            new TaskAutonomyGrant(
                $data['task_id'],
                $data['contract_revision'],
                $data['contract_digest'],
                $data['requested_by'],
                self::parseTimestamp($data['issued_at']),
                self::parseTimestamp($data['expires_at']),
            ),
            $data['nonce'],
            $data['signature'],
        );
    }

    private static function timestamp(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format(self::DATE_FORMAT);
    }

    private static function parseTimestamp(string $text): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        $value = DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, $text, $utc);
        if ($value === false || self::timestamp($value) !== $text) {
            throw new InvalidArgumentException('Task autonomy certificate requires a canonical UTC timestamp.');
        }

        return $value;
    }
}
