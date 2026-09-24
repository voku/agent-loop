<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use voku\AgentLearning\DreamRequest;
use voku\AgentLearning\DreamService;
use voku\AgentLoop\ProjectLayout;

/**
 * Dream for embedding hosts, addressed by repository rather than by path.
 *
 * `agent-loop learn dream` already reaches agent-learning's Dream through the
 * CLI with the repository's Learning root filled in. This is the same call for
 * a host that embeds Loop (agent-ui, a runner): Loop resolves the Learning root
 * from its project layout and runs agent-learning's typed Dream, so the host
 * neither rebuilds Dream's composition nor parses its report.
 *
 * {@see preview()} writes nothing. {@see writeCandidates()} writes only
 * candidate Proposals, which need the existing human review before any of
 * them can become active guidance; Loop never approves them.
 */
final readonly class WorkflowDreamService
{
    public function __construct(
        private string $rootPath,
        private DreamService $dream = new DreamService(),
    ) {
    }

    public function preview(int $reviewHorizonDays = 90): WorkflowDreamReport
    {
        return $this->run($reviewHorizonDays, false);
    }

    public function writeCandidates(int $reviewHorizonDays = 90): WorkflowDreamReport
    {
        return $this->run($reviewHorizonDays, true);
    }

    private function run(int $reviewHorizonDays, bool $writeCandidates): WorkflowDreamReport
    {
        $learningRoot = (new ProjectLayout($this->rootPath))->learningRoot();

        return new WorkflowDreamReport(
            $learningRoot,
            $this->dream->run(new DreamRequest(
                learningRoot: $learningRoot,
                reviewHorizonDays: $reviewHorizonDays,
                writeCandidates: $writeCandidates,
            )),
        );
    }
}
