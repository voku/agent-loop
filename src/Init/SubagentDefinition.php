<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;

final readonly class SubagentDefinition
{
    /**
     * @var list<string>
     */
    private const array LOCAL_PATH_PATTERNS = [
        '/\/home\/[^\/\s]+\/\.codex\//',
        '/\/home\/[^\/\s]+\/\.gemini\//',
        '/\/home\/[^\/\s]+\/\.claude\//',
        '/\/home\/[^\/\s]+\/\.agents\//',
        '/\/Users\/[^\/\s]+\/\.codex\//',
        '/\/Users\/[^\/\s]+\/\.gemini\//',
        '/\/Users\/[^\/\s]+\/\.claude\//',
        '/\/Users\/[^\/\s]+\/\.agents\//',
        '/~\/\.codex\//',
        '/~\/\.gemini\//',
        '/~\/\.claude\//',
        '/~\/\.agents\//',
    ];

    private function __construct(
        private string $name,
        private string $description,
        private string $body,
    ) {
    }

    public static function fromCanonicalFile(string $filePath): self
    {
        $content = self::readFile($filePath);
        $parsed = self::parseContent($filePath, $content);

        return new self(
            $parsed['frontmatter']['name'],
            $parsed['frontmatter']['description'],
            $parsed['body'],
        );
    }

    /**
     * @return list<string>
     */
    public static function validationErrors(string $filePath): array
    {
        $errors = [];
        if (!is_file($filePath)) {
            return ['Subagent file not found'];
        }

        if (!is_readable($filePath)) {
            return ['Subagent file is not readable'];
        }

        $content = file_get_contents($filePath);
        if (!is_string($content) || trim($content) === '') {
            return ['Subagent file is empty'];
        }

        try {
            $parsed = self::parseContent($filePath, $content);
        } catch (InvalidArgumentException $exception) {
            return [$exception->getMessage()];
        }

        foreach (preg_split('/\R/', $content) ?: [] as $index => $line) {
            foreach (self::LOCAL_PATH_PATTERNS as $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    $errors[] = 'local client path reference found on line ' . ($index + 1) . '; use repo-relative paths instead';

                    break;
                }
            }
        }

        if (trim($parsed['body']) === '') {
            $errors[] = 'Subagent body is empty';
        }

        return $errors;
    }

    public function renderForClient(string $client): string
    {
        $frontmatter = [
            'name' => $this->name,
            'description' => $this->description,
        ];

        if ($client === 'antigravity') {
            $frontmatter['kind'] = 'local';
            $frontmatter['max_turns'] = '12';
            $frontmatter['temperature'] = '0.2';
        } elseif ($client !== 'copilot') {
            throw new InvalidArgumentException('Unsupported subagent sync target: ' . $client);
        }

        $lines = ["---"];
        foreach ($frontmatter as $key => $value) {
            if (is_numeric($value)) {
                $lines[] = $key . ': ' . $value;

                continue;
            }

            $escaped = str_replace('"', '\"', $value);
            $lines[] = $key . ': "' . $escaped . '"';
        }
        $lines[] = "---";
        $lines[] = '';
        $lines[] = ltrim($this->body);

        return implode("\n", $lines);
    }

    private static function readFile(string $filePath): string
    {
        if (!is_readable($filePath)) {
            throw new InvalidArgumentException('Subagent file is not readable: ' . $filePath);
        }

        $content = file_get_contents($filePath);
        if (!is_string($content) || trim($content) === '') {
            throw new InvalidArgumentException('Subagent file is empty: ' . $filePath);
        }

        return $content;
    }

    /**
     * @return array{frontmatter: array{name: string, description: string}, body: string}
     */
    private static function parseContent(string $filePath, string $content): array
    {
        $normalizedContent = str_replace(["\r\n", "\r"], "\n", $content);
        if (!str_starts_with($normalizedContent, "---\n")) {
            throw new InvalidArgumentException('No YAML frontmatter found');
        }

        if (preg_match('/^---\n(.*?)\n---\n?(.*)$/s', $normalizedContent, $matches) !== 1) {
            throw new InvalidArgumentException('Invalid frontmatter format');
        }

        $frontmatter = self::parseFrontmatter($matches[1]);
        $name = $frontmatter['name'] ?? null;
        $description = $frontmatter['description'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            throw new InvalidArgumentException("Missing or invalid 'name' in frontmatter");
        }

        $expectedName = basename($filePath);
        if (str_ends_with($expectedName, '.agent.md')) {
            $expectedName = substr($expectedName, 0, -strlen('.agent.md'));
        } elseif (str_ends_with($expectedName, '.md')) {
            $expectedName = substr($expectedName, 0, -strlen('.md'));
        }

        if ($name !== $expectedName) {
            throw new InvalidArgumentException("Subagent name '{$name}' must match filename stem '{$expectedName}'");
        }

        if (!is_string($description) || trim($description) === '') {
            throw new InvalidArgumentException("Missing or invalid 'description' in frontmatter");
        }

        return [
            'frontmatter' => [
                'name' => $name,
                'description' => $description,
            ],
            'body' => $matches[2],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function parseFrontmatter(string $frontmatterBlock): array
    {
        $parsed = [];
        foreach (preg_split('/\R/', $frontmatterBlock) ?: [] as $line) {
            $trimmedLine = trim($line);
            if ($trimmedLine === '') {
                continue;
            }

            $parts = explode(':', $trimmedLine, 2);
            if (count($parts) !== 2) {
                throw new InvalidArgumentException('Invalid YAML in frontmatter');
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);
            if ($key === '') {
                throw new InvalidArgumentException('Invalid YAML in frontmatter');
            }

            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            $parsed[$key] = str_replace('\"', '"', $value);
        }

        return $parsed;
    }
}
