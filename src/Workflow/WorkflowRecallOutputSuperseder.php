<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use RuntimeException;
use voku\AgentLoop\RecallOutputRoot;
use voku\AgentLoop\Run\RelativePath;

/**
 * Archives derived recall output before a newly approved brief revision is
 * compiled into the canonical task directory.
 *
 * Without this boundary, a failed recompile could leave the previous revision's
 * `meta.json` and review in place, making the new approval appear prepared and
 * reviewed when it was neither. The old output is retained for audit instead of
 * being deleted or silently reused.
 */
final readonly class WorkflowRecallOutputSuperseder
{
    public function __construct(private string $rootPath)
    {
    }

    public function archiveIfPresent(string $taskId): ?string
    {
        $directory = RecallOutputRoot::resolve($this->rootPath) . '/' . $taskId;
        if (!is_dir($directory)) {
            return null;
        }

        $identitySource = $directory . '/meta.json';
        $digest = is_file($identitySource) ? hash_file('sha256', $identitySource) : false;
        $suffix = $digest === false ? 'unknown' : substr($digest, 0, 12);
        $archive = $directory . '.superseded-' . $suffix;
        for ($attempt = 1; file_exists($archive); ++$attempt) {
            $archive = $directory . '.superseded-' . $suffix . '-' . $attempt;
        }

        if (!rename($directory, $archive)) {
            throw new RuntimeException('Unable to archive superseded recall output: ' . $directory);
        }

        return RelativePath::fromRoot($this->rootPath, $archive);
    }
}
