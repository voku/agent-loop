<?php

declare(strict_types=1);

namespace voku\AgentLoop\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Expression;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use voku\AgentLoop\ProjectLayout;

/**
 * Calling a path authority and discarding its answer is either dead code or a split contract.
 *
 * @implements Rule<Expression>
 */
final class NoDiscardedProjectLayoutPathRule implements Rule
{
    /** @var non-empty-list<non-empty-string> */
    private const array PATH_METHODS = [
        'stateRoot',
        'boardRoot',
        'tasksRoot',
        'sessionsRoot',
        'learningRoot',
        'recallRoot',
        'runsRoot',
        'contractsRoot',
        'editRoot',
        'risksRoot',
        'mapRoot',
        'toolInventory',
        'gitHooksConfig',
    ];

    public function getNodeType(): string
    {
        return Expression::class;
    }

    /**
     * @param Expression $node
     *
     * @return list<\PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $call = $node->expr;
        if (
            !$call instanceof MethodCall
            || !$call->name instanceof Identifier
            || !in_array($call->name->toString(), self::PATH_METHODS, true)
            || !(new ObjectType(ProjectLayout::class))->isSuperTypeOf($scope->getType($call->var))->yes()
        ) {
            return [];
        }

        return [RuleErrorBuilder::message(sprintf(
            'ProjectLayout::%s() result is discarded. Consume the configured path or remove the meaningless resolver call.',
            $call->name->toString(),
        ))->identifier('agentLoop.layout.discardedPath')->build()];
    }
}
