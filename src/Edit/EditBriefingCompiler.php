<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

interface EditBriefingCompiler
{
    public function compile(EditRequest $request): EditBriefing;
}
