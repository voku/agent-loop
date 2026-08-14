<?php

declare(strict_types=1);

namespace voku\AgentLoop\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use voku\AgentSession\SessionStore;

/**
 * Keep the one-active-Session invariant behind one workflow owner.
 *
 * @implements Rule<MethodCall>
 */
final class NoDirectSessionStoreCreateInWorkflowRule implements Rule
{
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @param MethodCall $node
     *
     * @return list<\PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $namespace = $scope->getNamespace();
        if ($namespace === null || ($namespace !== 'voku\\AgentLoop\\Workflow' && !str_starts_with($namespace, 'voku\\AgentLoop\\Workflow\\'))) {
            return [];
        }
        if (!$node->name instanceof Identifier || strtolower($node->name->toString()) !== 'create') {
            return [];
        }
        if (!(new ObjectType(SessionStore::class))->isSuperTypeOf($scope->getType($node->var))->yes()) {
            return [];
        }
        if ($scope->getClassReflection()?->getName() === 'voku\\AgentLoop\\Workflow\\WorkflowSessionResolver') {
            return [];
        }

        return [
            RuleErrorBuilder::message('Workflow orchestration must not call SessionStore::create() directly. Route Session reuse/allocation through WorkflowSessionResolver so one-active-Session enforcement cannot drift.')
                ->identifier('agentLoop.workflow.directSessionCreate')
                ->build(),
        ];
    }
}
