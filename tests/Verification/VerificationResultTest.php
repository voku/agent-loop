<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests\Verification;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Verification\VerificationMessage;
use voku\AgentLoop\Verification\VerificationResult;
use voku\AgentLoop\Verification\VerificationSeverity;

/**
 * @internal
 */
final class VerificationResultTest extends TestCase
{
    public function testExitCodeIsZeroWithoutFailures(): void
    {
        $result = new VerificationResult([
            new VerificationMessage(VerificationSeverity::OK, 'board', 'board command is wired'),
            new VerificationMessage(VerificationSeverity::WARN, 'docs', 'README does not yet document workflow:verify'),
            new VerificationMessage(VerificationSeverity::SKIP, 'docs', 'no README.md found'),
        ]);

        self::assertFalse($result->hasFailures());
        self::assertSame(0, $result->exitCode());
    }

    public function testExitCodeIsOneWithAtLeastOneFailure(): void
    {
        $result = new VerificationResult([
            new VerificationMessage(VerificationSeverity::OK, 'board', 'board command is wired'),
            new VerificationMessage(VerificationSeverity::FAIL, 'session', 'session command failed to wire'),
        ]);

        self::assertTrue($result->hasFailures());
        self::assertSame(1, $result->exitCode());
    }

    public function testMessagesReturnsTheOriginalList(): void
    {
        $messages = [
            new VerificationMessage(VerificationSeverity::OK, 'board', 'board command is wired'),
        ];

        self::assertSame($messages, (new VerificationResult($messages))->messages());
    }

    public function testRenderJoinsEachMessageOnItsOwnLine(): void
    {
        $result = new VerificationResult([
            new VerificationMessage(VerificationSeverity::OK, 'board', 'board command is wired'),
            new VerificationMessage(VerificationSeverity::FAIL, 'session', 'session command failed to wire'),
        ]);

        self::assertSame(
            "[OK] board: board command is wired\n[FAIL] session: session command failed to wire",
            $result->render(),
        );
    }
}
