<?php

declare(strict_types=1);

namespace voku\AgentLoop\Cli;

/**
 * Authoritative typed catalog of Dispatcher-level command identities and metadata.
 */
final class CommandCatalog
{
    /**
     * @var array<string, CommandDescriptor>|null
     */
    private static ?array $descriptors = null;

    /**
     * @return array<string, CommandDescriptor>
     */
    public static function all(): array
    {
        if (self::$descriptors !== null) {
            return self::$descriptors;
        }

        /** @var list<CommandDescriptor> $list */
        $list = [
            new CommandDescriptor(
                id: CommandId::Quick,
                group: CommandGroup::Workflow,
                owner: CommandOwner::Loop,
                summary: 'Fast-path micro-task flow: initiate, approve, and enter a bounded surgical task within up to 2 target files in one command.',
                usage: 'agent-loop quick [TASK-ID] "<goal>" --file=<path> [--verify="<cmd>"]',
            ),
            new CommandDescriptor(
                id: CommandId::Enter,
                group: CommandGroup::Workflow,
                owner: CommandOwner::Loop,
                summary: 'Prepare deterministic post-approval workflow state and project bounded context before host mutation.',
                usage: 'agent-loop enter <task-id> [options]',
            ),
            new CommandDescriptor(
                id: CommandId::Finish,
                group: CommandGroup::Workflow,
                owner: CommandOwner::Loop,
                summary: 'Reconcile deterministic validation/review evidence, bind judgments, and close when canonical policy permits.',
                usage: 'agent-loop finish <task-id> [options]',
            ),
            new CommandDescriptor(
                id: CommandId::Repair,
                group: CommandGroup::Workflow,
                owner: CommandOwner::Loop,
                summary: 'Inspect the latest validation failure and project a bounded auto-repair instruction.',
                usage: 'agent-loop repair <task-id> [--max-attempts=2]',
            ),
            new CommandDescriptor(
                id: CommandId::Pipeline,
                group: CommandGroup::Workflow,
                owner: CommandOwner::Loop,
                summary: 'Turnkey multi-stage execution runner for governed task profiles.',
                usage: 'agent-loop pipeline <status|stage|run|submit> <task-id> [options]',
            ),
            new CommandDescriptor(
                id: CommandId::Edit,
                group: CommandGroup::Workflow,
                owner: CommandOwner::Loop,
                summary: 'Build or refresh the semantic map, compile target-aware recall, and prepare or run one auditable edit execution bundle.',
                usage: 'agent-loop edit CLASS::METHOD [options] -- INSTRUCTION',
            ),
            new CommandDescriptor(
                id: CommandId::Board,
                group: CommandGroup::Inspection,
                owner: CommandOwner::Kanban,
                summary: 'TODO Kanban board (voku/agent-kanban). Run `agent-loop board help` for subcommands.',
                usage: 'agent-loop board <command> [options]',
            ),
            new CommandDescriptor(
                id: CommandId::Verify,
                group: CommandGroup::Inspection,
                owner: CommandOwner::Loop,
                summary: 'Cross-package consistency check for Contract, Run, Session, Recall, board and Learning owner boundaries.',
                usage: 'agent-loop verify [options]',
            ),
            new CommandDescriptor(
                id: CommandId::BoardVerify,
                group: CommandGroup::Inspection,
                owner: CommandOwner::Kanban,
                summary: 'Verify only the kanban board projection (voku/agent-kanban).',
                usage: 'agent-loop board:verify',
            ),
            new CommandDescriptor(
                id: CommandId::Learn,
                group: CommandGroup::EvidenceLearning,
                owner: CommandOwner::Learning,
                summary: 'Durable findings, proposals, guidance and history (voku/agent-learning). Run `agent-loop learn help` for subcommands.',
                usage: 'agent-loop learn <command> [options]',
            ),
            new CommandDescriptor(
                id: CommandId::Recall,
                group: CommandGroup::Inspection,
                owner: CommandOwner::Recall,
                summary: 'Deterministic context/replay compilation (voku/agent-recall-compiler). Run `agent-loop recall help` for subcommands.',
                usage: 'agent-loop recall <command> [options]',
            ),
            new CommandDescriptor(
                id: CommandId::Prompt,
                group: CommandGroup::Inspection,
                owner: CommandOwner::Recall,
                summary: 'Explicit Recall-owned prompt helpers (voku/agent-recall-compiler). Run `agent-loop prompt help` for subcommands.',
                usage: 'agent-loop prompt <command> [options]',
            ),
            new CommandDescriptor(
                id: CommandId::Session,
                group: CommandGroup::EvidenceLearning,
                owner: CommandOwner::Session,
                summary: 'Pruneable per-Run working memory and raw validation observations (voku/agent-session). Run `agent-loop session help` for subcommands.',
                usage: 'agent-loop session <command> [options]',
            ),
            new CommandDescriptor(
                id: CommandId::Map,
                group: CommandGroup::Inspection,
                owner: CommandOwner::Map,
                summary: 'Deterministic PHP repository map, search-index, and code navigation (voku/agent-map). Run `agent-loop map help` for subcommands.',
                usage: 'agent-loop map <query|build|search-index|refresh> [options]',
            ),
            new CommandDescriptor(
                id: CommandId::Memory,
                group: CommandGroup::EvidenceLearning,
                owner: CommandOwner::Loop,
                summary: 'MEMORY.md structure validation and promotion review (voku/agent-loop).',
                usage: 'agent-loop memory <validate|review> [options]',
            ),
            new CommandDescriptor(
                id: CommandId::Workflow,
                group: CommandGroup::Workflow,
                owner: CommandOwner::Loop,
                summary: 'Durable governed workflow orchestration commands.',
                usage: 'agent-loop workflow <command> [options]',
            ),
            new CommandDescriptor(
                id: CommandId::Review,
                group: CommandGroup::EvidenceLearning,
                owner: CommandOwner::Recall,
                summary: 'Deterministic review helpers from voku/agent-recall-compiler.',
                usage: 'agent-loop review <blindspots|code> [options]',
            ),
            new CommandDescriptor(
                id: CommandId::Init,
                group: CommandGroup::SetupOps,
                owner: CommandOwner::Loop,
                summary: 'Repository setup, diagnostics, install plans, and repo-managed agent asset validation.',
                usage: 'agent-loop init <command> [options]',
            ),
            new CommandDescriptor(
                id: CommandId::Githooks,
                group: CommandGroup::SetupOps,
                owner: CommandOwner::Loop,
                summary: 'Package-owned Git hook entrypoints and commit validation.',
                usage: 'agent-loop githooks <pre-commit|commit-msg> [options]',
            ),
            new CommandDescriptor(
                id: CommandId::Commands,
                group: CommandGroup::SetupOps,
                owner: CommandOwner::Loop,
                summary: 'Discover available commands and capabilities in machine-readable or human format.',
                usage: 'agent-loop commands [--format=text|json|toon]',
            ),
            new CommandDescriptor(
                id: CommandId::Help,
                group: CommandGroup::SetupOps,
                owner: CommandOwner::Loop,
                summary: 'Show this help overview.',
                usage: 'agent-loop help',
            ),
        ];

        $map = [];
        foreach ($list as $descriptor) {
            $map[$descriptor->id->value] = $descriptor;
        }

        self::$descriptors = $map;

        return self::$descriptors;
    }

