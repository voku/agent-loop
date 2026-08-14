<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests\PHPStan\Data;

use PHPStan\Testing\RuleTestCase;
use voku\AgentLoop\PHPStan\Rules\NoFocusedPackageCliInWorkflowRule;

/** @extends RuleTestCase<NoFocusedPackageCliInWorkflowRule> */
abstract class InProcessPhpstanRuleTestCaseFixture extends RuleTestCase
{
}
