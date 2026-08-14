<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Dogfood\MinimumReleasePin;

/**
 * The version a candidate dogfood mounts, which used to be written by hand.
 *
 * @internal
 */
final class MinimumReleasePinTest extends TestCase
{
    public function testTheSentinelFollowsTheDeclaredMinimum(): void
    {
        // The defect: the constraint moved to ^0.12.0 and the hand-written
        // 0.11.999 stayed. A path repository is canonical, so Composer refused
        // the whole set instead of resolving the release from Packagist.
        self::assertSame('0.11.999', MinimumReleasePin::pathRepositoryVersion('^0.11.6'));
        self::assertSame('0.12.999', MinimumReleasePin::pathRepositoryVersion('^0.12.0'));
        self::assertSame('1.4.999', MinimumReleasePin::pathRepositoryVersion('^1.4.2'));
    }

    public function testTheDeclaredMinimumIsReadFromTheConstraint(): void
    {
        self::assertSame('0.12.0', MinimumReleasePin::minimumRelease('^0.12.0'));
        self::assertSame('0.12.0', MinimumReleasePin::minimumRelease('  ^0.12.0 '));
    }

    public function testAConstraintShapeItCannotReasonAboutIsRefused(): void
    {
        // Silently guessing here would put the runner back where it started:
        // mounting a version nobody chose.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expected a caret constraint such as ^0.12.0, got: 0.2.*');
        MinimumReleasePin::pathRepositoryVersion('0.2.*');
    }

    public function testEveryPathMountedCandidateTracksTheRepositoryConstraint(): void
    {
        $composer = dirname(__DIR__) . '/composer.json';
        foreach (['voku/agent-recall-compiler', 'voku/agent-learning', 'voku/agent-session'] as $package) {
            $constraint = MinimumReleasePin::declaredConstraint($composer, $package);
            self::assertMatchesRegularExpression(
                '/^\^\d+\.\d+\.\d+$/',
                $constraint,
                $package . ' must declare a caret constraint the candidate dogfoods can derive a mount version from.',
            );
        }
    }

    public function testARunnerCannotReDeclareAVersionByHand(): void
    {
        // Two runners and two workflow pins encoded the same minimum. The
        // runners now derive it; this keeps the literal from creeping back.
        foreach (['prompt-primitives-dogfood.php', 'release-set-dogfood.php'] as $runner) {
            $source = (string) file_get_contents(dirname(__DIR__) . '/tools/' . $runner);
            self::assertDoesNotMatchRegularExpression(
                "/'\\d+\\.\\d+\\.999'/",
                $source,
                $runner . ' must derive its path-repository version from composer.json, not hard-code it.',
            );
        }
    }
}
