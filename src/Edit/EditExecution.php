<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

use voku\AgentMap\Index\ResolvedMethod;

final readonly class EditExecution
{
    public function __construct(
        public EditRequest $request,
        public string $promptPath,
        public ?ResolvedMethod $target = null,
    ) {
    }
}
