<?php

declare(strict_types=1);

namespace voku\AgentLoop\Cli;

/**
 * Package owners responsible for executing commands in the governed loop.
 */
enum CommandOwner: string
{
    case Loop = 'voku/agent-loop';
    case Kanban = 'voku/agent-kanban';
    case Learning = 'voku/agent-learning';
    case Map = 'voku/agent-map';
    case Recall = 'voku/agent-recall-compiler';
    case Session = 'voku/agent-session';
}
