<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests\PHPStan;

use PHPStan\Testing\TypeInferenceTestCase;

/**
 * A sibling of RuleTestCase boots the same in-process PHPStan container, so the
 * project rule must match the shared PHPStanTestCase ancestor, not one child.
 */
final class TypeInferenceInProcessFixture extends TypeInferenceTestCase
{
}

/**
 * An intermediate base hides the forbidden ancestor from the extends clause of
 * the concrete test; the rule must still see it in the resolved parent chain.
 */
abstract class IntermediateInProcessFixtureBase extends TypeInferenceTestCase
{
}

final class IndirectInProcessFixture extends IntermediateInProcessFixtureBase
{
}
