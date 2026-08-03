<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

use voku\AgentMap\Index\AgentMapIndex;

interface EditMapProvider
{
    public function prepare(EditRequest $request): AgentMapIndex;
}
