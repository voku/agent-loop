<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

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

    public function render(): string
    {
        return '[' . $this->level . '] ' . $this->message;
    }
}
