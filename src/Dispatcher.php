<?php

declare(strict_types=1);

namespace voku\AgentLoop;

use voku\AgentKanban\JiraIssueProvider;
use voku\AgentKanban\TodoBoardCli;
use voku\AgentKanban\TodoBoardVerifier;
use voku\AgentLearning\Cli as LearningCli;
use voku\AgentRecallCompiler\Cli as RecallCli;
use voku\AgentSession\Cli as SessionCli;
use voku\AgentSession\SessionStore;

/**
 * Unified entrypoint for the governed agentic-coding loop.
 *
 * Routes the first CLI argument to the matching library:
 *  - `board`  -> voku/agent-kanban (TodoBoardCli)
 *  - `verify` -> voku/agent-loop (AgentLoopVerifier; cross-package consistency check)
 *  - `board:verify` -> voku/agent-kanban (TodoBoardVerifier; kanban board source only)
 *  - `learn`  -> voku/agent-learning (Cli)
 *  - `recall` -> voku/agent-recall-compiler (Cli)
 *  - `session` -> voku/agent-session (Cli)
 *  - `memory` -> voku/agent-loop (MemoryPromotionAnalyzer)
 *
 * Each library CLI expects the script name at argv[0] and its own command at
 * argv[1], so the namespace token is stripped and the remaining tokens are
 * re-prefixed with the script name before delegation.
 */
final class Dispatcher
{
    /**
     * voku/agent-kanban's own fallback project prefix when none is configured
     * and no todo/board.md exists. Mirrored here so the missing-file case can
     * be resolved before delegating, instead of letting TodoBoardSource hit
     * an unguarded file_get_contents() and emit a PHP warning.
     */
    private const string FALLBACK_PROJECT_PREFIX = 'ITPNG';

    public function __construct(
        private readonly string $rootPath,
        private readonly ?JiraIssueProvider $jiraIssueProvider = null,
        private readonly ?string $projectPrefix = null,
    ) {
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $scriptName = $argv[0] ?? 'agent-loop';
        $namespace = $argv[1] ?? 'help';
        $rest = array_slice($argv, 2);

        return match ($namespace) {
            'board' => (new TodoBoardCli($this->rootPath, $this->jiraIssueProvider, $this->resolveProjectPrefix()))
                ->run($this->subArgv($scriptName, $this->resolveBoardArgv($rest))),
            'verify' => (new AgentLoopVerifier($this->rootPath, $this->projectPrefix))->run($rest),
            'board:verify' => (new TodoBoardVerifier($this->rootPath, $this->projectPrefix))->run(),
            'learn' => (new LearningCli())->run($this->subArgv($scriptName, $rest)),
            'recall' => (new RecallCli())->run($this->subArgv($scriptName, $this->resolveRecallArgv($rest))),
            'session' => (new SessionCli())->run($this->subArgv($scriptName, $this->resolveSessionArgv($rest))),
            'memory' => (new MemoryPromotionAnalyzer($this->rootPath))->run($rest),
            'help', '--help', '-h', '' => $this->printUsage(0),
            default => $this->printUsage(1, $namespace),
        };
    }

    /**
     * Resolves the project prefix for the `board` namespace without ever
     * letting voku/agent-kanban's TodoBoardSource::readBoardMetadata() run
     * file_get_contents() against a todo/board.md that doesn't exist (which
     * emits a PHP warning instead of failing cleanly). When no explicit
     * prefix is configured and the metadata file is absent, the same
     * fallback the dependency itself would have used is supplied directly,
     * short-circuiting its lazy lookup.
     */
    private function resolveProjectPrefix(): ?string
    {
        if ($this->projectPrefix !== null) {
            return $this->projectPrefix;
        }

        $boardMetadataFile = rtrim($this->rootPath, '/') . '/todo/board.md';

        return is_file($boardMetadataFile) ? null : self::FALLBACK_PROJECT_PREFIX;
    }

    /**
     * voku/agent-kanban's TodoBoardCli has no `help`/`--help` case in its own
     * command match, so those tokens fall through to "unknown subcommand"
     * (exit 1, usage on stderr) instead of the usage-on-stdout, exit-0 path
     * that calling `board` with no arguments takes. Normalizing the help
     * tokens to "no arguments" here gives `board --help`/`board help` the
     * same clean exit as the documented `board` workaround, without touching
     * the dependency's own dispatch.
     *
     * @param list<string> $rest
     *
     * @return list<string>
     */
    private function resolveBoardArgv(array $rest): array
    {
        return in_array($rest[0] ?? null, ['help', '--help', '-h'], true) ? [] : $rest;
    }

