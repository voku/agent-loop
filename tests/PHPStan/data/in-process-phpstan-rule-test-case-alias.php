<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests\PHPStan;

use PHPStan\Testing\RuleTestCase as PhpstanRuleTest;

/**
 * Same forbidden in-process PHPStan container, expressed through an import alias.
 * The project rule must resolve the semantic parent class rather than compare
 * the source spelling of the extends clause.
 */
final class AliasedInProcessRuleTestCaseFixture extends PhpstanRuleTest
{
}
