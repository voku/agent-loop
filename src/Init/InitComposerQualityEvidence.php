<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

/**
 * Composer scripts are project-owned evidence, never setup authority.
 *
 * The caller must inspect script bodies and execution permissions before using
 * any reported name as a validation command.
 */
final readonly class InitComposerQualityEvidence
{
    /**
     * @param array<string, mixed> $composer
     *
     * @return list<string>
     */
    public function scriptNames(array $composer): array
    {
        $scripts = $composer['scripts'] ?? null;
        if (!is_array($scripts)) {
            return [];
        }

        $names = [];
        foreach ($scripts as $name => $command) {
            if (!is_string($name) || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,63}\z/', $name) !== 1) {
                continue;
            }

            if (!preg_match('/\A(?:ci|qa|check|lint|validate|phpstan|analyse|analyze|static-analysis|cs|test|tests)(?:[:.-][a-zA-Z0-9_.-]+)*\z/i', $name)) {
                continue;
            }

            if (!$this->isValidScriptDeclaration($command)) {
                continue;
            }

            $names[] = $name;
        }

        sort($names, SORT_STRING);

        return $names;
    }

    private function isValidScriptDeclaration(mixed $command): bool
    {
        if (is_string($command)) {
            return trim($command) !== '';
        }

        if (!is_array($command) || $command === [] || !array_is_list($command)) {
            return false;
        }

        foreach ($command as $step) {
            if (!is_string($step) || trim($step) === '') {
                return false;
            }
        }

        return true;
    }
}
