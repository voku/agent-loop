<?php

declare(strict_types=1);

namespace voku\AgentLoop;

use voku\AgentKanban\Cli\CliApplication;
use voku\AgentLearning\Cli as LearningCli;
use voku\AgentMap\Cli\AgentMapApplication;
use voku\AgentLoop\Init\InitCli;
use voku\AgentRecallCompiler\Review\ReviewCli as RecallReviewCli;
use voku\AgentRecallCompiler\Cli as RecallCli;
use voku\AgentSession\Cli as SessionCli;
use voku\AgentSession\SessionStore;
use voku\AgentLoop\Workflow\WorkflowCli;

/**
 * Unified entrypoint for the governed agentic-coding loop.
 *
 * Routes the first CLI argument to the matching library:
 *  - `board`  -> voku/agent-kanban (CliApplication)
 *  - `verify` -> voku/agent-loop (AgentLoopVerifier; cross-package consistency check)
 *  - `workflow` -> voku/agent-loop (plan/approve/start/status/report/close orchestration)
 *  - `map` -> voku/agent-map (PHP repository symbol map)
 *  - `board:verify` -> voku/agent-kanban (CliApplication `verify`; kanban board source only)
 *  - `learn`  -> voku/agent-learning (Cli)
 *  - `recall` -> voku/agent-recall-compiler (Cli)
 *  - `session` -> voku/agent-session (Cli)
 *  - `memory` -> voku/agent-loop (MemoryPromotionAnalyzer)
 *  - `review` -> voku/agent-recall-compiler (review reports and L2 prompts)
 *
 * Each library CLI expects the script name at argv[0] and its own command at
 * argv[1], so the namespace token is stripped and the remaining tokens are
 * re-prefixed with the script name before delegation.
 */
