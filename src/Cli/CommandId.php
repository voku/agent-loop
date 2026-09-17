<?php

declare(strict_types=1);

namespace voku\AgentLoop\Cli;

/**
 * Dispatcher-level command identities for the governed agent loop.
 */
enum CommandId: string
{
    case Quick = 'quick';
    case Enter = 'enter';
    case Finish = 'finish';
    case Repair = 'repair';
    case Pipeline = 'pipeline';
    case Edit = 'edit';
    case Board = 'board';
    case Verify = 'verify';
    case BoardVerify = 'board:verify';
    case Learn = 'learn';
    case Recall = 'recall';
    case Prompt = 'prompt';
    case Session = 'session';
    case Map = 'map';
    case Memory = 'memory';
    case Workflow = 'workflow';
    case Review = 'review';
    case Init = 'init';
    case Githooks = 'githooks';
    case Commands = 'commands';
    case Help = 'help';
}
