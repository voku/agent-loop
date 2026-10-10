<?php

declare(strict_types=1);

namespace voku\AgentLoop\Dogfood;

use RuntimeException;

/**
 * Decides what a pull request recorded, from the paths it touched.
 *
 * This is the part of the self-shape gate that has actually been wrong. As
 * Bash it watched `findings/validated/` alone, but `learn finding-transition`
 * moves a consolidated finding out of that directory - the normal end of its
 * lifecycle - so a change whose entire subject was recording learning looked
 * as though it had recorded none, and the gate then chose a status it could
 * not satisfy.
 *
 * Findings are therefore matched by identity across every state directory. A
 * shell script could have done that too; what it could not do is be tested,
 * which is why the defect shipped.
 */
final readonly class SelfShapeEvidence
{
    private const string FINDINGS_PREFIX = '.agent-loop/learning/findings/';

    private const string MEMORY_FILE = 'MEMORY.md';

    /**
     * @param list<string> $changedPaths every path added or renamed between base and head
     * @param bool $memoryChanged whether MEMORY.md differs between base and head
     */
    public function __construct(
        private array $changedPaths,
        private bool $memoryChanged,
        private bool $verifiedCanonicalHomeReanchor = false,
    ) {
    }

    /**
     * Finding IDs this change recorded, in stable order and without repeats.
     *
     * A rename shows the same finding under two paths; the ID is what the
     * Learning decision cites, so the ID is what gets de-duplicated.
     *
     * @return list<string>
     */
    public function recordedFindingIds(): array
    {
        $ids = [];
        foreach ($this->changedPaths as $path) {
            $normalized = str_replace('\\', '/', $path);
            if (!str_starts_with($normalized, self::FINDINGS_PREFIX) || !str_ends_with($normalized, '.json')) {
                continue;
            }
            $id = basename($normalized, '.json');
            if (str_starts_with($id, 'finding.')) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Confirm a Memory rule changed only where its canonical evidence lives.
     *
     * This is proof maintenance, not a promotion: all durable rule wording must
     * stay byte-identical, every new local path must exist, and all applied
     * MEMORY.md proposals must carry a current, explicitly reasoned reanchor.
     * Learning itself still owns semantic proof/lineage validation.
     *
     * @param list<string> $changedFiles
     */
    public static function hasVerifiedCanonicalHomeReanchor(
        string $previousMemory,
        string $currentMemory,
        array $changedFiles,
        string $repositoryRoot,
    ): bool {
        $before = explode("\n", $previousMemory);
        $after = explode("\n", $currentMemory);
        if (count($before) !== count($after) || $previousMemory === $currentMemory) {
            return false;
        }

        $changedRows = 0;
        foreach ($before as $index => $line) {
            if ($line === $after[$index]) {
                continue;
            }

            $pattern = '~^\| (?<subject>[^|]+) \| (?<rule>[^|]+) \| (?<home>.+) \|$~';
            if (preg_match($pattern, $line, $old) !== 1
                || preg_match($pattern, $after[$index], $new) !== 1
                || $old['subject'] !== $new['subject']
                || $old['rule'] !== $new['rule']
                || $old['home'] === $new['home']
            ) {
                return false;
            }

            preg_match_all('/\x60([^\x60]+)\x60/', $old['home'], $oldRefs);
            preg_match_all('/\x60([^\x60]+)\x60/', $new['home'], $newRefs);
            $addedRefs = array_diff($newRefs[1], $oldRefs[1]);
            if ($addedRefs === []) {
                return false;
            }
            foreach ($addedRefs as $ref) {
                if (str_contains($ref, '..')
                    || !preg_match('~^(?:\.agent-loop|\.github|tools|docs|src|tests|resources)/~', $ref)
                ) {
                    return false;
                }
                $absolute = rtrim($repositoryRoot, '/') . '/' . $ref;
                if (!is_file($absolute) && !is_dir($absolute)) {
                    return false;
                }
            }

            ++$changedRows;
        }

        if ($changedRows === 0) {
            return false;
        }

        $proofs = 0;
        $hash = hash('sha256', $currentMemory);
        $paths = glob(rtrim($repositoryRoot, '/') . '/.agent-loop/learning/proposals/applied/*.json') ?: [];
        foreach ($paths as $path) {
            $record = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($record) || ($record['target_type'] ?? null) !== 'memory') {
                continue;
            }
            $proof = $record['applied_validation'] ?? null;
            if (!is_array($proof) || ltrim((string) ($proof['target_source_ref'] ?? ''), './') !== 'MEMORY.md') {
                continue;
            }

            $relative = substr($path, strlen(rtrim($repositoryRoot, '/')) + 1);
            if (!in_array($relative, $changedFiles, true)
                || ($proof['target_content_hash'] ?? null) !== $hash
            ) {
                return false;
            }
            foreach (['reanchored_by', 'reanchored_at', 'reanchor_reason'] as $field) {
                if (!is_string($proof[$field] ?? null) || trim($proof[$field]) === '') {
                    return false;
                }
            }

            ++$proofs;
        }

        return $proofs > 0;
    }

    /**
     * The Learning decision this change's own evidence supports.
     *
     * `findings_recorded` requires at least one cited finding, so it can only
     * be chosen when one exists. A `MEMORY.md` change with no finding behind it
     * is not a status to be selected around: the repository's promotion rule
     * says a durable rule needs evidence, so it fails here instead.
     */
    public function learningStatus(): string
    {
        if ($this->recordedFindingIds() !== []) {
            return 'findings_recorded';
        }

        if ($this->memoryChanged && !$this->verifiedCanonicalHomeReanchor) {
            throw new RuntimeException(
                self::MEMORY_FILE . ' changed but no project finding was recorded; a durable rule needs evidence.',
            );
        }

        return 'no_durable_learning';
    }

    public function learningReason(): string
    {
        if ($this->verifiedCanonicalHomeReanchor && $this->recordedFindingIds() === []) {
            return 'Existing MEMORY.md rules kept their wording; corrected canonical homes were verified and reanchored through approved Learning proposal proofs.';
        }

        return $this->recordedFindingIds() === []
            ? 'The self-shape gate observed no new reusable guidance beyond the durable changes already represented by this pull request.'
            : 'The pull-request evidence recorded project findings; cite that evidence in the governed Run.';
    }
}
