<?php

declare(strict_types=1);

namespace voku\AgentLoop\Dogfood;

use JsonException;
use RuntimeException;

/**
 * Derives the version a candidate dogfood mounts from the declared minimum.
 *
 * A candidate dogfood installs a first-party package from a path repository, so
 * it has to name a version for it. That version was written out by hand as
 * `0.11.999` - just above the declared minimum, chosen to satisfy `^0.11.x` and
 * to beat anything on Packagist. Raising the requirement to `^0.12.0` left the
 * sentinel behind, and because a path repository is canonical, Composer refused
 * the whole set rather than falling back:
 *
 *   voku/agent-recall-compiler[0.11.999] from path repo has higher repository
 *   priority. The packages from the higher priority repository do not match
 *   your constraint and are therefore not installable.
 *
 * The number was never independent of the constraint, so it is computed from it
 * here instead of being repeated in two runners and a workflow pin.
 */
final readonly class MinimumReleasePin
{
    /** The release a caret constraint declares as its minimum: `^0.12.0` -> `0.12.0`. */
    public static function minimumRelease(string $constraint): string
    {
        if (preg_match('/^\^(\d+\.\d+\.\d+)$/', trim($constraint), $matches) !== 1) {
            throw new RuntimeException('Expected a caret constraint such as ^0.12.0, got: ' . $constraint);
        }

        return $matches[1];
    }

    /**
     * The version a path repository mounts a candidate under.
     *
     * `.999` as the patch keeps the candidate ahead of every published patch of
     * the same minor, so the checkout under test wins over anything cached.
     */
    public static function pathRepositoryVersion(string $constraint): string
    {
        $release = self::minimumRelease($constraint);

        return substr($release, 0, (int) strrpos($release, '.')) . '.999';
    }

    /** Reads the declared constraint for one required package. */
    public static function declaredConstraint(string $composerJsonPath, string $package): string
    {
        $raw = file_get_contents($composerJsonPath);
        if ($raw === false) {
            throw new RuntimeException('Cannot read ' . $composerJsonPath);
        }

        try {
            $composer = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Malformed ' . $composerJsonPath . ': ' . $exception->getMessage(), previous: $exception);
        }

        $constraint = is_array($composer) && is_array($composer['require'] ?? null)
            ? ($composer['require'][$package] ?? null)
            : null;
        if (!is_string($constraint)) {
            throw new RuntimeException($package . ' is not a declared requirement of ' . $composerJsonPath);
        }

        return $constraint;
    }
}
