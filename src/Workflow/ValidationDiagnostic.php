<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

/**
 * Immutable diagnostic record for a failed validation command.
 *
 * Categorizes and parses tool-specific failure output (PHPStan, PHPUnit,
 * syntax errors, etc.) so autonomous repair loops receive actionable targets.
 */
final readonly class ValidationDiagnostic
{
    public const string TOOL_PHPSTAN = 'phpstan';
    public const string TOOL_PHPUNIT = 'phpunit';
    public const string TOOL_PHP_LINTER = 'php_linter';
    public const string TOOL_PHP_CS_FIXER = 'php_cs_fixer';
    public const string TOOL_GENERIC = 'generic';

    /**
     * @param list<array{file?: string, line?: int, message: string}> $errors
     */
    public function __construct(
        public string $taskId,
        public int $contractRevision,
        public string $command,
        public int $exitCode,
        public string $rawOutput,
        public string $toolCategory,
        public array $errors,
        public string $recordedAt,
    ) {
    }

    public static function fromExecution(
        string $taskId,
        int $contractRevision,
        string $command,
        int $exitCode,
        string $rawOutput,
    ): self {
        $category = self::detectToolCategory($command, $rawOutput);
        $errors = self::parseErrors($category, $rawOutput);

        // Cap raw output at 32KB to avoid unbounded memory / storage
        $cappedOutput = mb_strcut($rawOutput, 0, 32768, 'UTF-8');

        return new self(
            taskId: $taskId,
            contractRevision: $contractRevision,
            command: $command,
            exitCode: $exitCode,
            rawOutput: $cappedOutput,
            toolCategory: $category,
            errors: $errors,
            recordedAt: gmdate('c'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => '1.0',
            'task_id' => $this->taskId,
            'contract_revision' => $this->contractRevision,
            'command' => $this->command,
            'exit_code' => $this->exitCode,
            'tool_category' => $this->toolCategory,
            'errors' => $this->errors,
            'raw_output' => $this->rawOutput,
            'recorded_at' => $this->recordedAt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $errors = [];
        $rawErrors = $data['errors'] ?? [];
        if (is_array($rawErrors)) {
            foreach ($rawErrors as $error) {
                if (is_array($error) && isset($error['message']) && is_string($error['message'])) {
                    $item = ['message' => $error['message']];
                    if (isset($error['file']) && is_string($error['file'])) {
                        $item['file'] = $error['file'];
                    }
                    if (isset($error['line']) && is_int($error['line'])) {
                        $item['line'] = $error['line'];
                    }
                    $errors[] = $item;
                }
            }
        }

        return new self(
            taskId: (string) ($data['task_id'] ?? ''),
            contractRevision: (int) ($data['contract_revision'] ?? 1),
            command: (string) ($data['command'] ?? ''),
            exitCode: (int) ($data['exit_code'] ?? 1),
            rawOutput: (string) ($data['raw_output'] ?? ''),
            toolCategory: (string) ($data['tool_category'] ?? self::TOOL_GENERIC),
            errors: $errors,
            recordedAt: (string) ($data['recorded_at'] ?? gmdate('c')),
        );
    }

    private static function detectToolCategory(string $command, string $output): string
    {
        $lowerCmd = strtolower($command);
        if (str_contains($lowerCmd, 'phpstan')) {
            return self::TOOL_PHPSTAN;
        }
        if (str_contains($lowerCmd, 'phpunit') || str_contains($lowerCmd, 'codecept')) {
            return self::TOOL_PHPUNIT;
        }
        if (str_contains($lowerCmd, 'php -l')) {
            return self::TOOL_PHP_LINTER;
        }
        if (str_contains($lowerCmd, 'php-cs-fixer') || str_contains($lowerCmd, 'cs-fixer')) {
            return self::TOOL_PHP_CS_FIXER;
        }

        // Detect from output patterns
        if (str_contains($output, 'PHP Parse error:')) {
            return self::TOOL_PHP_LINTER;
        }
        if (str_contains($output, 'PHPUnit') || str_contains($output, 'FAILURES!')) {
            return self::TOOL_PHPUNIT;
        }
        if (str_contains($output, '[ERROR] Found') && str_contains($output, 'error')) {
            return self::TOOL_PHPSTAN;
        }

        return self::TOOL_GENERIC;
    }

    /**
     * @return list<array{file?: string, line?: int, message: string}>
     */
    private static function parseErrors(string $category, string $output): array
    {
        $errors = [];

        switch ($category) {
            case self::TOOL_PHP_LINTER:
                if (preg_match('/Parse error:\s*(.*?)\s+in\s+([^\r\n]+?)\s+on line\s+(\d+)/i', $output, $matches)) {
                    $errors[] = [
                        'file' => trim($matches[2]),
                        'line' => (int) $matches[3],
                        'message' => 'Parse error: ' . trim($matches[1]),
                    ];
                }
                break;

            case self::TOOL_PHPSTAN:
                // Pattern 1: Table format - "Line   path/to/file.php" followed by "12     Message"
                $currentFile = null;
                $lines = explode("\n", $output);
                foreach ($lines as $line) {
                    $trimmed = trim($line);
                    if (preg_match('/^Line\s+([a-zA-Z0-9_\/.-]+\.php)/', $trimmed, $m)) {
                        $currentFile = trim($m[1]);
                        continue;
                    }
                    if ($currentFile !== null && preg_match('/^(\d+)\s+(.+)$/', $trimmed, $m)) {
                        $msg = trim($m[2]);
                        if (!str_starts_with($msg, '------') && !str_starts_with($msg, "\u{1F6AA}") && !str_starts_with($msg, "\u{1F4A1}")) {
                            $errors[] = [
                                'file' => $currentFile,
                                'line' => (int) $m[1],
                                'message' => $msg,
                            ];
                        }
                    }
                }

                // Pattern 2: Single-line format "path/to/file.php:12:Message"
                if ($errors === [] && preg_match_all('/([a-zA-Z0-9_\/.-]+\.php):(\d+):([^\r\n]+)/', $output, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $errors[] = [
                            'file' => trim($match[1]),
                            'line' => (int) $match[2],
                            'message' => trim($match[3]),
                        ];
                    }
                }
                break;

            case self::TOOL_PHPUNIT:
                if (preg_match_all('/\d+\)\s+([^\r\n]+)[\r\n]+([^\r\n]+)(?:[\r\n]+([^\r\n]+?):(\d+))?/', $output, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $err = [
                            'message' => trim($match[1]) . ': ' . trim($match[2]),
                        ];
                        if (isset($match[3]) && trim($match[3]) !== '' && isset($match[4])) {
                            $err['file'] = trim($match[3]);
                            $err['line'] = (int) $match[4];
                        }
                        $errors[] = $err;
                    }
                }
                break;

            case self::TOOL_PHP_CS_FIXER:
                if (preg_match_all('/\d+\)\s+([a-zA-Z0-9_\/.-]+\.php)/', $output, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $errors[] = [
                            'file' => trim($match[1]),
                            'message' => 'Code style violation; run php-cs-fixer to format.',
                        ];
                    }
                }
                break;
        }

        if ($errors === []) {
            $nonEmpty = array_values(array_filter(
                array_map('trim', explode("\n", $output)),
                static fn (string $l): bool => $l !== '',
            ));
            $summaryLines = array_slice($nonEmpty, -5);
            if ($summaryLines !== []) {
                $errors[] = [
                    'message' => implode(' | ', $summaryLines),
                ];
            } else {
                $errors[] = [
                    'message' => 'Validation command exited with non-zero status.',
                ];
            }
        }

        return $errors;
    }
}
