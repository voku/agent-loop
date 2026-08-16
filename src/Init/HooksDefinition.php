<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use ParseError;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Client-agnostic reader and validator for a repository-local hook bundle.
 *
 * A bundle is one `hooks.json` plus every package-owned file under `hooks/`.
 * Clients differ only in the directory their commands must point at and in the
 * events they are required to declare. Registered scripts are entrypoints, not
 * the complete runtime dependency bundle.
 */
final readonly class HooksDefinition
{
    /**
     * @param list<string> $scriptNames
     */
    private function __construct(
        private string $hooksJsonContent,
        private array $scriptNames,
    ) {
    }

    /**
     * @param string       $clientDirectory client-local hook directory the commands must call, for example `.codex`
     * @param list<string> $requiredEvents  events the bundle must declare; empty means any non-empty event set is valid
     */
    public static function fromRoot(string $hooksRoot, string $clientDirectory, array $requiredEvents = []): self
    {
        $hooksJsonPath = rtrim($hooksRoot, '/') . '/hooks.json';
        $content = file_get_contents($hooksJsonPath);
        if (!is_string($content)) {
            throw new InvalidArgumentException('hooks.json is not readable');
        }

        $validation = self::validationErrors($hooksRoot, $clientDirectory, $requiredEvents);
        if ($validation !== []) {
            throw new InvalidArgumentException($validation[0]);
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('hooks.json is not valid JSON');
        }

        return new self($content, self::bundleFileNames($hooksRoot));
    }

    /**
     * Every package-owned file installed under the client's `hooks/` directory.
     * Registered event entrypoints are a subset of this complete runtime bundle.
     *
     * @return list<string>
     */
    public function scriptNames(): array
    {
        return $this->scriptNames;
    }

    /**
     * Returns every regular file owned by the canonical `hooks/` bundle,
     * relative to that directory and sorted deterministically.
     *
     * @return list<string>
     */
    public static function bundleFileNames(string $hooksRoot): array
    {
        $root = rtrim($hooksRoot, '/') . '/hooks';
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $path = str_replace('\\', '/', $item->getPathname());
            if (!str_starts_with($path, $normalizedRoot)) {
                continue;
            }

            $relative = substr($path, strlen($normalizedRoot));
            if ($relative !== '') {
                $files[$relative] = $relative;
            }
        }

        ksort($files);

        return array_values($files);
    }

    public function hooksJsonContent(): string
    {
        return $this->hooksJsonContent;
    }

    /**
     * @return array<string, mixed> the decoded `hooks` object, for clients that register hooks inside another file
     */
    public function hooksObject(): array
    {
        $decoded = json_decode($this->hooksJsonContent, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('hooks.json is not valid JSON');
        }

        $hooks = $decoded['hooks'] ?? null;
        if (!is_array($hooks)) {
            throw new InvalidArgumentException('hooks.json must contain a non-empty hooks object');
        }

        /** @var array<string, mixed> $hooks */
        return $hooks;
    }

    /**
     * @param list<string> $requiredEvents
     * @return list<string>
     */
    public static function validationErrors(string $hooksRoot, string $clientDirectory, array $requiredEvents = []): array
    {
        $hooksJsonPath = rtrim($hooksRoot, '/') . '/hooks.json';
        $hookScriptsRoot = rtrim($hooksRoot, '/') . '/hooks';
        if (!is_file($hooksJsonPath) && !is_dir($hookScriptsRoot)) {
            return [];
        }

        if (!is_file($hooksJsonPath)) {
            return ['hooks.json not found'];
        }
        if (!is_readable($hooksJsonPath)) {
            return ['hooks.json is not readable'];
        }

        $content = file_get_contents($hooksJsonPath);
        if (!is_string($content) || trim($content) === '') {
            return ['hooks.json is empty'];
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return ['hooks.json is not valid JSON'];
        }

        $hooks = $decoded['hooks'] ?? null;
        if (!is_array($hooks) || $hooks === []) {
            return ['hooks.json must contain a non-empty hooks object'];
        }

        $errors = [];
        foreach ($requiredEvents as $requiredEvent) {
            if (!array_key_exists($requiredEvent, $hooks)) {
                $errors[] = 'hooks.json misses required event ' . $requiredEvent;
            }
        }
        if ($errors !== []) {
            return $errors;
        }

        $scriptNames = [];
        foreach ($hooks as $eventName => $eventGroups) {
            if (!is_string($eventName) || !is_array($eventGroups) || $eventGroups === []) {
                $errors[] = 'Hook event must define a non-empty hook group list';

                continue;
            }

            foreach ($eventGroups as $eventGroup) {
                if (!is_array($eventGroup)) {
                    $errors[] = $eventName . ' contains a non-object hook group';

                    continue;
                }

                $hookEntries = $eventGroup['hooks'] ?? null;
                if (!is_array($hookEntries) || $hookEntries === []) {
                    $errors[] = $eventName . ' hook group misses hook entries';

                    continue;
                }

                foreach ($hookEntries as $hookEntry) {
                    if (!is_array($hookEntry)) {
                        $errors[] = $eventName . ' contains a non-object hook entry';

                        continue;
                    }
                    if (($hookEntry['type'] ?? null) !== 'command') {
                        $errors[] = $eventName . ' contains unsupported hook type';

                        continue;
                    }

                    $command = $hookEntry['command'] ?? null;
                    $scriptName = is_string($command) ? self::scriptNameFromCommand($command, $clientDirectory) : null;
                    if ($scriptName === null) {
                        $errors[] = $eventName . ' hook command must call one repository-local ' . $clientDirectory . '/hooks PHP script';

                        continue;
                    }

                    $scriptNames[$scriptName] = $scriptName;
                }
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        foreach (array_values($scriptNames) as $scriptName) {
            $scriptPath = $hookScriptsRoot . '/' . $scriptName;
            if (!is_file($scriptPath)) {
                $errors[] = 'Referenced hook script is missing: hooks/' . $scriptName;
            }
        }

        foreach (self::bundleFileNames($hooksRoot) as $bundleFile) {
            $path = $hookScriptsRoot . '/' . $bundleFile;
            if (!is_readable($path)) {
                $errors[] = 'Hook bundle file is not readable: hooks/' . $bundleFile;

                continue;
            }

            $bundleContent = file_get_contents($path);
            if (!is_string($bundleContent) || trim($bundleContent) === '') {
                $errors[] = 'Hook bundle file is empty: hooks/' . $bundleFile;

                continue;
            }

            if (!str_ends_with(strtolower($bundleFile), '.php')) {
                continue;
            }

            try {
                if (token_get_all($bundleContent, TOKEN_PARSE) === []) {
                    $errors[] = 'Hook PHP syntax error in hooks/' . $bundleFile . ': parser returned no tokens';
                }
            } catch (ParseError $exception) {
                $errors[] = 'Hook PHP syntax error in hooks/' . $bundleFile . ': ' . $exception->getMessage();
            }
        }

        return $errors;
    }

    private static function scriptNameFromCommand(string $command, string $clientDirectory): ?string
    {
        $directory = preg_quote(trim($clientDirectory, '/'), '~');
        $suffix = '(?:\s+--event=(?:SessionStart|SubagentStart))?';

        $relative = '~\Aphp\s+["\']?' . $directory . '/hooks/([A-Za-z0-9_.-]+\.php)["\']?' . $suffix . '\z~';
        if (preg_match($relative, trim($command), $matches) === 1) {
            return $matches[1];
        }

        $gitRoot = '~\Aphp\s+["\']?\$\(git rev-parse --show-toplevel\)/' . $directory . '/hooks/([A-Za-z0-9_.-]+\.php)["\']?' . $suffix . '\z~';
        if (preg_match($gitRoot, trim($command), $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