    public static function find(CommandId $id): ?CommandDescriptor
    {
        return self::all()[$id->value] ?? null;
    }

    public static function get(CommandId $id): CommandDescriptor
    {
        $descriptor = self::find($id);
        if ($descriptor === null) {
            throw new \OutOfBoundsException("CommandDescriptor not found for ID: {$id->value}");
        }

        return $descriptor;
    }

    /**
     * @return list<CommandDescriptor>
     */
    public static function byGroup(CommandGroup $group): array
    {
        $result = [];
        foreach (self::all() as $descriptor) {
            if ($descriptor->group === $group) {
                $result[] = $descriptor;
            }
        }

        return $result;
    }

    /**
     * Renders the canonical concise top-level usage string for `agent-loop help`.
     */
    public static function renderUsage(): string
    {
        $out = "agent-loop - unified CLI for the governed agentic-coding loop.\n\n";
        $out .= "Usage:\n";
        $out .= "  agent-loop quick [TASK-ID] \"<goal>\" --file=<path> [--verify=\"<cmd>\"]\n";
        $out .= "  agent-loop enter <task-id> [options]\n";
        $out .= "  agent-loop finish <task-id> [options]\n";
        $out .= "  agent-loop edit CLASS::METHOD [options] -- INSTRUCTION\n";
        $out .= "  agent-loop <namespace> <command> [options]\n\n";
        $out .= "Namespaces:\n";

        foreach (self::all() as $descriptor) {
            $id = $descriptor->id->value;
            $summary = $descriptor->summary;
            $usage = $descriptor->usage;

            // Strip the leading 'agent-loop <id> ' if present to format like the original help
            $prefix = 'agent-loop ' . $id;
            $subUsage = '';
            if ($usage !== null && str_starts_with($usage, $prefix)) {
                $subUsage = trim(substr($usage, strlen($prefix)));
            }

            if ($subUsage !== '') {
                $out .= sprintf("  %-8s  %s\n", $id, $subUsage);
                $out .= sprintf("          %s\n", $summary);
            } else {
                if (strlen($id) >= 8) {
                    $out .= sprintf("  %s\n          %s\n", $id, $summary);
                } else {
                    $out .= sprintf("  %-8s  %s\n", $id, $summary);
                }
            }
        }

        $out .= "\nRepository layout:\n";
        $out .= "  Workflow state lives below `.agent-loop/`; the project/source root remains unchanged.\n\n";
        $out .= "Run a namespace with `help` for its own command list, e.g.:\n";
        $out .= "  agent-loop edit help\n";
        $out .= "  agent-loop learn help\n";
        $out .= "  agent-loop recall help\n";
        $out .= "  agent-loop prompt guidance-gaps\n";

        return $out;
    }

    /**
     * Renders grouped human-readable discovery output.
     */
    public static function renderGrouped(): string
    {
        $out = "agent-loop commands:\n";

        foreach (CommandGroup::cases() as $group) {
            $commands = self::byGroup($group);
            if ($commands === []) {
                continue;
            }

            $out .= "\n" . $group->title() . ":\n";
            foreach ($commands as $cmd) {
                $out .= sprintf("  %-14s %s\n", $cmd->id->value, $cmd->summary);
                if ($cmd->usage !== null) {
                    $out .= sprintf("                 Usage: %s\n", $cmd->usage);
                }
            }
        }

        return $out;
    }
}