    /**
     * Lets `session record|checkpoint|close|claim|show` accept the task id
     * passed to `session start --task` in place of the generated session id
     * (e.g. `2026-06-20-abc-123`), which is otherwise easy to confuse with
     * the task id and fails with a bare "Session not found". Resolution is a
     * read-only lookup against the existing session_plan/ files at request
     * time, not new state of its own.
     *
     * @param list<string> $rest
     *
     * @return list<string>
     */
    private function resolveSessionArgv(array $rest): array
    {
        $command = $rest[0] ?? null;
        if (!in_array($command, ['claim', 'checkpoint', 'record', 'close', 'show'], true)) {
            return $rest;
        }

        $tokens = array_slice($rest, 1);
        $sessionsRoot = null;
        $positionalIndex = null;
        $count = count($tokens);

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if (str_starts_with($token, '--')) {
                $hasValue = $i + 1 < $count && !str_starts_with($tokens[$i + 1], '--');
                if ($hasValue) {
                    if (substr($token, 2) === 'root') {
                        $sessionsRoot = $tokens[$i + 1];
                    }
                    ++$i;
                }

                continue;
            }

            if ($positionalIndex === null) {
                $positionalIndex = $i;
            }
        }

        if ($positionalIndex === null) {
            return $rest;
        }

        $sessionsRoot ??= rtrim($this->rootPath, '/') . '/session_plan';
        $store = new SessionStore();
        $candidate = $tokens[$positionalIndex];

        if ($store->exists($sessionsRoot, $candidate)) {
            return $rest;
        }

        $matchingSessionIds = array_values(array_map(
            static fn ($session): string => $session->id,
            array_filter(
                $store->all($sessionsRoot),
                static fn ($session): bool => $session->taskId === $candidate,
            ),
        ));

        if ($matchingSessionIds === []) {
            return $rest;
        }

        // all() is sorted by id ascending; ids are date-prefixed, so the last
        // match is the most recently created session for this task.
        $tokens[$positionalIndex] = $matchingSessionIds[count($matchingSessionIds) - 1];

        return array_merge([$command], $tokens);
    }

    /**
     * Defaults `recall compile --task <id>` to `--output-dir <root>/recall/<id>`
     * when the caller didn't pass one (the dependency itself defaults to the
     * current directory), matching the layout `agent-loop verify`'s
     * recall-coverage check expects: `<recall-root>/<task-id>/meta.json` with
     * `<recall-root>` defaulting to `<root>/recall`. Anything the caller
     * already passed for `--output-dir` is left alone.
     *
     * @param list<string> $rest
     *
     * @return list<string>
     */
    private function resolveRecallArgv(array $rest): array
    {
        if (($rest[0] ?? null) !== 'compile') {
            return $rest;
        }

        $taskId = null;
        $count = count($rest);

        for ($i = 1; $i < $count; ++$i) {
            $token = $rest[$i];
            if (!str_starts_with($token, '--')) {
                continue;
            }

            $name = substr($token, 2);
            $hasValue = $i + 1 < $count && !str_starts_with($rest[$i + 1], '--');
            if ($name === 'output-dir') {
                return $rest;
            }

            if ($name === 'task' && $hasValue) {
                $taskId = $rest[$i + 1];
            }

            if ($hasValue) {
                ++$i;
            }
        }

        if ($taskId === null || trim($taskId) === '') {
            return $rest;
        }

        return array_merge($rest, ['--output-dir', rtrim($this->rootPath, '/') . '/recall/' . $taskId]);
    }

    /**
     * Rebuilds an argv array for a delegated library CLI: script name at index 0,
     * the namespace's own command/arguments from index 1 onwards.
     *
     * @param list<string> $rest
     *
     * @return list<string>
     */
    private function subArgv(string $scriptName, array $rest): array
    {
        return array_merge([$scriptName], $rest);
    }

    private function printUsage(int $exitCode, string $unknownNamespace = ''): int
    {
        if ($unknownNamespace !== '') {
            fwrite(\STDERR, "Unknown command: {$unknownNamespace}\n\n");
        }

        $usage = <<<TXT
        agent-loop - unified CLI for the governed agentic-coding loop.

        Usage:
          agent-loop <namespace> <command> [options]

        Namespaces:
          board   <summary|render|lane|next-pull|ticket|context|brief|jira-sync>
                  TODO Kanban board (voku/agent-kanban). `jira-sync` needs a
                  JiraIssueProvider injected via the Dispatcher constructor.
          verify  Cross-package consistency check: tasks, board, sessions,
                  recall outputs, and the learning root (voku/agent-loop).
                  Each check skips itself when its inputs are absent. Run
                  `board:verify` for the narrower kanban-board-only check.
          learn   <validate|prepare|proposal-*|constraint-*|guidance-evaluate|finding-transition>
                  Findings, proposals, and decision history (voku/agent-learning).
          recall  <compile|log-outcome>
                  L2 meta-prompt compilation (voku/agent-recall-compiler).
          session <start|claim|checkpoint|record|close|list|show|prune>
                  Working memory: per-task session plans (voku/agent-session).
          memory  <review>
                  MEMORY.md promotion review (voku/agent-loop).
          help    Show this help.

        Run a namespace with `help` for its own command list, e.g.:
          agent-loop learn help
          agent-loop recall help

        TXT;

        if ($unknownNamespace === '') {
            echo $usage;
        } else {
            fwrite(\STDERR, $usage);
        }

        return $exitCode;
    }
}
