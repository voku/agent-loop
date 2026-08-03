<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

use InvalidArgumentException;

final readonly class EditRequestParser
{
    /** @param list<string> $tokens */
    public function parse(string $projectRoot, array $tokens): EditRequest
    {
        $realProjectRoot = realpath($projectRoot);
        if (!is_string($realProjectRoot) || !is_dir($realProjectRoot)) {
            throw new InvalidArgumentException('Project root not found: ' . $projectRoot);
        }
        $realProjectRoot = $this->normalizePath($realProjectRoot);

        $separator = array_search('--', $tokens, true);
        if (!is_int($separator)) {
            throw new InvalidArgumentException('edit requires `--` before the implementation instruction.');
        }

        $requestTokens = array_slice($tokens, 0, $separator);
        $instruction = trim(implode(' ', array_slice($tokens, $separator + 1)));
        if ($instruction === '') {
            throw new InvalidArgumentException('edit requires a non-empty implementation instruction after `--`.');
        }

        /** @var array<string, string> $values */
        $values = [];
        /** @var array{focus: list<string>, map-exclude: list<string>, runner-arg: list<string>} $repeated */
        $repeated = [
            'focus' => [],
            'map-exclude' => [],
            'runner-arg' => [],
        ];
        /** @var array{rebuild-map: bool, no-rebuild-map: bool, dry-run: bool, print-prompt: bool} $flags */
        $flags = [
            'rebuild-map' => false,
            'no-rebuild-map' => false,
            'dry-run' => false,
            'print-prompt' => false,
        ];
        $target = null;

        for ($index = 0, $count = count($requestTokens); $index < $count; ++$index) {
            $token = $requestTokens[$index];
            if (!str_starts_with($token, '--')) {
                if ($target !== null) {
                    throw new InvalidArgumentException('Unexpected edit argument: ' . $token);
                }
                $target = trim($token);
                continue;
            }

            $raw = substr($token, 2);
            if (array_key_exists($raw, $flags)) {
                $flags[$raw] = true;
                continue;
            }

            [$name, $value, $consumedNext] = $this->readOption($token, $requestTokens, $index);
            if ($consumedNext) {
                ++$index;
            }
            if (array_key_exists($name, $repeated)) {
                $repeated[$name][] = $value;
                continue;
            }
            if (!in_array($name, [
                'task',
                'recall-root',
                'map-index',
                'map-root',
                'map-paths',
                'phpstan-config',
                'phpstan-memory-limit',
                'output-dir',
                'runner',
                'runner-command',
                'runner-timeout',
                'replace-old',
                'replace-new',
            ], true)) {
                throw new InvalidArgumentException('Unknown edit option: --' . $name);
            }
            if (array_key_exists($name, $values)) {
                throw new InvalidArgumentException('Edit option may only be supplied once: --' . $name);
            }
            $values[$name] = $value;
        }

        if ($target === null || $target === '') {
            throw new InvalidArgumentException('edit requires an exact Class::method target.');
        }
        if (!str_contains($target, '::')) {
            throw new InvalidArgumentException('Edit target must use Class::method syntax: ' . $target);
        }
        if ($flags['rebuild-map'] && $flags['no-rebuild-map']) {
            throw new InvalidArgumentException('--rebuild-map and --no-rebuild-map are mutually exclusive.');
        }

        $taskId = trim($values['task'] ?? '');
        if ($taskId === '') {
            $taskId = $this->generatedTaskId($target, $instruction);
        }
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $taskId) !== 1 || str_contains($taskId, '..')) {
            throw new InvalidArgumentException('Invalid edit task ID: ' . $taskId);
        }

        $mapRoot = $this->resolveExistingDirectory($realProjectRoot, $values['map-root'] ?? $realProjectRoot, 'map root');
        $recallRoot = isset($values['recall-root'])
            ? $this->resolveExistingDirectory($realProjectRoot, $values['recall-root'], 'recall root')
            : $this->discoverRecallRoot($realProjectRoot);
        $mapIndex = $this->resolvePath($realProjectRoot, $values['map-index'] ?? '.agent-map/php-symbols.json');
        $outputDirectory = $this->resolvePath($realProjectRoot, $values['output-dir'] ?? '.agent-loop/edit/' . $taskId);
        $mapPaths = $this->splitList($values['map-paths'] ?? '.');
        $phpStanConfiguration = isset($values['phpstan-config']) && trim($values['phpstan-config']) !== ''
            ? trim($values['phpstan-config'])
            : null;
        $phpStanMemoryLimit = isset($values['phpstan-memory-limit'])
            ? $this->memoryLimit($values['phpstan-memory-limit'])
            : null;
        $runner = trim($values['runner'] ?? 'stdout');
        $runnerCommand = isset($values['runner-command']) ? trim($values['runner-command']) : null;
        $timeout = $this->positiveInt('runner-timeout', $values['runner-timeout'] ?? '900');

        return new EditRequest(
            taskId: $taskId,
            target: ltrim($target, '\\'),
            instruction: $instruction,
            projectRoot: $realProjectRoot,
            recallRoot: $recallRoot,
            mapIndex: $mapIndex,
            mapRoot: $mapRoot,
            outputDirectory: $outputDirectory,
            mapPaths: $mapPaths,
            mapExcludes: array_values(array_filter(array_map('trim', $repeated['map-exclude']), static fn (string $value): bool => $value !== '')),
            focusTerms: array_values(array_filter(array_map('trim', $repeated['focus']), static fn (string $value): bool => $value !== '')),
            phpStanConfiguration: $phpStanConfiguration,
            phpStanMemoryLimit: $phpStanMemoryLimit,
            forceMapRebuild: $flags['rebuild-map'],
            allowMapRebuild: !$flags['no-rebuild-map'],
            dryRun: $flags['dry-run'],
            runner: $runner,
            runnerCommand: $runnerCommand,
            runnerArguments: $repeated['runner-arg'],
            runnerTimeoutSeconds: $timeout,
            printPrompt: $flags['print-prompt'],
            replacementOld: $values['replace-old'] ?? null,
            replacementNew: $values['replace-new'] ?? null,
        );
    }

    /**
     * @param list<string> $tokens
     * @return array{0: string, 1: string, 2: bool}
     */
    private function readOption(string $token, array $tokens, int $index): array
    {
        $raw = substr($token, 2);
        if ($raw === '') {
            throw new InvalidArgumentException('Invalid empty edit option.');
        }
        if (str_contains($raw, '=')) {
            [$name, $value] = explode('=', $raw, 2);
            if ($value === '') {
                throw new InvalidArgumentException('Missing value for edit option: --' . $name);
            }

            return [$name, $value, false];
        }

        $value = $tokens[$index + 1] ?? null;
        if (!is_string($value) || str_starts_with($value, '--')) {
            throw new InvalidArgumentException('Missing value for edit option: --' . $raw);
        }

        return [$raw, $value, true];
    }

    private function generatedTaskId(string $target, string $instruction): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $target));
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'target';
        }

        return 'edit-' . substr($slug, 0, 48) . '-' . substr(hash('sha256', $target . "\0" . $instruction), 0, 12);
    }

    private function resolveExistingDirectory(string $projectRoot, string $path, string $label): string
    {
        $resolved = $this->resolvePath($projectRoot, $path);
        $real = realpath($resolved);
        if (!is_string($real) || !is_dir($real)) {
            throw new InvalidArgumentException('Edit ' . $label . ' not found: ' . $path);
        }

        return $this->normalizePath($real);
    }

    private function resolvePath(string $projectRoot, string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new InvalidArgumentException('Edit path option must not be empty.');
        }
        if ($this->isAbsolutePath($path)) {
            return $this->trimTrailingSeparator($this->normalizePath($path));
        }

        return $this->trimTrailingSeparator($projectRoot . '/' . ltrim($this->normalizePath($path), '/'));
    }

    private function discoverRecallRoot(string $projectRoot): string
    {
        foreach (['infra/doc/agent-learning', '.agent-learning', 'docs/agent-learning', 'agent-learning'] as $candidate) {
            if (is_dir($projectRoot . '/' . $candidate)) {
                return $projectRoot . '/' . $candidate;
            }
        }

        return $projectRoot;
    }

    /** @return non-empty-list<string> */
    private function splitList(string $value): array
    {
        $items = [];
        foreach (explode(',', $value) as $item) {
            $item = trim($item);
            if ($item !== '') {
                $items[] = $item;
            }
        }

        return $items === [] ? ['.'] : array_values(array_unique($items));
    }

    private function positiveInt(string $name, string $value): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 86400]]);
        if (!is_int($integer)) {
            throw new InvalidArgumentException('Invalid ' . $name . ': ' . $value);
        }

        return $integer;
    }

    private function memoryLimit(string $value): string
    {
        if (preg_match('/\A[1-9][0-9]*(?:[KMG])?\z/i', $value) !== 1) {
            throw new InvalidArgumentException('Invalid phpstan-memory-limit: ' . $value);
        }

        return strtoupper($value);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function trimTrailingSeparator(string $path): string
    {
        if ($path === '/' || preg_match('~^[A-Za-z]:/$~', $path) === 1) {
            return $path;
        }

        return rtrim($path, '/');
    }
}
