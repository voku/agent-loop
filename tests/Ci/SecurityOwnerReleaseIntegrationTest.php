<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests\Ci;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\VersionParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The root lock is Loop's installed-consumer graph; keep its security owners on
 * the first fixed releases so a green Loop suite cannot silently test old tags.
 */
final class SecurityOwnerReleaseIntegrationTest extends TestCase
{
    /** @var array<string, array{fixed: string, previous: string}> */
    private const SECURITY_RELEASES = [
        'voku/agent-edit' => ['fixed' => '0.6.1', 'previous' => '0.6.0'],
        'voku/agent-kanban' => ['fixed' => '0.4.6', 'previous' => '0.4.5'],
        'voku/agent-learning' => ['fixed' => '0.18.36', 'previous' => '0.18.35'],
        'voku/agent-map' => ['fixed' => '0.22.3', 'previous' => '0.22.2'],
        'voku/agent-recall-compiler' => ['fixed' => '0.25.9', 'previous' => '0.25.8'],
        'voku/agent-session' => ['fixed' => '0.7.2', 'previous' => '0.7.1'],
    ];

    public function testDeclaredAndLockedOwnerGraphIncludesSecurityFixReleases(): void
    {
        $root = dirname(__DIR__, 2);
        $composer = $this->readJson($root . '/composer.json');
        $lock = $this->readJson($root . '/composer.lock');
        $requirements = $composer['require'] ?? null;
        self::assertIsArray($requirements);

        $lockedVersions = [];
        foreach ($lock['packages'] ?? [] as $package) {
            if (is_array($package) && is_string($package['name'] ?? null) && is_string($package['version'] ?? null)) {
                $lockedVersions[$package['name']] = ltrim($package['version'], 'v');
            }
        }

        foreach (self::SECURITY_RELEASES as $package => $release) {
            $constraint = $requirements[$package] ?? null;
            self::assertIsString($constraint, sprintf('Loop must declare a direct requirement for %s.', $package));
            self::assertFalse(
                $this->constraintSatisfies($release['previous'], $constraint),
                sprintf('%s must not permit the pre-fix release %s.', $package, $release['previous']),
            );

            $locked = $lockedVersions[$package] ?? null;
            self::assertIsString($locked, sprintf('composer.lock must include %s.', $package));
            self::assertTrue(
                $this->constraintSatisfies($locked, $constraint),
                sprintf('%s locked at %s must satisfy its declared constraint %s.', $package, $locked, $constraint),
            );
            self::assertGreaterThanOrEqual(
                0,
                version_compare($locked, $release['fixed']),
                sprintf('composer.lock pins %s at %s, before fixed release %s.', $package, $locked, $release['fixed']),
            );
        }
    }

    private function constraintSatisfies(string $version, string $constraint): bool
    {
        $parser = new VersionParser();
        $provider = new Constraint('==', $parser->normalize($version));

        return $parser->parseConstraints($constraint)->matches($provider);
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read dependency manifest: ' . $path);
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Dependency manifest is not a JSON object: ' . $path);
        }

        return $decoded;
    }
}
