<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests\Verification;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Verification\VerificationMessage;
use voku\AgentLoop\Verification\VerificationSeverity;

/**
 * @internal
 */
final class VerificationMessageTest extends TestCase
{
    public function testRenderFormatsEachSeverity(): void
    {
        self::assertSame(
            '[OK] board: board command is wired',
            (new VerificationMessage(VerificationSeverity::OK, 'board', 'board command is wired'))->render(),
        );
        self::assertSame(
            '[WARN] docs: README does not yet document workflow:verify',
            (new VerificationMessage(VerificationSeverity::WARN, 'docs', 'README does not yet document workflow:verify'))->render(),
        );
        self::assertSame(
            '[SKIP] docs: no README.md found',
            (new VerificationMessage(VerificationSeverity::SKIP, 'docs', 'no README.md found'))->render(),
        );
        self::assertSame(
            '[FAIL] session: voku/agent-session Cli is not installed; the session command cannot be wired',
            (new VerificationMessage(VerificationSeverity::FAIL, 'session', 'voku/agent-session Cli is not installed; the session command cannot be wired'))->render(),
        );
    }
}
