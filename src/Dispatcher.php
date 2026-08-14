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
 * Delegated package CLIs keep their own commands while ProjectLayout owns the
 * repository-local workflow-state defaults shared by the umbrella commands.
 */
final class Dispatcher
{
    public function __construct(
        private readonly string $rootPath,
    ) {
    }

    /** @param list<string> $argv */
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
            'board' => (new CliApplication($this->layout()->boardRoot()))->run($this->subArgv($scriptName, $rest)),
            'verify' => (new AgentLoopVerifier($this->rootPath))->run($rest),
            'board:verify' => (new CliApplication($this->layout()->boardRoot()))->run($this->subArgv($scriptName, ['verify'])),
            'learn' => $this->dispatchLearn($scriptName, $rest),
            'recall' => $this->dispatchRecall($scriptName, $rest),
            'prompt' => $this->dispatchRecall($scriptName, ['prompt', ...$rest]),
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

    /** @param list<string> $rest */
    private function dispatchLearn(string $scriptName, array $rest): int
    {
        if (!in_array($rest[0] ?? 'help', ['help', '--help', '-h', ''], true) && !$this->hasOption($rest, 'root')) {
            $rest[] = '--root';
            $rest[] = $this->layout()->learningRoot();
        }

        return (new LearningCli())->run($this->subArgv($scriptName, $rest));
    }

