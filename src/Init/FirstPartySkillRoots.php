<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use ReflectionClass;
use RuntimeException;
use voku\AgentRecallCompiler\Cli as RecallCli;

/**
 * The skill sources `init install-assets` projects into a host.
 *
 * agent-loop ships its own skills and re-exports the Recall consumer skills from
 * `agent-recall-compiler`, so a projection legitimately contains entries that no
 * directory in *this* repository owns. Anything that reasons about the projected
 * set has to know both roots: when only the repository root was consulted, the
 * two Recall skills were reported as stale managed entries immediately after a
 * successful install.
 */
final readonly class FirstPartySkillRoots
{
    /**
     * @return list<string>
     * @throws RuntimeException when the installed Recall package cannot be located
     */
    public static function resolve(string $packageRoot): array
    {
        $recallFile = (new ReflectionClass(RecallCli::class))->getFileName();
        if (!is_string($recallFile)) {
            throw new RuntimeException('Unable to resolve the installed agent-recall-compiler package path.');
        }

        return [
            $packageRoot . '/docs/agents/skills',
            dirname($recallFile, 2) . '/skills',
        ];
    }

    /**
     * Skill directory names the Recall dependency contributes, or an empty list
     * when it is not installed - a read-only report must not fail over that.
     *
     * @return list<string>
     */
    public static function recallSkillEntries(): array
    {
        try {
            $roots = self::resolve('');
        } catch (RuntimeException) {
            return [];
        }

        $recallRoot = $roots[1];
        if (!is_dir($recallRoot)) {
            return [];
        }

        $entries = [];
        foreach ((array) scandir($recallRoot) as $entry) {
            if (!is_string($entry) || str_starts_with($entry, '.')) {
                continue;
            }

            if (is_file($recallRoot . '/' . $entry . '/SKILL.md')) {
                $entries[] = $entry;
            }
        }

        sort($entries);

        return $entries;
    }
}
