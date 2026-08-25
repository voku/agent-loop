<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

/**
 * Severity of one typed repository-setup diagnostic.
 *
 * The backing values are the same tokens {@see InitCheckLevel} renders, so a
 * CLI adapter can print `[LEVEL] message` without a translation table and
 * hosts can compare severity without parsing text.
 */
enum RepositorySetupDiagnosticLevel: string
{
    case OK = InitCheckLevel::OK;
    case WARN = InitCheckLevel::WARN;
    case INFO = InitCheckLevel::INFO;
    case FAIL = InitCheckLevel::FAIL;

    /** Ordering used to reduce many diagnostics to the single worst severity. */
    public function weight(): int
    {
        return match ($this) {
            self::INFO => 0,
            self::OK => 1,
            self::WARN => 2,
            self::FAIL => 3,
        };
    }

    public function isBlocking(): bool
    {
        return $this === self::FAIL;
    }

    public function needsAction(): bool
    {
        return $this === self::WARN || $this === self::FAIL;
    }
}
