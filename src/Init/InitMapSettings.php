<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use stdClass;
use voku\AgentMap\IO\SourceLanguage;

/**
 * The `map` section of init config: the source languages and project-relative map scope.
 *
 * The allowed values and their meaning are agent-map's (`SourceLanguage`); nothing is listed
 * here. An invalid language declaration or path scope is recorded as an error and refused by the
 * ProjectLayout accessors, so a typo cannot silently index less than the project asked for.
 *
 * The config loader also runs inside dependency-free host hooks, which stage `src/` without
 * `vendor/`. There the owner cannot be asked; the declared names are passed on unjudged and
 * `ProjectLayout::mapLanguages()`, which always asks the owner, stays the authority.
 */
final readonly class InitMapSettings
{
    public function __construct(private string $rootPath)
    {
    }

    /**
     * @param mixed $shape the decoded `map` value, objects kept as objects
     * @param list<string> $warnings
     *
     * @return array{
     *   languages: list<string>|null,
     *   languages_error: string|null,
     *   paths: list<string>|null,
     *   paths_error: string|null
     * }
     */
    public function read(mixed $shape, array &$warnings): array
    {
        $paths = null;
        $pathsError = null;
        $languages = [];
        $languagesError = null;
        if (!$shape instanceof stdClass) {
            $languagesError = 'map must be an object';
            $pathsError = 'map must be an object';
        } else {
            if (property_exists($shape, 'languages')) {
                if (!is_array($shape->languages) || $shape->languages === []) {
                    $languagesError = 'map.languages must be a non-empty array of language names';
                } else {
                    foreach ($shape->languages as $language) {
                        if (!is_string($language)) {
                            $languagesError = 'map.languages must contain only strings';

                            break;
                        }
                        $languages[] = $language;
                    }
                }
            }

            if (property_exists($shape, 'paths')) {
                [$paths, $pathsError] = $this->paths($shape->paths);
            }
        }

        if ($languagesError === null && $languages !== [] && class_exists(SourceLanguage::class)) {
            try {
                $languages = SourceLanguage::normalize($languages);
            } catch (InvalidArgumentException $exception) {
                $languagesError = 'map.languages is invalid. ' . $exception->getMessage();
            }
        }

        if ($languagesError !== null) {
            $warnings[] = '[WARN] init config: ' . $languagesError;
        }
        if ($pathsError !== null) {
            $warnings[] = '[WARN] init config: ' . $pathsError;
        }

        return [
            'languages' => $languagesError === null && $languages !== [] ? $languages : null,
            'languages_error' => $languagesError,
            'paths' => $pathsError === null ? $paths : null,
            'paths_error' => $pathsError,
        ];
    }

    /** @return array{list<string>|null, string|null} */
    private function paths(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            return [null, 'map.paths must be a non-empty array of project-relative directories'];
        }

        $root = realpath($this->rootPath);
        if ($root === false) {
            return [null, 'map.paths cannot be validated because the project root cannot be resolved'];
        }

        $paths = [];
        foreach ($value as $path) {
            if (!is_string($path)
                || ($path !== '.' && preg_match('#\A[A-Za-z0-9_.][A-Za-z0-9._/-]*\z#', $path) !== 1)
                || ($path !== '.' && in_array('.', explode('/', rtrim($path, '/')), true))
                || in_array('..', explode('/', $path), true)) {
                return [null, 'map.paths entries must be plain project-relative directories'];
            }

            $resolved = realpath(rtrim($this->rootPath, '/\\') . '/' . $path);
            $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
            $normalizedResolved = $resolved === false ? null : rtrim(str_replace('\\', '/', $resolved), '/');
            $prefix = ($normalizedRoot === '' ? '/' : $normalizedRoot . '/');
            $withinRoot = $normalizedResolved !== null && (
                (PHP_OS_FAMILY === 'Windows'
                    ? strcasecmp($normalizedResolved, $normalizedRoot) === 0
                    : $normalizedResolved === $normalizedRoot)
                || (PHP_OS_FAMILY === 'Windows'
                    ? strncasecmp($normalizedResolved, $prefix, strlen($prefix)) === 0
                    : str_starts_with($normalizedResolved, $prefix))
            );
            if ($resolved === false || !is_dir($resolved) || !$withinRoot) {
                return [null, 'map.paths entry ' . json_encode($path) . ' is not an existing project-relative directory'];
            }

            $paths[] = $path === '.' ? '.' : rtrim($path, '/');
        }

        return [array_values(array_unique($paths)), null];
    }
}
