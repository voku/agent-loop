<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;

final readonly class HostRuntimeProbe
{
    /**
     * @var array<string, non-empty-string|null>
     */
    private const array COMMANDS = [
        'codex' => 'codex',
        'claude' => 'claude',
        'copilot' => 'copilot',
        'gemini' => 'gemini',
        'antigravity' => null,
    ];

    public function __construct(
        private ?string $path = null,
        private ?string $pathExt = null,
    ) {
    }

    /**
     * @return array{
     *     status: 'available'|'missing'|'unprobed',
     *     command: non-empty-string|null,
     *     path: non-empty-string|null
     * }
     */
    public function probe(string $canonicalAgent): array
    {
        if (!array_key_exists($canonicalAgent, self::COMMANDS)) {
            throw new InvalidArgumentException('Unknown canonical agent: ' . $canonicalAgent);
        }

        $command = self::COMMANDS[$canonicalAgent];
        if ($command === null) {
            return [
                'status' => 'unprobed',
                'command' => null,
                'path' => null,
            ];
        }

        $resolved = $this->resolveExecutable($command);

        return [
            'status' => $resolved === null ? 'missing' : 'available',
            'command' => $command,
            'path' => $resolved,
        ];
    }

    private function resolveExecutable(string $command): ?string
    {
        $path = $this->path ?? getenv('PATH');
        if (!is_string($path) || $path === '') {
            return null;
        }

        $extensions = [''];
        if (DIRECTORY_SEPARATOR === '\\') {
            $pathExt = $this->pathExt ?? getenv('PATHEXT');
            $extensions = is_string($pathExt) && $pathExt !== ''
                ? array_values(array_filter(explode(';', $pathExt), static fn (string $value): bool => $value !== ''))
                : ['.COM', '.EXE', '.BAT', '.CMD'];
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory === '') {
                continue;
            }

            foreach ($extensions as $extension) {
                $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $command . $extension;
                if (!is_file($candidate)) {
                    continue;
                }

                if (DIRECTORY_SEPARATOR === '\\' || is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
