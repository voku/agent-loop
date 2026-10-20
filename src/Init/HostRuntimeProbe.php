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
        if ($executablePath === null) {
            return ['status' => 'unavailable', 'version' => null];
        }
        if (!function_exists('proc_open')) {
            return ['status' => 'unavailable', 'version' => null];
        }

        $pipes = [];
        $process = @proc_open(
            [$executablePath, '--version'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            return ['status' => 'unavailable', 'version' => null];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $errorOutput = '';
        $deadline = hrtime(true) + (int) (self::VERSION_PROBE_TIMEOUT_SECONDS * 1_000_000_000);
        $processExitCode = null;

        do {
            $output .= self::readAvailableOutput($pipes[1]);
            $errorOutput .= self::readAvailableOutput($pipes[2]);
            if (strlen($output) + strlen($errorOutput) > self::VERSION_PROBE_MAX_OUTPUT_BYTES) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return ['status' => 'unavailable', 'version' => null];
            }

            $processStatus = proc_get_status($process);
            if (!$processStatus['running']) {
                $processExitCode = $processStatus['exitcode'];
                break;
            }
            if (hrtime(true) >= $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return ['status' => 'unavailable', 'version' => null];
            }

            usleep(10_000);
        } while (true);

        $output .= self::readAvailableOutput($pipes[1]);
        $errorOutput .= self::readAvailableOutput($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closeExitCode = proc_close($process);
        if (strlen($output) + strlen($errorOutput) > self::VERSION_PROBE_MAX_OUTPUT_BYTES
            || ($closeExitCode !== 0 && $processExitCode !== 0)
        ) {
            return ['status' => 'unavailable', 'version' => null];
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

    /** Read currently available bytes from a non-blocking process pipe. */
    private static function readAvailableOutput(mixed $pipe): string
    {
        // One bounded read per poll: a continuously writing child must not keep
        // us inside a drain loop past either the byte or monotonic time budget.
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
