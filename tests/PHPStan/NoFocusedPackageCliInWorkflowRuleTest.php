<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use voku\AgentLoop\PHPStan\Rules\NoFocusedPackageCliInWorkflowRule;

/** @extends RuleTestCase<NoFocusedPackageCliInWorkflowRule> */
final class NoFocusedPackageCliInWorkflowRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoFocusedPackageCliInWorkflowRule();
    }

    public function testFocusedPackageCliInstantiationIsRejectedButTypedApiIsAllowed(): void
    {
        $this->analyse(
            [__DIR__ . '/data/focused-package-cli-in-workflow.php'],
            [[
                'Workflow orchestration must not instantiate focused-package CLI voku\\AgentSession\\Cli. Use the package typed PHP API, or keep an unavoidable CLI boundary outside voku\\AgentLoop\\Workflow.',
                14,
            ]],
        );
    }
}
