<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

interface EditRunner
{
    public function run(EditExecution $execution): EditRunResult;
}
