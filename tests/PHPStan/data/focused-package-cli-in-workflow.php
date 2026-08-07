<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use voku\AgentSession\Cli as SessionCli;
use voku\AgentSession\SessionStore;

final class FocusedPackageCliInWorkflowFixture
{
    public function forbidden(): void
    {
        new SessionCli();
    }

    public function allowed(): void
    {
        new SessionStore();
    }
}
