<?php

declare(strict_types=1);

namespace voku\AgentLoop;

use voku\AgentLoop\Init\InitConfigLoader;

/**
 * Single authoritative source for where `workflow plan/context/status/report/close`,
 * `agent-loop verify`, and `recall compile` (when invoked through this
 * Dispatcher) read and write a task's compiled recall briefing. Configure
 * `paths.recall_root` in `.agent-loop/init.json` to point this at the
 * project's own convention (for example `infra/doc/agent-learning/recall-output`).
 *
 * With no config: if `<rootPath>/infra/doc/agent-learning` exists, defaults
 * to `<rootPath>/infra/doc/agent-learning/recall-output` (the same
 * learning-root convention already auto-detected elsewhere in this codebase,
 * e.g. WorkflowReportCommand::resolveLearningRoot()); otherwise defaults to
 * `<rootPath>/recall`. This is a single sane default, not a multi-candidate
 * guess: every caller resolves the same one path.
 */
final class RecallOutputRoot
{
    private const DEFAULT_RELATIVE = 'recall';

    private const LEARNING_ROOT_RELATIVE = 'infra/doc/agent-learning';

    private const LEARNING_ROOT_RECALL_OUTPUT_RELATIVE = 'infra/doc/agent-learning/recall-output';

    private const CONFIG_RELATIVE = '.agent-loop/init.json';

    public static function resolve(string $rootPath): string
    {
        $configured = (new InitConfigLoader($rootPath))
            ->load(rtrim($rootPath, '/') . '/' . self::CONFIG_RELATIVE)['paths']['recall_root'] ?? null;

        if (is_string($configured) && $configured !== '') {
            return PathResolver::join($rootPath, $configured);
        }

        if (is_dir(rtrim($rootPath, '/') . '/' . self::LEARNING_ROOT_RELATIVE)) {
            return PathResolver::join($rootPath, self::LEARNING_ROOT_RECALL_OUTPUT_RELATIVE);
        }

        return PathResolver::join($rootPath, self::DEFAULT_RELATIVE);
    }

    /**
     * Renders an absolute path resolved via resolve() (or any absolute path
     * under $rootPath) as a root-relative path for display, matching
     * PathResolver::relativeTo() so all callers share one implementation.
     */
    public static function relativeTo(string $rootPath, string $absolutePath): string
    {
        return PathResolver::relativeTo($rootPath, $absolutePath);
    }
}
