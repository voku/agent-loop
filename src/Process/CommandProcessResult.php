<?php

declare(strict_types=1);

namespace voku\AgentLoop\Process;

final readonly class CommandProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public bool $timedOut,
    ) {
    }
}
