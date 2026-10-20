<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;

final readonly class HostRuntimeProbe
{
    private const float VERSION_PROBE_TIMEOUT_SECONDS = 2.0;

    private const int VERSION_PROBE_MAX_OUTPUT_BYTES = 8192;

    /**
     * @var array<string, non-empty-string|null>
     */
    private const array COMMANDS = [
        'codex' => 'codex',
        'claude' => 'claude',
        'opencode' => 'opencode',
        'copilot' => 'copilot',
        'gemini' => 'gemini',
        'antigravity' => null,
        'cursor' => null,
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

    /**
     * @return array{status: 'available'|'unavailable'|'unprobed', version: non-empty-string|null}
     */
    public function probeVersion(string $canonicalAgent, ?string $executablePath): array
    {
        if (!array_key_exists($canonicalAgent, self::COMMANDS)) {
            throw new InvalidArgumentException('Unknown canonical agent: ' . $canonicalAgent);
        }

        if (!in_array($canonicalAgent, ['codex', 'claude'], true)) {
            return ['status' => 'unprobed', 'version' => null];
        }
        if ($executablePath === null || !self::isAbsolutePath($executablePath)) {
            return ['status' => 'unavailable', 'version' => null];
        }
        if (!function_exists('proc_open')) {
            return ['status' => 'unavailable', 'version' => null];
        }

        // File descriptors avoid Windows' blocking proc_open pipes. Never read
        // from the child while it is running; only poll sizes and its status.
        $stdout = tmpfile();
        $stderr = tmpfile();
        if ($stdout === false || $stderr === false) {
            if (is_resource($stdout)) {
                fclose($stdout);
            }
            if (is_resource($stderr)) {
                fclose($stderr);
            }

            return ['status' => 'unavailable', 'version' => null];
        }

        $process = null;
        try {
            $pipes = [];
            $process = @proc_open(
                [$executablePath, '--version'],
                [0 => ['pipe', 'r'], 1 => $stdout, 2 => $stderr],
                $pipes,
                null,
                null,
                ['bypass_shell' => true],
            );
            if (!is_resource($process)) {
                return ['status' => 'unavailable', 'version' => null];
            }
            fclose($pipes[0]);
            $deadline = hrtime(true) + (int) (self::VERSION_PROBE_TIMEOUT_SECONDS * 1_000_000_000);

            do {
                $outputStat = fstat($stdout);
                $errorStat = fstat($stderr);
                if ($outputStat === false || $errorStat === false
                    || $outputStat['size'] + $errorStat['size'] > self::VERSION_PROBE_MAX_OUTPUT_BYTES
                ) {
                    return ['status' => 'unavailable', 'version' => null];
                }
                $processStatus = proc_get_status($process);
                if (!$processStatus['running']) {
                    $processExitCode = $processStatus['exitcode'];
                    break;
                }
                if (hrtime(true) >= $deadline) {
                    return ['status' => 'unavailable', 'version' => null];
                }
                usleep(10_000);
            } while (true);

            $closeExitCode = proc_close($process);
            if ($closeExitCode !== 0 && $processExitCode !== 0) {
                return ['status' => 'unavailable', 'version' => null];
            }
            rewind($stdout);
            rewind($stderr);
            $output = self::readAvailableOutput($stdout);
            $errorOutput = self::readAvailableOutput($stderr);
            if (strlen($output) + strlen($errorOutput) > self::VERSION_PROBE_MAX_OUTPUT_BYTES) {
                return ['status' => 'unavailable', 'version' => null];
            }
        } finally {
            if (is_resource($process)) {
                proc_terminate($process, 9);
                proc_close($process);
            }
            fclose($stdout);
            fclose($stderr);
        }

        $version = self::parseVersionOutput($canonicalAgent, $output . "\n" . $errorOutput);

        return $version === null
            ? ['status' => 'unavailable', 'version' => null]
            : ['status' => 'available', 'version' => $version];
    }

    /** @return non-empty-string|null */
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
                $directory = '.';
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

    private static function isAbsolutePath(string $path): bool
    {
        if (DIRECTORY_SEPARATOR === '/') {
            return str_starts_with($path, '/');
        }

        return preg_match('~\\A(?:[A-Za-z]:[\\\\/]|[\\\\/]{2})~', $path) === 1;
    }

    /** Read bounded bytes from the completed process output file. */
    private static function readAvailableOutput(mixed $pipe): string
    {
        $chunk = fread($pipe, self::VERSION_PROBE_MAX_OUTPUT_BYTES + 1);

        return $chunk === false ? '' : $chunk;
    }

    /** @return non-empty-string|null */
    private static function parseVersionOutput(string $canonicalAgent, string $output): ?string
    {
        $pattern = match ($canonicalAgent) {
            'codex' => '/\Acodex(?:-cli)?[ \t]+v?(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?)\z/i',
            'claude' => '/\A(?=[^\r\n]*Claude Code)(?:Claude Code[ \t]+)?v?(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?)(?:[ \t]+\(Claude Code\))?\z/i',
            default => null,
        };
        if ($pattern === null || preg_match($pattern, trim($output, " \t\r\n"), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
