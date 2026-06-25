<?php

declare(strict_types=1);

namespace voku\AgentLoop\Verification;

/**
 * Severity of a single workflow-verification check.
 *
 * OK   - check passed.
 * WARN - suspicious but not fatal.
 * SKIP - optional input or optional dependency state missing.
 * FAIL - a required workflow contract is broken.
 */
enum VerificationSeverity: string
{
    case OK = 'OK';
    case WARN = 'WARN';
    case SKIP = 'SKIP';
    case FAIL = 'FAIL';
}
