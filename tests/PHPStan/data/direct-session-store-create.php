<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use voku\AgentSession\SessionStore;

final class DirectSessionStoreCreateFixture
{
    public function create(SessionStore $store): void
    {
        $store->create('/tmp/sessions', 'TASK-1', null, 'fixture', null);
    }
}
