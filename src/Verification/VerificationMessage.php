<?php

declare(strict_types=1);

namespace voku\AgentLoop\Verification;

final readonly class VerificationMessage
{
    public function __construct(
        public VerificationSeverity $severity,
        public string $scope,
        public string $message,
    ) {
    }

    public function render(): string
    {
        return '[' . $this->severity->value . '] ' . $this->scope . ': ' . $this->message;
    }
}