    /** @param list<string> $rest */
    private function dispatchSession(string $scriptName, array $rest): int
    {
        $resolved = $this->resolveSessionArgv($rest);
        if ($resolved === null) {
            return 1;
        }

        if (!$this->hasOption($resolved, 'root')) {
            $resolved[] = '--root';
            $resolved[] = $this->layout()->sessionsRoot();
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

    /** @param list<string> $rest */
    private function dispatchReview(string $scriptName, array $rest): int
    {
        return (new RecallReviewCli($this->rootPath))->run($this->subArgv($scriptName, $this->resolveReviewArgv($rest)));
    }

    /**
     * Preserve agent-map's temporal/discovery/general routing while keeping all
     * derived state below the shared workspace root.
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

        // The derived search index is what turns retrieval into ranked Recall
        // evidence, and `workflow approve` reads it from the path ProjectLayout
        // owns. Without this, agent-map falls back to its own `.agent-map/`
        // default: the index is built somewhere nothing reads, approve compiles
        // Recall with no ranked facts, and the decisive symbol silently never
        // reaches the governed context.
        if (in_array($command, ['search-index', 'search'], true) && !$this->hasOption($rest, 'database')) {
            $rest[] = '--database=' . $this->defaultMapSearchIndex();
        }

        return $rest;
    }

    /**

     * @param list<string> $rest

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

    /** @param list<string> $tokens */
    private function optionValue(array $tokens, string $name): ?string
    {
        $prefix = '--' . $name . '=';
        $count = count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            if (str_starts_with($tokens[$i], $prefix)) {
                $value = substr($tokens[$i], strlen($prefix));

                return $value !== '' ? $value : null;
            }
            if ($tokens[$i] !== '--' . $name) {
                continue;
            }

            $value = $tokens[$i + 1] ?? null;

            return is_string($value) && $value !== '' && !str_starts_with($value, '--') ? $value : null;
        }

        return null;
    }

    private function defaultMapIndex(): string
    {
        return $this->layout()->mapIndex();
    }

    private function defaultMapSearchIndex(): string
    {
        return $this->layout()->mapSearchIndex();
    }

    private function defaultHistoryDatabase(): string
    {
        return $this->layout()->mapHistoryDatabase();
    }

    /**

     * @param list<string> $rest

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
        if (!$this->isSafeTaskId($taskId)) {
            return $rest;
        }

        return array_merge($rest, ['--output-dir', RecallOutputRoot::resolve($this->rootPath) . '/' . $taskId]);
    }

    /** @param list<string> $rest */
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
     * Resolve task IDs accepted as ergonomic aliases by Session commands to
     * the one active Session id. Durable Contract/Learning operations are not
     * Session commands and therefore have no compatibility aliases here.
     *
     * @param list<string> $rest
     * @return list<string>|null
     */
    private function resolveSessionArgv(array $rest): ?array
    {
        $command = $rest[0] ?? null;
        $tokens = array_slice($rest, 1);
        $positionalIndex = match ($command) {
            'record', 'checkpoint', 'close', 'claim', 'show' => 0,
            'validation' => 1,
            default => null,
        };
        if ($positionalIndex === null || !isset($tokens[$positionalIndex])) {
            return $rest;
        }

        $candidate = $tokens[$positionalIndex];
        if (str_starts_with($candidate, '--')) {
            return $rest;
        }

        $sessionRoot = $this->optionValue($rest, 'root') ?? $this->layout()->sessionsRoot();
        if (!is_dir($sessionRoot)) {
            return $rest;
        }

        $store = new SessionStore();
        try {
            $store->load($sessionRoot, $candidate);

            return $rest;
        } catch (\RuntimeException) {
            // Resolve the candidate as a task id below.
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

     * @param list<string> $rest

     * @return list<string>

     */
    private function resolveRecallArgv(array $rest): array
    {
        $command = $rest[0] ?? null;
        if (in_array($command, ['compile', 'log-outcome'], true) && !$this->hasOption($rest, 'root')) {
            $rest[] = '--root';
            $rest[] = $this->layout()->learningRoot();
        }

        if ($command !== 'compile') {
            return $rest;
        }

        if ($this->hasOption($rest, 'output-dir')) {
            return $rest;
        }

        $taskId = $this->optionValue($rest, 'task');
        if ($taskId === null || !$this->isSafeTaskId($taskId)) {
            return $rest;
        }

        return array_merge($rest, ['--output-dir', RecallOutputRoot::resolve($this->rootPath) . '/' . $taskId]);
    }

    private function isSafeTaskId(string $taskId): bool
    {
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $taskId) === 1
            && !str_contains($taskId, '..');
    }

    /**

     * @param list<string> $rest

     * @return list<string>

     */
    private function subArgv(string $scriptName, array $rest): array
    {
        return array_merge([$scriptName], $rest);
    }

    private function layout(): ProjectLayout
    {
        return new ProjectLayout($this->rootPath);
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
                  TODO Kanban board (voku/agent-kanban). Use `board:verify` for
                  the narrower kanban-board-only consistency check.
          verify  Cross-package consistency check for Contract, Run, Session,
                  Recall, board and Learning owner boundaries.
          board:verify
                  Verify only the kanban board projection.
          learn   <validate|prepare|proposal-*|constraint-*|guidance-evaluate|finding-transition>
                  Durable findings, proposals, guidance and history (voku/agent-learning).
          recall  <compile|log-outcome>
                  Deterministic context/replay compilation (voku/agent-recall-compiler).
          prompt  <future-work|guidance-gaps>
                  Explicit Recall-owned prompt helpers; `guidance-gaps` is opt-in and never a default workflow stage.
          session <start|claim|checkpoint|record|close|list|show|validation|prune>
                  Pruneable per-Run working memory and raw validation observations (voku/agent-session).
          map     <build|refresh|search-index|search|discover|rank|impact|history|query|file|stale|
                  summary|changed|related|stats|scope|callers|callees|context>
                  Deterministic PHP repository map, architecture discovery, and
                  temporal evidence (voku/agent-map).
          memory  <validate|review>
                  MEMORY.md structure validation and promotion review (voku/agent-loop).
          workflow
                  Durable governed workflow orchestration commands.
          review  <blindspots|code>
                  Deterministic review helpers from voku/agent-recall-compiler.
          init    Setup, diagnostics, install plans, and repo-managed agent asset validation.
          help    Show this help.

        Repository layout:
          Workflow state lives below `.agent-loop/`; the project/source root remains unchanged.

        Run a namespace with `help` for its own command list, e.g.:
          agent-loop edit help
          agent-loop learn help
          agent-loop recall help
          agent-loop prompt guidance-gaps

        TXT;

        if ($unknownNamespace === '') {
            echo $usage;
        } else {
            fwrite(STDERR, $usage);
        }

        return $exitCode;
    }
}
