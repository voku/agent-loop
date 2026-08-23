<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit\Refactor;

use RuntimeException;

/** One exact deletion edit decoded from agent-map's class_constant_removal_plan contract. */
final readonly class ClassConstantRemovalPlanEditEvidence
{
    public function __construct(
        public string $path,
        public string $sourceSha256,
        public int $startFilePos,
        public int $endFilePos,
        public int $lineStart,
        public int $lineEnd,
        public string $expected,
        public string $role,
        public string $symbolId,
        public string $resolution,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['replacement'] ?? null) !== '') {
            throw new RuntimeException('Class-constant removal edit must use an empty replacement.');
        }

        $edit = new self(
            path: self::string($data, 'path'),
            sourceSha256: self::string($data, 'source_sha256'),
            startFilePos: self::integer($data, 'start_file_pos'),
            endFilePos: self::integer($data, 'end_file_pos'),
            lineStart: self::integer($data, 'line_start'),
            lineEnd: self::integer($data, 'line_end'),
            expected: self::string($data, 'expected'),
            role: self::string($data, 'role'),
            symbolId: self::string($data, 'symbol_id'),
            resolution: self::string($data, 'resolution'),
        );

        if ($edit->startFilePos < 0 || $edit->endFilePos < $edit->startFilePos) {
            throw new RuntimeException('Class-constant removal edit contains an invalid byte range.');
        }
        if ($edit->lineStart < 1 || $edit->lineEnd < $edit->lineStart) {
            throw new RuntimeException('Class-constant removal edit contains an invalid line range.');
        }
        if (preg_match('/\Asha256:[a-f0-9]{64}\z/D', $edit->sourceSha256) !== 1) {
            throw new RuntimeException('Class-constant removal edit contains an invalid source SHA-256.');
        }
        if ($edit->role !== 'class_constant_declaration_removal') {
            throw new RuntimeException('Class-constant removal edit has an unsupported role.');
        }
        if ($edit->resolution !== 'parser_resolved') {
            throw new RuntimeException('Class-constant removal edit must be parser-resolved.');
        }

        return $edit;
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Class-constant removal edit requires non-empty string ' . $key . '.');
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function integer(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new RuntimeException('Class-constant removal edit requires integer ' . $key . '.');
        }

        return $value;
    }
}
