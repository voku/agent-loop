<?php

declare(strict_types=1);

namespace voku\AgentLoop\Context;

use ItpContext\Contract\RuleIdentifier;
use ItpContext\Enum\Tier;
use ItpContext\Model\RuleDef;
use voku\AgentLoop\PHPStan\Rules\NoFocusedPackageCliInWorkflowRule;
use voku\AgentLoop\Tests\AgentFacingPathGuidanceTest;
use voku\AgentLoop\Tests\InitToolsCommandTest;
use voku\AgentLoop\Tests\ProjectLayoutOwnershipTest;
use voku\AgentLoop\Tests\RealIssueEvidenceToolBoundaryTest;
use voku\AgentLoop\Tests\WorkflowCloseCommandTest;

/**
 * The architecture intent a reader cannot recover from the code alone.
 *
 * Every rule here was violated at least once by a change that passed review,
 * which is the bar for adding one: a rule nobody has broken is a comment. Each
 * names the check that would catch the next violation, so `verified_by` stays a
 * reference an agent can run rather than a claim it has to trust.
 *
 * Keep this small. Density is the point — annotating everything produces a
 * second copy of the code with none of its precision.
 */
enum ArchitectureRules implements RuleIdentifier
{
    case SingleStatePathOwner;
    case TypedPackageApisInsideWorkflow;
    case EvidenceIsNotAuthority;
    case ExternalToolsStayOptional;

    public function getDefinition(): RuleDef
    {
        return match ($this) {
            self::SingleStatePathOwner => new RuleDef(
                statement: 'Only ProjectLayout may spell a repository-local state path.',
                tier: Tier::Critical,
                owner: 'voku/agent-loop',
                rationale: 'A second literal is a second answer to "where does this live", and the two agree only in the '
                    . 'default configuration. That is how a read-only projection kept reading a pre-consolidation session '
                    . 'root after the migration, and how `init tools` reported a built agent-map index as never built '
                    . 'while ignoring a configured state_root.',
                verifiedBy: [ProjectLayoutOwnershipTest::class, InitToolsCommandTest::class],
                refs: ['docs/compact-layout.md', 'src/ProjectLayout.php'],
            ),
            self::TypedPackageApisInsideWorkflow => new RuleDef(
                statement: 'Workflow orchestration calls typed package APIs, never a focused package CLI.',
                tier: Tier::Important,
                owner: 'voku/agent-loop',
                rationale: 'Shelling out to a sibling package duplicates argv, path, default and failure knowledge that '
                    . 'the package already owns, and the duplicate drifts silently because nothing type-checks a string.',
                verifiedBy: [NoFocusedPackageCliInWorkflowRule::class],
                refs: ['docs/architecture/first-party-capability-matrix.md'],
            ),
            self::EvidenceIsNotAuthority => new RuleDef(
                statement: 'Generated evidence never becomes approval: a produced artifact is not a passed gate.',
                tier: Tier::Critical,
                owner: 'voku/agent-loop',
                rationale: 'Rendering a review prompt, running a heuristic scanner or exercising an APPROVE transition '
                    . 'produces evidence about a check, not the judgment the check exists to obtain. Collapsing the two '
                    . 'lets a lifecycle report success while the artifact it wrote says the decision is missing.',
                verifiedBy: [WorkflowCloseCommandTest::class],
                refs: ['docs/dogfood/self-shaping.md', 'docs/workflow/learning-boundary.md'],
            ),
            self::ExternalToolsStayOptional => new RuleDef(
                statement: 'External evidence tools are discovered and reported, never required to run the workflow.',
                tier: Tier::Important,
                owner: 'voku/agent-loop',
                rationale: 'An absent tool is one evidence plane a run does not have, not a broken setup. Treating '
                    . 'presence as a precondition would make the workflow unusable in exactly the repositories that '
                    . 'have not adopted the tooling yet.',
                verifiedBy: [RealIssueEvidenceToolBoundaryTest::class, InitToolsCommandTest::class],
                refs: ['docs/dogfood/real-issue-acceptance.md'],
            ),
        };
    }
}
