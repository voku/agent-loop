<?php

declare(strict_types=1);

namespace voku\AgentLoop;

use voku\AgentKanban\JiraIssueProvider;
use voku\AgentKanban\TodoBoardCli;
use voku\AgentKanban\TodoBoardVerifier;
use voku\AgentLearning\Cli as LearningCli;
use voku\AgentRecallCompiler\Cli as RecallCli;
use voku\AgentSession\Cli as SessionCli;

/**
 * Unified entrypoint for the governed agentic-coding loop.
 *
 * Routes the first CLI argument to the matching library:
 *  - `board`  -> voku/agent-kanban (TodoBoardCli)
 *  - `verify` -> voku/agent-kanban (TodoBoardVerifier)
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
            'board' => (new TodoBoardCli($this->rootPath, $this->jiraIssueProvider, $this->projectPrefix))
                ->run($this->subArgv($scriptName, $rest)),
            'verify', 'board:verify' => (new TodoBoardVerifier($this->rootPath, $this->projectPrefix))->run(),
            'learn' => (new LearningCli())->run($this->subArgv($scriptName, $rest)),
            'recall' => (new RecallCli())->run($this->subArgv($scriptName, $rest)),
            'session' => (new SessionCli())->run($this->subArgv($scriptName, $rest)),
            'memory' => (new MemoryPromotionAnalyzer($this->rootPath))->run($rest),
            'help', '--help', '-h', '' => $this->printUsage(0),
            default => $this->printUsage(1, $namespace),
        };
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
          verify  Verify the split TODO board source (voku/agent-kanban).
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
