<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow\Transparency;

use RuntimeException;
use voku\AgentLoop\GitWorkTree;
use voku\AgentLoop\Process\CommandProcessRunner;
use voku\AgentLoop\Workflow\TaskContract;

/**
 * Asks Git which repository-relative paths differ from the Contract baseline.
 *
 * Read-only by construction: every command here is a query. Nothing writes an
 * index, a ref, a commit or a stash, so running a transparency projection can
 * never change what the next one observes.
 */
final readonly class RepositoryObservationCollector
{
    private const int COMMAND_TIMEOUT_SECONDS = 30;

    public function __construct(private string $rootPath)
    {
    }

    public function collect(?TaskContract $contract): RepositoryObservation
    {
        if ($contract === null) {
            return RepositoryObservation::unavailable(
                RepositoryObservationStatus::NO_CONTRACT,
                null,
                'No durable Contract, so there is no approved baseline to observe changes against.',
            );
        }
        if ($contract->baseCommit === null) {
            return RepositoryObservation::unavailable(
                RepositoryObservationStatus::NO_BASE_COMMIT,
                null,
                'Contract records no base commit; a baseline cannot be guessed from current Git state.',
            );
        }

        $root = realpath($this->rootPath);
        if ($root === false || !is_dir($root)) {
            return RepositoryObservation::unavailable(
                RepositoryObservationStatus::PROJECT_ROOT_UNAVAILABLE,
                $contract->baseCommit,
                'Project root is unavailable.',
            );
        }
        if (!GitWorkTree::detected($root)) {
            return RepositoryObservation::unavailable(
                RepositoryObservationStatus::NOT_A_GIT_WORK_TREE,
                $contract->baseCommit,
                'Project root is not inside a Git work tree.',
            );
        }

        $base = $contract->baseCommit;
        $runner = new CommandProcessRunner();
        try {
            $verified = $runner->run(['git', 'rev-parse', '--verify', '--quiet', $base . '^{commit}'], $root, self::COMMAND_TIMEOUT_SECONDS);
            if ($verified->exitCode !== 0) {
                return RepositoryObservation::unavailable(
                    RepositoryObservationStatus::BASE_COMMIT_UNKNOWN,
                    $base,
                    'Contract base commit is not available in this Git checkout.',
                );
            }

            $committed = $this->names($runner, $root, ['git', 'diff', '--name-only', '-z', '--no-ext-diff', '--find-renames', $base, 'HEAD', '--']);
            $staged = $this->names($runner, $root, ['git', 'diff', '--name-only', '-z', '--no-ext-diff', '--find-renames', '--cached', '--']);
            $unstaged = $this->names($runner, $root, ['git', 'diff', '--name-only', '-z', '--no-ext-diff', '--find-renames', '--']);
            $untracked = $this->names($runner, $root, ['git', 'ls-files', '--others', '--exclude-standard', '-z', '--']);
        } catch (RuntimeException) {
            return RepositoryObservation::unavailable(
                RepositoryObservationStatus::GIT_FAILED,
                $base,
                'Git could not derive the change set from the Contract base commit.',
            );
        }

        return RepositoryObservation::observed(
            baseCommit: $base,
            headCommit: GitWorkTree::headCommit($root),
            committed: $committed,
            staged: $staged,
            unstaged: $unstaged,
            untracked: $untracked,
        );
    }

    /**
     * @param non-empty-list<string> $command
     * @return list<string>
     */
    private function names(CommandProcessRunner $runner, string $root, array $command): array
    {
        $result = $runner->run($command, $root, self::COMMAND_TIMEOUT_SECONDS);
        if ($result->exitCode !== 0) {
            throw new RuntimeException('Git query failed: ' . implode(' ', $command));
        }

        return self::nulList($result->stdout);
    }

    /**
     * Git's `-z` output is the only safe reader: a path containing a space, a
     * quote or a newline survives it unchanged, while the default output would
     * quote and escape it into a different string.
     *
     * @return list<string>
     */
    private static function nulList(string $value): array
    {
        $items = array_values(array_unique(array_filter(
            explode("\0", $value),
            static fn (string $item): bool => $item !== '',
        )));
        sort($items, SORT_STRING);

        return $items;
    }
}
