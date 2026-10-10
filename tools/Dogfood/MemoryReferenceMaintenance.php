<?php

declare(strict_types=1);

namespace voku\AgentLoop\Dogfood;

/**
 * Recognizes a proof-repaired canonical-home edit, never a change to durable guidance.
 *
 * The Learning owner still validates all applied wording and lineage. This
 * evidence does not claim independent human approval.
 */
final readonly class MemoryReferenceMaintenance
{
    /**
     * @param list<string> $changedFiles Paths in the base-to-head Git diff
     * @param array<string, array<string, mixed>> $beforeProposals Applied proposals at merge-base, keyed by path
     * @param array<string, array<string, mixed>> $afterProposals Applied proposals at head, keyed by path
     */
    public function isVerified(
        string $beforeMemory,
        string $afterMemory,
        array $changedFiles,
        array $beforeProposals,
        array $afterProposals,
        string $projectRoot,
    ): bool {
        $proofFiles = $this->proofFiles($changedFiles);
        if ($proofFiles === null || $beforeMemory === $afterMemory
            || array_keys($beforeProposals) !== $proofFiles
            || array_keys($afterProposals) !== $proofFiles
        ) {
            return false;
        }

        return $this->onlyCanonicalHomesChanged($beforeMemory, $afterMemory, $projectRoot)
            && $this->proofsMatch($proofFiles, $beforeProposals, $afterProposals, hash('sha256', $afterMemory));
    }

    /**
     * @param list<string> $changedFiles
     * @return list<string>|null
     */
    private function proofFiles(array $changedFiles): ?array
    {
        if (!in_array('MEMORY.md', $changedFiles, true)) {
            return null;
        }

        $paths = [];
        foreach ($changedFiles as $path) {
            if ($path === 'MEMORY.md') {
                continue;
            }
            if (preg_match('#^\.agent-loop/learning/proposals/applied/proposal\.[0-9.-]+\.json$#D', $path) !== 1) {
                return null;
            }
            $paths[] = $path;
        }

        return $paths === [] ? null : $paths;
    }

    private function onlyCanonicalHomesChanged(string $before, string $after, string $projectRoot): bool
    {
        $oldLines = explode("\n", $before);
        $newLines = explode("\n", $after);
        $root = realpath($projectRoot);
        if (count($oldLines) !== count($newLines) || $root === false) {
            return false;
        }

        $corrected = 0;
        foreach ($oldLines as $i => $oldLine) {
            if ($oldLine === $newLines[$i]) {
                continue;
            }
            $changes = $this->referenceChanges($oldLine, $newLines[$i], $root);
            if ($changes === null) {
                return false;
            }
            $corrected += $changes;
        }

        return $corrected > 0;
    }

    /** Returns null for semantic drift, otherwise the number of resolved new references. */
    private function referenceChanges(string $oldLine, string $newLine, string $root): ?int
    {
        $oldColumns = explode('|', $oldLine);
        $newColumns = explode('|', $newLine);
        if (count($oldColumns) !== 5 || count($newColumns) !== 5
            || $oldColumns[0] !== '' || $oldColumns[4] !== ''
            || $newColumns[0] !== '' || $newColumns[4] !== ''
            || $oldColumns[1] !== $newColumns[1] || $oldColumns[2] !== $newColumns[2]
        ) {
            return null;
        }

        $oldHome = $oldColumns[3];
        $newHome = $newColumns[3];
        if (preg_replace('/`[^`]+`/', '`<reference>`', $oldHome)
            !== preg_replace('/`[^`]+`/', '`<reference>`', $newHome)
        ) {
            return null;
        }
        preg_match_all('/`([^`]+)`/', $oldHome, $oldReferences);
        preg_match_all('/`([^`]+)`/', $newHome, $newReferences);
        if (count($oldReferences[1]) !== count($newReferences[1])) {
            return null;
        }

        $corrected = 0;
        foreach ($newReferences[1] as $i => $reference) {
            if ($reference === $oldReferences[1][$i]) {
                continue;
            }
            if (!$this->resolvedWithinProject($reference, $root)) {
                return null;
            }
            ++$corrected;
        }

        return $corrected;
    }

    private function resolvedWithinProject(string $reference, string $root): bool
    {
        if (str_starts_with($reference, '/') || str_contains($reference, '..') || str_contains($reference, '\\')) {
            return false;
        }

        $path = realpath($root . '/' . $reference);

        return $path !== false && str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }

    /**
     * @param list<string> $proofFiles
     * @param array<string, array<string, mixed>> $beforeProposals
     * @param array<string, array<string, mixed>> $afterProposals
     */
    private function proofsMatch(array $proofFiles, array $beforeProposals, array $afterProposals, string $hash): bool
    {
        foreach ($proofFiles as $path) {
            if (!$this->sameApprovalWithValidReanchor($beforeProposals[$path], $afterProposals[$path], $hash)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    private function sameApprovalWithValidReanchor(array $before, array $after, string $hash): bool
    {
        if (($before['status'] ?? null) !== 'applied' || ($after['status'] ?? null) !== 'applied') {
            return false;
        }
        $oldProof = $before['applied_validation'] ?? null;
        $newProof = $after['applied_validation'] ?? null;
        if (!is_array($oldProof) || !is_array($newProof)
            || ($oldProof['target_source_ref'] ?? null) !== 'MEMORY.md'
            || ($newProof['target_source_ref'] ?? null) !== 'MEMORY.md'
            || ($newProof['target_content_hash'] ?? null) !== $hash
            || ($oldProof['target_content_hash'] ?? null) === $hash
            || !is_string($newProof['reanchored_by'] ?? null) || trim($newProof['reanchored_by']) === ''
            || !is_string($newProof['reanchor_reason'] ?? null) || trim($newProof['reanchor_reason']) === ''
            || !is_string($newProof['reanchored_at'] ?? null) || trim($newProof['reanchored_at']) === ''
        ) {
            return false;
        }

        foreach (['target_content_hash', 'reanchored_by', 'reanchored_at', 'reanchor_reason'] as $field) {
            unset($oldProof[$field], $newProof[$field]);
        }
        $before['applied_validation'] = $oldProof;
        $after['applied_validation'] = $newProof;

        return $before === $after;
    }
}
