<?php

declare(strict_types=1);

namespace voku\AgentLoop\Dogfood;

/**
 * Recognizes a proof-repaired canonical-home edit, never a change to durable guidance.
 *
 * The caller must also run the Learning owner's validation/lineage rebuild. A
 * reanchor receipt is not itself independent human approval.
 */
final readonly class MemoryReferenceMaintenance
{
    /**
     * @param list<string> $changedFiles Paths in the base-to-head Git diff
     * @param array<string, array<string, mixed>> $beforeProposals Applied proposal JSON at merge-base, keyed by path
     * @param array<string, array<string, mixed>> $afterProposals Applied proposal JSON at head, keyed by path
     */
    public function isVerified(
        string $beforeMemory,
        string $afterMemory,
        array $changedFiles,
        array $beforeProposals,
        array $afterProposals,
        string $projectRoot,
    ): bool {
        $proofFiles = [];
        foreach ($changedFiles as $path) {
            if ($path === 'MEMORY.md') {
                continue;
            }
            if (preg_match('#^\.agent-loop/learning/proposals/applied/proposal\.[0-9.-]+\.json$#D', $path) !== 1) {
                return false;
            }
            $proofFiles[] = $path;
        }
        if (!in_array('MEMORY.md', $changedFiles, true) || $proofFiles === [] || $beforeMemory === $afterMemory) {
            return false;
        }
        if (array_keys($beforeProposals) !== $proofFiles || array_keys($afterProposals) !== $proofFiles) {
            return false;
        }

        $oldLines = explode("\n", $beforeMemory);
        $newLines = explode("\n", $afterMemory);
        if (count($oldLines) !== count($newLines)) {
            return false;
        }
        $corrected = 0;
        foreach ($oldLines as $i => $oldLine) {
            $newLine = $newLines[$i];
            if ($oldLine === $newLine) {
                continue;
            }
            $oldColumns = explode('|', $oldLine);
            $newColumns = explode('|', $newLine);
            if (count($oldColumns) !== 5 || count($newColumns) !== 5
                || $oldColumns[0] !== '' || $oldColumns[4] !== ''
                || $newColumns[0] !== '' || $newColumns[4] !== ''
                || $oldColumns[1] !== $newColumns[1] || $oldColumns[2] !== $newColumns[2]
            ) {
                return false;
            }
            $oldHome = $oldColumns[3];
            $newHome = $newColumns[3];
            $withoutReferences = static fn (string $value): string => (string) preg_replace('/`[^`]+`/', '`<reference>`', $value);
            if ($withoutReferences($oldHome) !== $withoutReferences($newHome)) {
                return false;
            }
            preg_match_all('/`([^`]+)`/', $oldHome, $oldReferences);
            preg_match_all('/`([^`]+)`/', $newHome, $newReferences);
            if (count($oldReferences[1]) !== count($newReferences[1])) {
                return false;
            }
            foreach ($newReferences[1] as $j => $reference) {
                if ($reference === $oldReferences[1][$j]) {
                    continue;
                }
                // Changed references must point to real, contained files/directories.
                if (str_starts_with($reference, '/') || str_contains($reference, '..') || str_contains($reference, '\\')) {
                    return false;
                }
                $root = realpath($projectRoot);
                $resolved = realpath($projectRoot . '/' . $reference);
                if ($root === false || $resolved === false || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
                    return false;
                }
                ++$corrected;
            }
        }
        if ($corrected === 0) {
            return false;
        }

        $headHash = hash('sha256', $afterMemory);
        foreach ($proofFiles as $path) {
            $before = $beforeProposals[$path];
            $after = $afterProposals[$path];
            if (($before['status'] ?? null) !== 'applied' || ($after['status'] ?? null) !== 'applied') {
                return false;
            }
            $oldValidation = $before['applied_validation'] ?? null;
            $newValidation = $after['applied_validation'] ?? null;
            if (!is_array($oldValidation) || !is_array($newValidation)
                || ($oldValidation['target_source_ref'] ?? null) !== 'MEMORY.md'
                || ($newValidation['target_source_ref'] ?? null) !== 'MEMORY.md'
                || ($newValidation['target_content_hash'] ?? null) !== $headHash
                || ($oldValidation['target_content_hash'] ?? null) === $headHash
                || !is_string($newValidation['reanchored_by'] ?? null)
                || trim($newValidation['reanchored_by']) === ''
                || !is_string($newValidation['reanchor_reason'] ?? null)
                || trim($newValidation['reanchor_reason']) === ''
                || !is_string($newValidation['reanchored_at'] ?? null)
                || trim($newValidation['reanchored_at']) === ''
            ) {
                return false;
            }
            foreach (['target_content_hash', 'reanchored_by', 'reanchored_at', 'reanchor_reason'] as $field) {
                unset($oldValidation[$field], $newValidation[$field]);
            }
            $before['applied_validation'] = $oldValidation;
            $after['applied_validation'] = $newValidation;
            if ($before !== $after) {
                return false;
            }
        }

        return true;
    }
}
