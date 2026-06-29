<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;

final readonly class CodexHooksDefinition
{
    /**
     * @param list<string> $scriptNames
     */
    private function __construct(
        private string $hooksJsonContent,
        private array $scriptNames,
    ) {
    }

    public static function fromRoot(string $hooksRoot): self
    {
        $hooksJsonPath = rtrim($hooksRoot, '/') . '/hooks.json';
        $content = file_get_contents($hooksJsonPath);
        if (!is_string($content)) {
            throw new InvalidArgumentException('hooks.json is not readable');
        }

        $validation = self::validationErrors($hooksRoot);
        if ($validation !== []) {
            throw new InvalidArgumentException($validation[0]);
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('hooks.json is not valid JSON');
        }

        $scriptNames = self::referencedScriptNames($decoded);

        return new self($content, $scriptNames);
    }

    /**
     * @return list<string>
     */
    public function scriptNames(): array
    {
        return $this->scriptNames;
    }

    public function hooksJsonContent(): string
    {
        return $this->hooksJsonContent;
    }

    /**
     * @return list<string>
     */
    public static function validationErrors(string $hooksRoot): array
    {
        $hooksJsonPath = rtrim($hooksRoot, '/') . '/hooks.json';
        $hookScriptsRoot = rtrim($hooksRoot, '/') . '/hooks';
        if (!is_file($hooksJsonPath) && !is_dir($hookScriptsRoot)) {
            return [];
        }

        $errors = [];
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

        foreach (['SessionStart', 'SubagentStart', 'PreToolUse'] as $requiredEvent) {
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
                    if (!is_string($command) || !str_contains($command, '$(git rev-parse --show-toplevel)')) {
                        $errors[] = $eventName . ' hook command must resolve from git root';

                        continue;
                    }

                    if (preg_match('/\/\.codex\/hooks\/([^" ]+\.php)/', $command, $matches) !== 1) {
                        $errors[] = $eventName . ' hook command must call a .codex/hooks PHP script';

                        continue;
                    }

                    $scriptNames[$matches[1]] = $matches[1];
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
    private static function referencedScriptNames(array $decoded): array
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
                    if (!is_array($hookEntry)) {
                        continue;
                    }

                    $command = $hookEntry['command'] ?? null;
                    if (!is_string($command)) {
                        continue;
                    }

                    if (preg_match('/\/\.codex\/hooks\/([^" ]+\.php)/', $command, $matches) === 1) {
                        $scriptNames[$matches[1]] = $matches[1];
                    }
                }
            }
        }

        ksort($scriptNames);

        return array_values($scriptNames);
    }
}
