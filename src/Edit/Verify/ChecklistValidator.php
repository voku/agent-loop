<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit\Verify;

use voku\AgentLoop\PathResolver;

/**
 * Checks that every checklist obligation was answered with evidence that actually exists.
 *
 * What this can decide and what it cannot is a hard line. Whether a `human_review` item was
 * genuinely reviewed is not something a program can observe, so this never claims it did: it
 * verifies that evidence was supplied and that each reference resolves to a real artifact - a file
 * in the bundle, a changed file, or an id the plan itself declared. Judging the content stays with
 * the human, and an item with no evidence is `missing`, not `passed`.
 */
final readonly class ChecklistValidator
{
    /**
     * @param list<array<string, mixed>> $checklist
     * @param array<string, mixed> $evidence `checklist_evidence` from agent-result.json
     *
     * @return list<array{id: string, verifier: string, required: bool, status: string, detail: string}>
     */
    public function validate(array $checklist, array $evidence, VerificationBundle $bundle, string $projectRoot): array
    {
        $known = $this->knownEvidenceIds($bundle);
        $results = [];
        foreach ($checklist as $item) {
            $id = $item['id'] ?? null;
            if (!is_string($id) || $id === '') {
                continue;
            }

            $verifier = is_string($item['verifier'] ?? null) ? $item['verifier'] : 'unknown';
            $required = ($item['required'] ?? true) !== false;
            $references = $this->references($evidence[$id] ?? null);

            if ($references === []) {
                $results[] = ['id' => $id, 'verifier' => $verifier, 'required' => $required, 'status' => 'missing', 'detail' => 'No evidence was supplied.'];
                continue;
            }

            $unresolved = [];
            foreach ($references as $reference) {
                if (!$this->resolves($reference, $known, $bundle, $projectRoot)) {
                    $unresolved[] = $reference;
                }
            }

            $results[] = $unresolved === []
                ? ['id' => $id, 'verifier' => $verifier, 'required' => $required, 'status' => 'passed', 'detail' => count($references) . ' evidence reference(s) resolved.']
                : ['id' => $id, 'verifier' => $verifier, 'required' => $required, 'status' => 'failed', 'detail' => 'Unresolved evidence reference(s): ' . implode(', ', $unresolved) . '.'];
        }

        return $results;
    }

    /** @param array<string, true> $known */
    private function resolves(string $reference, array $known, VerificationBundle $bundle, string $projectRoot): bool
    {
        if (isset($known[$reference])) {
            return true;
        }

        return $this->resolvesInside($bundle->directory, $reference)
            || $this->resolvesInside($projectRoot, $reference);
    }

    /**
     * A reference resolves only to a file that really sits under the bundle or the
     * project - the three categories this validator claims to accept.
     *
     * The bare `is_file($reference)` fallback that used to close this method accepted
     * any readable path on the machine, so `/etc/hostname` "resolved" and a required
     * `human_review` item passed on evidence belonging to no task at all. Relative
     * references climbing out with `..` are refused for the same reason.
     */
    private function resolvesInside(string $base, string $reference): bool
    {
        $realBase = realpath($base);
        if ($realBase === false) {
            return false;
        }
        $realBase = rtrim(str_replace('\\', '/', $realBase), '/');

        $candidate = PathResolver::isAbsolute($reference)
            ? $reference
            : $realBase . '/' . ltrim($reference, '/');

        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved)) {
            return false;
        }

        return str_starts_with(str_replace('\\', '/', $resolved), $realBase . '/');
    }

    /**
     * Ids the plan and the observed result already vouch for. Anything else has to exist on disk.
     *
     * @return array<string, true>
     */
    private function knownEvidenceIds(VerificationBundle $bundle): array
    {
        $known = [];
        foreach ($bundle->knowledgeProbes() as $probe) {
            foreach (is_array($probe['evidence_ids'] ?? null) ? $probe['evidence_ids'] : [] as $evidenceId) {
                if (is_string($evidenceId)) {
                    $known[$evidenceId] = true;
                }
            }
        }
        foreach ($bundle->checklist() as $item) {
            foreach (is_array($item['evidence_ids'] ?? null) ? $item['evidence_ids'] : [] as $evidenceId) {
                if (is_string($evidenceId)) {
                    $known[$evidenceId] = true;
                }
            }
        }
        foreach ($bundle->changedFiles() as $file) {
            $known[$file] = true;
        }

        return $known;
    }

    /** @return list<string> */
    private function references(mixed $entry): array
    {
        $references = [];
        foreach (is_array($entry) ? $entry : [$entry] as $reference) {
            if (is_string($reference) && trim($reference) !== '') {
                $references[] = trim($reference);
            }
        }

        return $references;
    }
}
