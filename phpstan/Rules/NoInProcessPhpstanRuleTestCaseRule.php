<?php

declare(strict_types=1);

namespace voku\AgentLoop\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Project PHPStan rule fixtures stay out of the normal PHPUnit process.
 *
 * `PHPStan\Testing\RuleTestCase` boots a PHPStan container inside the test
 * process. agent-loop's PHPUnit run also loads `agent-map`, which performs its
 * own Composer-based PHPStan discovery there, and the two containers do not
 * coexist. The isolation is owned by the dedicated PHP runner
 * (`tools/project-phpstan-rules.php`); this rule makes reintroducing the
 * in-process variant fail analysis instead of failing mysteriously at runtime.
 *
 * @implements Rule<Class_>
 */
final class NoInProcessPhpstanRuleTestCaseRule implements Rule
{
    private const string RULE_TEST_CASE = 'PHPStan\\Testing\\RuleTestCase';

    public function getNodeType(): string
    {
        return Class_::class;
    }

    /**
     * @return list<\PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->extends === null || ltrim($node->extends->toString(), '\\') !== self::RULE_TEST_CASE) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Project PHPStan rule fixtures must run in an isolated PHPStan process; do not extend PHPStan\\Testing\\RuleTestCase in the PHPUnit suite. Assert the rule through php tools/project-phpstan-rules.php instead.',
            )->identifier('agentLoop.phpstan.inProcessRuleTestCase')->build(),
        ];
    }
}
