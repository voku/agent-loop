<?php

declare(strict_types=1);

namespace voku\AgentLoop\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Project PHPStan-rule fixtures must not share the normal PHPUnit process.
 *
 * @implements Rule<Class_>
 */
final class NoInProcessPhpstanRuleTestCaseRule implements Rule
{
    public function getNodeType(): string
    {
        return Class_::class;
    }

    /**
     * @param Class_ $node
     *
     * @return list<\PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->extends === null || $scope->resolveName($node->extends) !== \PHPStan\Testing\RuleTestCase::class) {
            return [];
        }

        return [
            RuleErrorBuilder::message('Project-specific PHPStan rule fixtures must run in an isolated PHPStan subprocess; do not extend PHPStan\\Testing\\RuleTestCase in the normal PHPUnit suite.')
                ->identifier('agentLoop.phpstan.inProcessRuleTestCase')
                ->build(),
        ];
    }
}
