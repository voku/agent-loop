<?php

declare(strict_types=1);

namespace voku\AgentLoop\GitHooks;

use InvalidArgumentException;

/**
 * Configuration for the package-owned `pre-commit` and `commit-msg` runners.
 *
 * The mechanics - which files Git staged, how to batch them, when to skip a merge
 * commit, how a commit header is shaped - are the same in every repository and live
 * in this package. What differs per project is the list of check commands and the
 * exact commit convention, so those are data in `.agent-loop/githooks.json`.
 */
final readonly class GitHookConfig
{
    public const string DEFAULT_PATH = '.agent-loop/githooks.json';

    /**
     * @param list<string>                                                 $filePatterns
     * @param list<string>                                                 $excludePaths
     * @param list<array{name: string, command: string, optional: bool, per_file: bool}> $checks
     * @param list<array{pattern: string, message: string}>                $forbiddenPatterns
     * @param list<string>                                                 $vaguePhrases
     * @param int<1, max>                                                  $batchSize
     */
    private function __construct(
        public bool $skipMergeCommits,
        public array $filePatterns,
        public array $excludePaths,
        public int $batchSize,
        public array $checks,
        public string $headerPattern,
        public string $headerHint,
        public ?string $trivialHeaderPattern,
        public array $forbiddenPatterns,
        public ?string $requiredSection,
        public array $vaguePhrases,
        public int $vagueWordThreshold,
    ) {
    }

    public static function load(string $rootPath, ?string $configPath = null): self
    {
        $path = $configPath ?? self::DEFAULT_PATH;
        $absolutePath = str_starts_with($path, '/') ? $path : rtrim($rootPath, '/') . '/' . $path;
        if (!is_file($absolutePath)) {
            return self::fromArray([]);
        }

        $content = file_get_contents($absolutePath);
        if (!is_string($content)) {
            throw new InvalidArgumentException('Unable to read git hook configuration: ' . $absolutePath);
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Git hook configuration is not valid JSON: ' . $absolutePath);
        }

        /** @var array<string, mixed> $decoded */
        return self::fromArray($decoded);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $preCommit = is_array($data['pre_commit'] ?? null) ? $data['pre_commit'] : [];
        $commitMessage = is_array($data['commit_msg'] ?? null) ? $data['commit_msg'] : [];

        return new self(
            (bool) ($preCommit['skip_merge_commits'] ?? true),
            self::stringList($preCommit['file_patterns'] ?? ['*.php']),
            self::stringList($preCommit['exclude_paths'] ?? []),
            max(1, (int) ($preCommit['batch_size'] ?? 500)),
            self::checks($preCommit['checks'] ?? []),
            is_string($commitMessage['header_pattern'] ?? null) ? $commitMessage['header_pattern'] : '',
            is_string($commitMessage['header_hint'] ?? null) ? $commitMessage['header_hint'] : '',
            is_string($commitMessage['trivial_header_pattern'] ?? null) ? $commitMessage['trivial_header_pattern'] : null,
            self::forbiddenPatterns($commitMessage['forbidden_patterns'] ?? []),
            is_string($commitMessage['required_section'] ?? null) ? $commitMessage['required_section'] : null,
            self::stringList($commitMessage['vague_phrases'] ?? []),
            max(0, (int) ($commitMessage['vague_word_threshold'] ?? 8)),
        );
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $entries = [];
        foreach ($value as $entry) {
            if (is_string($entry) && $entry !== '') {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param mixed $value
     * @return list<array{name: string, command: string, optional: bool, per_file: bool}>
     */
    private static function checks(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $checks = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            /** @var array<string, mixed> $entry */
            $resolved = CheckCommandFactory::create($entry);

            $name = $entry['name'] ?? null;
            $checks[] = [
                'name' => is_string($name) && $name !== '' ? $name : $resolved['command'],
                'command' => $resolved['command'],
                'optional' => (bool) ($entry['optional'] ?? false),
                'per_file' => $resolved['per_file'],
            ];
        }

        return $checks;
    }

    /**
     * @param mixed $value
     * @return list<array{pattern: string, message: string}>
     */
    private static function forbiddenPatterns(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $patterns = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $pattern = $entry['pattern'] ?? null;
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }

            $message = $entry['message'] ?? null;
            $patterns[] = [
                'pattern' => $pattern,
                'message' => is_string($message) && $message !== '' ? $message : 'forbidden pattern found: ' . $pattern,
            ];
        }

        return $patterns;
    }
}
