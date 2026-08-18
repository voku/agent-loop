<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use voku\AgentLoop\Run\GovernedRun;
use voku\AgentSession\Session;

final readonly class WorkflowRunPreparationResult
{
    public function __construct(
        public GovernedRun $run,
        public Session $session,
        public string $learningRoot,
        public string $preparedManifestPath,
        public ?string $compiledManifestPath,
        public int $recallExitCode,
        public ?string $searchWarning,
    ) {
    }

    public function recallCompiled(): bool
    {
        return $this->recallExitCode === 0 && $this->compiledManifestPath !== null;
    }
}
