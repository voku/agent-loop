<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

/**
 * One typed repository-setup fact.
 *
 * `message` stays human-readable so CLI output can remain byte-compatible,
 * but no consumer has to read it: `kind`, `level`, `host` and `facts` carry
 * the same information in a form a UI can render and compare directly.
 */
final readonly class RepositorySetupDiagnostic
{
    /**
     * @param non-empty-string      $message
     * @param non-empty-string|null $host  the coding host this fact belongs to, when it is host-scoped
     * @param array<non-empty-string, string> $facts owner-observed values behind the message
     */
    public function __construct(
        public RepositorySetupDiagnosticKind $kind,
        public RepositorySetupDiagnosticLevel $level,
        public string $message,
        public ?string $host = null,
        public array $facts = [],
    ) {
    }

    /** Renders exactly what {@see InitCheckResult::render()} renders, so CLI output is unchanged. */
    public function render(): string
    {
        return '[' . $this->level->value . '] ' . $this->message;
    }

    /**
     * @return array{
     *     kind: string,
     *     level: string,
     *     message: non-empty-string,
     *     host: non-empty-string|null,
     *     facts: array<non-empty-string, string>
     * }
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'level' => $this->level->value,
            'message' => $this->message,
            'host' => $this->host,
            'facts' => $this->facts,
        ];
    }
}
