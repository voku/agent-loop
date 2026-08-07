<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;

/**
 * Client-agnostic reader and validator for a repository-local hook bundle.
 *
 * A bundle is one `hooks.json` plus the PHP scripts it references under `hooks/`.
 * Clients differ only in the directory their commands must point at and in the
 * events they are required to declare.
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

        return new self($content, self::referencedScriptNames($decoded, $clientDirectory));
    }

    /** @return list<string> */
    public function scriptNames(): array
    {
        return $this->scriptNames;
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

                continue;
            }
            if (!is_readable($scriptPath)) {
                $errors[] = 'Referenced hook script is not readable: hooks/' . $scriptName;

                continue;
            }

            $scriptContent = file_get_contents($scriptPath);
            if (!is_string($scriptContent) || trim($scriptContent) === '') {
                $errors[] = 'Referenced hook script is empty: hooks/' . $scriptName;
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $decoded
     * @return list<string>
     */
    private static function referencedScriptNames(array $decoded, string $clientDirectory): array
    {
        $scriptNames = [];
        $hooks = $decoded['hooks'] ?? [];
        if (!is_array($hooks)) {
            return [];
        }

        foreach ($hooks as $eventGroups) {
            if (!is_array($eventGroups)) {
                continue;
            }
            foreach ($eventGroups as $eventGroup) {
                if (!is_array($eventGroup)) {
                    continue;
                }

                $hookEntries = $eventGroup['hooks'] ?? null;
                if (!is_array($hookEntries)) {
                    continue;
                }
                foreach ($hookEntries as $hookEntry) {
                    if (!is_array($hookEntry) || !is_string($hookEntry['command'] ?? null)) {
                        continue;
                    }

                    $scriptName = self::scriptNameFromCommand($hookEntry['command'], $clientDirectory);
                    if ($scriptName !== null) {
                        $scriptNames[$scriptName] = $scriptName;
                    }
                }
            }
        }

        ksort($scriptNames);

        return array_values($scriptNames);
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
