<?php

declare(strict_types=1);

namespace voku\AgentLoop\Run;

final class RunApprovalRevisionArgument
{
    /** @param array<string, mixed> $contractReference */
    public static function fromContractReference(array $contractReference): string
    {
        $revision = filter_var($contractReference['revision'] ?? null, FILTER_VALIDATE_INT);

        return $revision !== false && $revision > 0
            ? (string) $revision
            : '<current-revision>';
    }
}
