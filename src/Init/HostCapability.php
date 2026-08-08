<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

enum HostCapability: string
{
    case SkillProjection = 'skill-projection';
    case SubagentProjection = 'subagent-projection';
    case SessionBootstrap = 'session-bootstrap';
    case SubagentBootstrap = 'subagent-bootstrap';
    case PreToolGuardrail = 'pre-tool-guardrail';
    case RepositoryHooks = 'repository-hooks';
}
