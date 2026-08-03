<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

final readonly class EditExecution
{
    public function __construct(
        public EditRequest $request,
        public string $promptPath,
    ) {
    }
}