final class Dispatcher
{
    public function __construct(
        private readonly string $rootPath,
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
            'board' => (new CliApplication($this->rootPath))->run($this->subArgv($scriptName, $rest)),
            'verify' => (new AgentLoopVerifier($this->rootPath))->run($rest),
            'board:verify' => (new CliApplication($this->rootPath))->run($this->subArgv($scriptName, ['verify'])),
            'learn' => (new LearningCli())->run($this->subArgv($scriptName, $rest)),
            'recall' => $this->dispatchRecall($scriptName, $rest),
            'session' => $this->dispatchSession($scriptName, $rest),
            'workflow' => $this->dispatchWorkflow($scriptName, $rest),
            'map' => $this->dispatchMap($scriptName, $rest),
            'memory' => (new MemoryPromotionAnalyzer($this->rootPath))->run($rest),
            'review' => $this->dispatchReview($scriptName, $rest),
            'init' => (new InitCli($this->rootPath))->run($rest),
            'help', '--help', '-h', '' => $this->printUsage(0),
            default => $this->printUsage(1, $namespace),
        };
    }

    /**
     * Resolves `session record|checkpoint|close|claim|show <id>` and
     * `session brief <action> <id>`, then delegates
     * to voku/agent-session, unless task-id resolution reports an ambiguous
     * match (see resolveSessionArgv()), in which case the error it already
     * printed is the only output and the namespace is never delegated.
     *
     * @param list<string> $rest
     */
    private function dispatchSession(string $scriptName, array $rest): int
    {
        $resolved = $this->resolveSessionArgv($rest);
        if ($resolved === null) {
            return 1;
        }

        return (new SessionCli())->run($this->subArgv($scriptName, $resolved));
    }

    /**
     * @param list<string> $rest
     */
    private function dispatchWorkflow(string $scriptName, array $rest): int
    {
        return (new WorkflowCli(
            $this->rootPath,
            fn (array $sessionRest): int => $this->dispatchSession($scriptName, $this->resolveWorkflowSessionRoot($sessionRest)),
            fn (array $recallRest): int => $this->dispatchRecall($scriptName, array_values($recallRest)),
            fn (array $verifyRest): int => (new AgentLoopVerifier($this->rootPath))->run(array_values($verifyRest)),
        ))->run($rest);
    }

    /**
     * Workflow orchestration is rooted in this Dispatcher even when a host
     * calls it from another current working directory. Direct `session`
     * commands retain the package's normal current-directory default.
     *
     * @param list<string> $rest
     * @return list<string>
     */
    private function resolveWorkflowSessionRoot(array $rest): array
    {
        if ($this->hasOption($rest, 'root')) {
            return $rest;
        }

        return array_merge($rest, ['--root', rtrim($this->rootPath, '/') . '/session_plan']);
    }

    /**
     * Delegates review commands to voku/agent-recall-compiler, where the L2
     * prompt/review feature lives. When the caller does not pass --output-dir,
     * default to agent-loop's recall/<task-id> layout so the standard workflow
     * stays: recall compile -> review blindspots/code.
     *
     * @param list<string> $rest
     */
    private function dispatchReview(string $scriptName, array $rest): int
    {
        return (new RecallReviewCli($this->rootPath))->run($this->subArgv($scriptName, $this->resolveReviewArgv($rest)));
    }

    /**
     * Delegates repository symbol-map commands to voku/agent-map while
     * preserving agent-loop's root path for programmatic hosts. Callers can
     * still override every default with normal agent-map options.
     *
     * @param list<string> $rest
     */
    private function dispatchMap(string $scriptName, array $rest): int
    {
        return (new AgentMapApplication())->run($this->subArgv($scriptName, $this->resolveMapArgv($rest)));
    }

    /**
     * @param list<string> $rest
     *
     * @return list<string>
     */
    private function resolveMapArgv(array $rest): array
    {
        $command = $rest[0] ?? 'help';
        if (in_array($command, ['help', '--help', '-h', ''], true)) {
            return $rest;
        }

        if ($command === 'build') {
            if (!$this->hasOption($rest, 'root')) {
                $rest[] = '--root=' . rtrim($this->rootPath, '/');
            }

            if (!$this->hasOption($rest, 'out')) {
                $rest[] = '--out=' . $this->defaultMapIndex();
            }

            return $rest;
        }

        if (!$this->hasOption($rest, 'index')) {
            $rest[] = '--index=' . $this->defaultMapIndex();
        }

        return $rest;
    }

    /**
     * @param list<string> $tokens
     */
    private function hasOption(array $tokens, string $name): bool
    {
        foreach ($tokens as $token) {
            if ($token === '--' . $name || str_starts_with($token, '--' . $name . '=')) {
                return true;
            }
        }

        return false;
    }

    private function defaultMapIndex(): string
    {
        return rtrim($this->rootPath, '/') . '/.agent-map/php-symbols.json';
    }

    /**
     * @param list<string> $rest
     *
     * @return list<string>
     */
    private function resolveReviewArgv(array $rest): array
    {
        $command = $rest[0] ?? null;
        if (!in_array($command, ['blindspots', 'code'], true)) {
            return $rest;
        }

        foreach ($rest as $token) {
            if ($token === '--output-dir' || str_starts_with($token, '--output-dir=')) {
                return $rest;
            }
        }

        $taskId = $rest[1] ?? '';
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $taskId) || str_contains($taskId, '..')) {
            return $rest;
        }

        return array_merge($rest, ['--output-dir', rtrim($this->rootPath, '/') . '/recall/' . $taskId]);
    }

    /**
     * Delegates to voku/agent-recall-compiler, then -- only for a successful
     * `recall compile` -- appends a note that the compiled artifacts are
     * written for review or harness ingestion, not consumed automatically by
     * anything in this stack. This never touches the dependency's own
     * output (which it writes via fwrite(STDOUT, ...), not echo); it only
     * appends after delegation returns.
     *
     * @param list<string> $rest
     */
    private function dispatchRecall(string $scriptName, array $rest): int
    {
        $exit = (new RecallCli())->run($this->subArgv($scriptName, $this->resolveRecallArgv($rest)));

        if ($exit === 0 && ($rest[0] ?? null) === 'compile') {
            echo "\n[NOTE] Recall artifacts were written for review or harness ingestion.\n";
            echo "[ACTION REQUIRED] Pass system.md / validation-plan.md into your agent workflow manually unless your harness consumes them automatically.\n";
        }

        return $exit;
    }

    /**
     * Lets `session record|checkpoint|close|claim|show` accept the task id
     * passed to `session start --task` in place of the generated session id
     * (e.g. `2026-06-20-abc-123`), which is otherwise easy to confuse with
     * the task id and fails with a bare "Session not found". Resolution is a
     * read-only lookup against the existing session_plan/ files at request
     * time, not new state of its own.
     *
     * A task id can match more than one session (e.g. a dropped attempt
     * followed by a fresh one for the same task). Resolution rules, in order:
     *  1. an explicit, already-valid session id is left unchanged.
     *  2. zero matches: left unchanged, so the upstream "Session not found"
     *     failure still applies.
     *  3. exactly one match: resolved.
     *  4. multiple matches with exactly one non-closed ("active") session:
     *     the active one is resolved.
     *  5. multiple matches with zero or more-than-one active session: this
     *     is ambiguous, so resolution fails cleanly (an [ERROR] is printed
     *     and null is returned) instead of guessing which one the caller
     *     meant.
     *
     * @param list<string> $rest
     *
     * @return list<string>|null null when the task id matched more than one
     *                            session and the caller must pass the
     *                            generated session id explicitly instead
     */
    private function resolveSessionArgv(array $rest): ?array
    {
        $command = $rest[0] ?? null;
        if (!in_array($command, ['claim', 'checkpoint', 'record', 'close', 'show', 'brief', 'validation', 'learning'], true)) {
            return $rest;
        }

        $tokens = array_slice($rest, 1);
        $firstPositionalIndex = 0;
        if (in_array($command, ['brief', 'validation', 'learning'], true)) {
            $action = $tokens[0] ?? null;
            $actions = match ($command) {
                'brief' => ['create', 'revise', 'approve', 'show'],
                'validation' => ['record'],
                'learning' => ['decide'],
            };
            if (!in_array($action, $actions, true)) {
                return $rest;
            }
            $firstPositionalIndex = 1;
        }

        $sessionsRoot = null;
        $positionalIndex = null;
        $count = count($tokens);

        for ($i = $firstPositionalIndex; $i < $count; ++$i) {
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

        $matchingSessions = array_values(array_filter(
            $store->all($sessionsRoot),
            static fn ($session): bool => $session->taskId === $candidate,
        ));

        if ($matchingSessions === []) {
            return $rest;
        }

        if (count($matchingSessions) === 1) {
            $tokens[$positionalIndex] = $matchingSessions[0]->id;

            return array_merge([$command], $tokens);
        }

        $activeSessions = array_values(array_filter(
            $matchingSessions,
            static fn ($session): bool => !$session->status->isClosed(),
        ));

        if (count($activeSessions) !== 1) {
            echo "[ERROR] Multiple sessions found for task {$candidate}. Pass the generated session id explicitly.\n";

            return null;
        }

        $tokens[$positionalIndex] = $activeSessions[0]->id;

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
          board   <summary|render|lane|next-pull|card|external-sync>
                  TODO Kanban board (voku/agent-kanban). `card show|create|
                  update|move|claim|release|archive|restore` operate on a
                  single card; `external-sync` needs
                  --provider-class=<FQCN> implementing ExternalIssueProvider.
          verify  Cross-package consistency check: tasks, board, sessions,
                  recall outputs, and the learning root (voku/agent-loop).
                  Each check skips itself when its inputs are absent. Run
                  `board:verify` for the narrower kanban-board-only check.
          learn   <validate|prepare|proposal-*|constraint-*|guidance-evaluate|finding-transition>
                  Findings, proposals, and decision history (voku/agent-learning).
          recall  <compile|log-outcome>
                  L2 meta-prompt compilation (voku/agent-recall-compiler).
          session <start|claim|checkpoint|record|close|list|show|brief|validation|learning|prune>
                  Working memory: per-task session plans (voku/agent-session).
          map     <build|query|file|stale|summary|changed|related|stats>
                  Compact PHP repository symbol map (voku/agent-map).
          memory  <review>
                  MEMORY.md promotion review (voku/agent-loop).
          workflow
                  Gated workflow orchestration commands.
          review  <blindspots|code>
                  Deterministic review helpers from voku/agent-recall-compiler.
          init    Setup, diagnostics, install plans, and repo-managed agent asset validation.
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
