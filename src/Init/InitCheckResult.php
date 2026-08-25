<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use LogicException;

/**
 * One init check outcome.
 *
 * `render()` exists for CLI output, but it is not the way to read this object.
 * Consumers that need the outcome — including the typed setup diagnostics
 * projection — ask for {@see level()} and {@see message()} so nobody has to
 * parse a rendered string back into a decision.
 */
final readonly class InitCheckResult
{
    private function __construct(
        private string $level,
        private string $message,
    ) {
    }

    public static function ok(string $message): self
    {
        return new self(InitCheckLevel::OK, $message);
    }

    public static function warn(string $message): self
    {
        return new self(InitCheckLevel::WARN, $message);
    }

    public static function info(string $message): self
    {
        return new self(InitCheckLevel::INFO, $message);
    }

    public static function fail(string $message): self
    {
        return new self(InitCheckLevel::FAIL, $message);
    }

    public function level(): RepositorySetupDiagnosticLevel
    {
        return RepositorySetupDiagnosticLevel::from($this->level);
    }

    /** @return non-empty-string */
    public function message(): string
    {
        if ($this->message === '') {
            throw new LogicException('An init check result was constructed without a message.');
        }

        return $this->message;
    }

    public function render(): string
    {
        return '[' . $this->level . '] ' . $this->message;
    }
}
