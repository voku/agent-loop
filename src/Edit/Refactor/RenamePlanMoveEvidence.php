<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit\Refactor;

use RuntimeException;

/** One preconditioned class-file move decoded from the public agent-map wire contract. */
final readonly class RenamePlanMoveEvidence
{
    public function __construct(
        public string $fromPath,
        public string $toPath,
        public string $sourceSha256,
        public string $reason,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $move = new self(
            fromPath: self::string($data, 'from_path'),
            toPath: self::string($data, 'to_path'),
            sourceSha256: self::string($data, 'source_sha256'),
            reason: self::string($data, 'reason'),
        );
        if (!preg_match('/\Asha256:[a-f0-9]{64}\z/D', $move->sourceSha256)) {
            throw new RuntimeException('Class rename move contains an invalid source SHA-256.');
        }

        return $move;
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('Class rename move requires non-empty string ' . $key . '.');
        }

        return $value;
    }
}
