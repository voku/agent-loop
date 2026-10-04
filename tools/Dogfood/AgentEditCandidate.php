<?php

declare(strict_types=1);

namespace voku\AgentLoop\Dogfood;

use RuntimeException;

/**
 * agent-edit has no stable release yet, so agent-loop pins one exact candidate commit as a `package` repository in
 * its own composer.json. Installed-consumer harnesses mount that same entry instead of repeating the pin.
 */
final class AgentEditCandidate
{
    /**
     * @param array<string, mixed> $composer decoded agent-loop composer.json
     * @return array<string, mixed> the `package` repository entry for voku/agent-edit
     */
    public static function repository(array $composer): array
    {
        $repositories = $composer['repositories'] ?? null;
        if (is_array($repositories)) {
            foreach ($repositories as $repository) {
                if (
                    is_array($repository)
                    && ($repository['type'] ?? null) === 'package'
                    && is_array($repository['package'] ?? null)
                    && ($repository['package']['name'] ?? null) === 'voku/agent-edit'
                ) {
                    return $repository;
                }
            }
        }

        throw new RuntimeException('agent-loop composer.json does not pin a voku/agent-edit candidate package repository.');
    }

    /** @return array<string, mixed> */
    public static function repositoryFromFile(string $composerJson): array
    {
        $raw = file_get_contents($composerJson);
        if (!is_string($raw)) {
            throw new RuntimeException('Unable to read ' . $composerJson);
        }
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException($composerJson . ' must decode to an object.');
        }

        return self::repository($decoded);
    }
}
