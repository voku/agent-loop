<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests\PHPStan;

use PHPStan\Testing\RuleTestCase;

/**
 * Booting a PHPStan container inside the PHPUnit process collides with the
 * Composer-based PHPStan discovery agent-map performs in that same process.
 */
final class InProcessRuleTestCaseFixture extends RuleTestCase
{
}
