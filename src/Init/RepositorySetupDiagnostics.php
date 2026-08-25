<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

/**
 * The full typed diagnostic projection for one repository.
 *
 * Immutable and mutation-free: producing it never repairs, installs, or
 * rewrites anything. Consumers reduce it themselves via {@see worstLevel()}
 * rather than agent-loop deciding on their behalf what "healthy" means.
 */
final readonly class RepositorySetupDiagnostics
{
    /** @param list<RepositorySetupDiagnostic> $diagnostics */
    public function __construct(public array $diagnostics)
    {
    }

    /** @return list<RepositorySetupDiagnostic> */
    public function byKind(RepositorySetupDiagnosticKind $kind): array
    {
        return array_values(array_filter(
            $this->diagnostics,
            static fn (RepositorySetupDiagnostic $diagnostic): bool => $diagnostic->kind === $kind,
        ));
    }

    /**
     * @param non-empty-string $host
     * @return list<RepositorySetupDiagnostic>
     */
    public function forHost(string $host): array
    {
        return array_values(array_filter(
            $this->diagnostics,
            static fn (RepositorySetupDiagnostic $diagnostic): bool => $diagnostic->host === $host,
        ));
    }

    /** @return list<RepositorySetupDiagnostic> */
    public function needingAction(): array
    {
        return array_values(array_filter(
            $this->diagnostics,
            static fn (RepositorySetupDiagnostic $diagnostic): bool => $diagnostic->level->needsAction(),
        ));
    }

    public function worstLevel(): RepositorySetupDiagnosticLevel
    {
        $worst = RepositorySetupDiagnosticLevel::INFO;
        foreach ($this->diagnostics as $diagnostic) {
            if ($diagnostic->level->weight() > $worst->weight()) {
                $worst = $diagnostic->level;
            }
        }

        return $worst;
    }

    /**
     * @return array{
     *     schema_version: 1,
     *     worst_level: string,
     *     diagnostics: list<array{
     *         kind: string,
     *         level: string,
     *         message: non-empty-string,
     *         host: non-empty-string|null,
     *         facts: array<non-empty-string, string>
     *     }>
     * }
     */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'worst_level' => $this->worstLevel()->value,
            'diagnostics' => array_map(
                static fn (RepositorySetupDiagnostic $diagnostic): array => $diagnostic->toArray(),
                $this->diagnostics,
            ),
        ];
    }
}
