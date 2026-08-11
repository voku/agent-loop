<?php

declare(strict_types=1);

namespace voku\AgentLoop;

use voku\AgentKanban\Cli\CliApplication;
use voku\AgentLearning\Cli as LearningCli;
use voku\AgentMap\Cli\AgentMapApplication;
use voku\AgentMap\Cli\DiscoveryCliApplication;
use voku\AgentMap\Cli\TemporalCliApplication;
use voku\AgentLoop\Edit\EditCommand;
use voku\AgentLoop\GitHooks\GitHooksCli;
use voku\AgentLoop\Init\InitCli;
use voku\AgentLoop\Workflow\WorkflowCli;
use voku\AgentRecallCompiler\Cli as RecallCli;
use voku\AgentRecallCompiler\Review\ReviewCli as RecallReviewCli;
use voku\AgentSession\Cli as SessionCli;
use voku\AgentSession\SessionStore;

/**
 * Unified entrypoint for the governed agentic-coding loop.
 *
 * Routes the first CLI argument to the matching library:
 *  - `board`  -> voku/agent-kanban (CliApplication)
 *  - `verify` -> voku/agent-loop (AgentLoopVerifier; cross-package consistency check)
 *  - `workflow` -> voku/agent-loop (plan/approve/start/status/report/close orchestration)
 *  - `map` -> voku/agent-map (PHP repository symbol map)
 *  - `edit` -> voku/agent-loop (target-aware map + recall + runner orchestration)
 *  - `board:verify` -> voku/agent-kanban (CliApplication `verify`; kanban board source only)
 *  - `learn`  -> voku/agent-learning (Cli)
 *  - `recall` -> voku/agent-recall-compiler (Cli)
 *  - `session` -> voku/agent-session (Cli)
 *  - `memory` -> voku/agent-loop (MemoryPromotionAnalyzer)
 *  - `review` -> voku/agent-recall-compiler (review reports and L2 prompts)
 *
 * Each delegated library CLI expects the script name at argv[0] and its own
 * command at argv[1], so those namespaces are re-prefixed before delegation.
 * Workflow orchestration itself uses focused-package PHP APIs where they exist.
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
        if (MemoryLimit::shouldRaise((string) ini_get('memory_limit'))) {
            ini_set('memory_limit', MemoryLimit::MINIMUM);
        }

        $scriptName = $argv[0] ?? 'agent-loop';
        $namespace = $argv[1] ?? 'help';
        $rest = array_slice($argv, 2);

        return match ($namespace) {
            'edit' => (new EditCommand($this->rootPath))->run($rest),
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
            'githooks' => (new GitHooksCli($this->rootPath))->run($rest),
            'help', '--help', '-h', '' => $this->printUsage(0),
            default => $this->printUsage(1, $namespace),
        };
    }

    /**
     * Resolves `session record|checkpoint|close|claim|show <id>` and
     * `session brief <action> <id>`, then delegates to voku/agent-session,
     * unless task-id resolution reports an ambiguous match.
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

    /** @param list<string> $rest */
    private function dispatchWorkflow(string $scriptName, array $rest): int
    {
        return (new WorkflowCli(
            $this->rootPath,
            fn (array $recallRest): int => $this->dispatchRecall($scriptName, $recallRest),
        ))->run($rest);
    }

    /**
     * Delegates review commands to voku/agent-recall-compiler, where the L2
     * prompt/review feature lives. When the caller does not pass --output-dir,
     * use the same resolved recall root as `recall compile` so the standard
     * workflow stays: recall compile -> review blindspots/code.
     *
     * @param list<string> $rest
     */
    private function dispatchReview(string $scriptName, array $rest): int
    {
        return (new RecallReviewCli($this->rootPath))->run($this->subArgv($scriptName, $this->resolveReviewArgv($rest)));
    }

    /**
     * Delegates repository map commands to the same temporal/discovery/general
     * router chain used by agent-map's binary while preserving agent-loop's
     * root, map-index, and temporal-history defaults.
     *
     * @param list<string> $rest
     */
    private function dispatchMap(string $scriptName, array $rest): int
    {
        $argv = $this->subArgv($scriptName, $this->resolveMapArgv($rest));
        $temporal = new TemporalCliApplication();
        if ($temporal->supports($argv)) {
            return $temporal->run($argv);
        }

        $discovery = new DiscoveryCliApplication();
        if ($discovery->supports($argv)) {
            return $discovery->run($argv);
        }

        $status = (new AgentMapApplication())->run($argv);
        if ($temporal->shouldAppendToGeneralHelp($argv)) {
            echo $temporal->helpOverview();
        }
        if ($discovery->shouldAppendToGeneralHelp($argv)) {
            echo $discovery->helpOverview();
        }

        return $status;
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

        if ($command === 'history') {
            return $this->resolveHistoryArgv($rest);
        }

        if ($command === 'build' || $command === 'refresh') {
            if (!$this->hasOption($rest, 'root')) {
                $rest[] = '--root=' . rtrim($this->rootPath, '/');
            }

            if (!$this->hasOption($rest, 'out')) {
                $rest[] = '--out=' . $this->defaultMapIndex();
            }
        }

        if ($command !== 'build' && !$this->hasOption($rest, 'index')) {
            $rest[] = '--index=' . $this->defaultMapIndex();
        }

        return $rest;
    }

    /**
     * `history diff` compares explicit maps and needs no defaults. Coupling and
     * claims consume the current map plus Git history. Observe writes a compact
     * snapshot at a clean Git checkpoint, while show is deliberately read-only
     * and therefore receives only the history database default.
     *
     * @param list<string> $rest
     *
     * @return list<string>
     */
    private function resolveHistoryArgv(array $rest): array
    {
        $subcommand = $rest[1] ?? 'help';

        if (in_array($subcommand, ['coupling', 'claims'], true)) {
            if (!$this->hasOption($rest, 'index')) {
                $rest[] = '--index=' . $this->defaultMapIndex();
            }
            if (!$this->hasOption($rest, 'root')) {
                $rest[] = '--root=' . rtrim($this->rootPath, '/');
            }
        }

        if ($subcommand === 'observe' && !$this->hasOption($rest, 'index')) {
            $rest[] = '--index=' . $this->defaultMapIndex();
        }

        if (in_array($subcommand, ['observe', 'show'], true) && !$this->hasOption($rest, 'database')) {
            $rest[] = '--database=' . $this->defaultHistoryDatabase();
        }

        return $rest;
    }

    /** @param list<string> $tokens */
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

    private function defaultHistoryDatabase(): string
    {
        return rtrim($this->rootPath, '/') . '/.agent-map/history.sqlite';
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

        return array_merge($rest, ['--output-dir', RecallOutputRoot::resolve($this->rootPath) . '/' . $taskId]);
    }

    /**
     * Delegates to voku/agent-recall-compiler, then -- only for a successful
     * `recall compile` -- appends a note that the compiled artifacts are
     * written for review or harness ingestion, not consumed automatically by
     * anything in this stack.
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
     * Resolves task IDs accepted as ergonomic aliases by session commands to
     * the one active generated session id. Explicit session ids pass through.
     *
     * @param list<string> $rest
     *
     * @return list<string>|null
     */
    private function resolveSessionArgv(array $rest): ?array
    {
        $command = $rest[0] ?? null;
        $tokens = array_slice($rest, 1);
        $positionalIndex = match ($command) {
            'record', 'checkpoint', 'close', 'claim', 'show' => 0,
            'brief', 'validation', 'learning' => 1,
            default => null,
        };
        if ($positionalIndex === null || !isset($tokens[$positionalIndex])) {
            return $rest;
        }

        $candidate = $tokens[$positionalIndex];
        if (str_starts_with($candidate, '--')) {
            return $rest;
        }

        $sessionRoot = rtrim($this->rootPath, '/') . '/session_plan';
        if (!is_dir($sessionRoot)) {
            return $rest;
        }

        $store = new SessionStore();
        try {
            $store->load($sessionRoot, $candidate);

            return $rest;
        } catch (\RuntimeException) {
            // Keep resolving the candidate as a task id below.
        }

        $matchingSessions = array_values(array_filter(
            $store->all($sessionRoot),
            static fn ($session): bool => $session->taskId === $candidate,
        ));

        if ($matchingSessions === []) {
            return $rest;
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
     * Defaults `recall compile --task <id>` to `--output-dir <recall-root>/<id>`
     * when the caller didn't pass one.
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

        return array_merge($rest, ['--output-dir', RecallOutputRoot::resolve($this->rootPath) . '/' . $taskId]);
    }

    /**
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
            fwrite(STDERR, "Unknown command: {$unknownNamespace}\n\n");
        }

        $usage = <<<TXT
        agent-loop - unified CLI for the governed agentic-coding loop.

        Usage:
          agent-loop edit CLASS::METHOD [options] -- INSTRUCTION
          agent-loop <namespace> <command> [options]

        Namespaces:
          edit    CLASS::METHOD [options] -- INSTRUCTION
                  Build or refresh the semantic map, compile target-aware recall,
                  and prepare or run one auditable edit execution bundle.
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
          map     <build|refresh|discover|rank|impact|history|query|file|stale|summary|changed|related|
                  stats|scope|callers|callees|context>
                  Deterministic PHP repository map, architecture discovery, and
                  temporal evidence (voku/agent-map). `build` writes the whole
                  scope; `refresh` incrementally patches changed or new files.
          memory  <validate|review>
                  MEMORY.md structure validation and promotion review (voku/agent-loop).
          workflow
                  Gated workflow orchestration commands.
          review  <blindspots|code>
                  Deterministic review helpers from voku/agent-recall-compiler.
          init    Setup, diagnostics, install plans, and repo-managed agent asset validation.
          help    Show this help.

        Run a namespace with `help` for its own command list, e.g.:
          agent-loop edit help
          agent-loop learn help
          agent-loop recall help

        TXT;

        if ($unknownNamespace === '') {
            echo $usage;
        } else {
            fwrite(STDERR, $usage);
        }

        return $exitCode;
    }
}
