<?php

declare(strict_types=1);

namespace voku\AgentLoop\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Project PHPStan rule fixtures stay out of the normal PHPUnit process.
 *
 * Every `PHPStan\Testing\PHPStanTestCase` subclass (`RuleTestCase`,
 * `TypeInferenceTestCase`, ...) boots a PHPStan container inside the test
 * process. agent-loop's PHPUnit run also loads `agent-map`, which performs its
 * own Composer-based PHPStan discovery there, and the two containers do not
 * coexist. The isolation is owned by the dedicated PHP runner
 * (`tools/project-phpstan-rules.php`); this rule makes reintroducing the
 * in-process variant fail analysis instead of failing mysteriously at runtime.
 *
 * The check reads the resolved ancestor chain rather than the `extends` clause,
 * so an import alias, an intermediate base class or a sibling of
 * `RuleTestCase` cannot slip past it.
 *
 * @implements Rule<InClassNode>
 */
final class NoInProcessPhpstanRuleTestCaseRule implements Rule
{
    private const string PHPSTAN_TEST_CASE = 'PHPStan\\Testing\\PHPStanTestCase';

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @return list<\PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!in_array(self::PHPSTAN_TEST_CASE, $node->getClassReflection()->getParentClassesNames(), true)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Project PHPStan rule fixtures must run in an isolated PHPStan process; do not extend PHPStan\\Testing\\PHPStanTestCase (RuleTestCase, TypeInferenceTestCase) in the PHPUnit suite. Assert the rule through php tools/project-phpstan-rules.php instead.',
            )->identifier('agentLoop.phpstan.inProcessRuleTestCase')->build(),
        ];
    }
}
