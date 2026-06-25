<?php

declare(strict_types=1);

namespace voku\AgentLoop\Verification;

final class VerificationResult
{
    /**
     * @param list<VerificationMessage> $messages
     */
    public function __construct(private readonly array $messages)
    {
    }

    /**
     * @return list<VerificationMessage>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    public function hasFailures(): bool
    {
        foreach ($this->messages as $message) {
            if ($message->severity === VerificationSeverity::FAIL) {
                return true;
            }
        }

        return false;
    }

    public function exitCode(): int
    {
        return $this->hasFailures() ? 1 : 0;
    }

    public function render(): string
    {
        return implode("\n", array_map(
            static fn (VerificationMessage $message): string => $message->render(),
            $this->messages,
        ));
    }
}
