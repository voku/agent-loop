<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use stdClass;
use voku\AgentMap\IO\SourceLanguage;

/**
 * The `map` section of the init config: which source languages the project declared for its map.
 *
 * The allowed values and their meaning are agent-map's (`SourceLanguage`); nothing is listed
 * here. An invalid declaration is recorded in `languages_error`: every path that builds or
 * requires a map reads the setting through `ProjectLayout::mapLanguages()` and refuses, so a typo
 * can never silently index less than the project asked for.
 *
 * The config loader also runs inside dependency-free host hooks, which stage `src/` without
 * `vendor/`. There the owner cannot be asked; the declared names are passed on unjudged and
 * `ProjectLayout::mapLanguages()`, which always asks the owner, stays the authority.
 */
final readonly class InitMapSettings
{
    /**
     * @param mixed $shape the decoded `map` value, objects kept as objects
     * @param list<string> $warnings
     *
     * @return array{languages: list<string>|null, languages_error: string|null} `languages` is
     *                                                                            null when none
     *                                                                            were declared
     */
    public function read(mixed $shape, array &$warnings): array
    {
        $languages = [];
        $error = null;
        if (!$shape instanceof stdClass) {
            $error = 'map must be an object';
        } elseif (!property_exists($shape, 'languages')) {
            return ['languages' => null, 'languages_error' => null];
        } elseif (!is_array($shape->languages) || $shape->languages === []) {
            $error = 'map.languages must be a non-empty array of language names';
        } else {
            foreach ($shape->languages as $language) {
                if (!is_string($language)) {
                    $error = 'map.languages must contain only strings';

                    break;
                }
                $languages[] = $language;
            }
        }

        if ($error === null && class_exists(SourceLanguage::class)) {
            try {
                $languages = SourceLanguage::normalize($languages);
            } catch (InvalidArgumentException $exception) {
                $error = 'map.languages is invalid. ' . $exception->getMessage();
            }
        }

        if ($error === null) {
            return ['languages' => $languages, 'languages_error' => null];
        }
        $warnings[] = '[WARN] init config: ' . $error;

        return ['languages' => null, 'languages_error' => $error];
    }
}
