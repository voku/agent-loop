<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

enum HostCapability: string
{
    case Skills = 'skills';
    case Subagents = 'subagents';
    case SessionBootstrap = 'session-bootstrap';
    case SubagentBootstrap = 'subagent-bootstrap';
    case PreToolGuardrail = 'pre-tool-guardrail';
    case RepositoryHooks = 'repository-hooks';
}
