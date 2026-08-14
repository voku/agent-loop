<?php

declare(strict_types=1);

namespace voku\AgentLoop\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Generic process boundaries use argv arrays; explicit shell execution has a separately named API.
 *
 * @implements Rule<FuncCall>
 */
final class NoShellStringProcessCommandRule implements Rule
{
    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /**
     * @param FuncCall $node
     *
     * @return list<\PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $namespace = $scope->getNamespace();
        $command = $node->getArgs()[0]->value ?? null;
        if (
            !$node->name instanceof Name
            || strtolower($node->name->toString()) !== 'proc_open'
            || $command === null
            || !$scope->getType($command)->isString()->yes()
            || $namespace === null
            || !str_starts_with($namespace, 'voku\\AgentLoop')
            || str_starts_with($namespace, 'voku\\AgentLoop\\Tests')
            || str_starts_with($namespace, 'voku\\AgentLoop\\PHPStan')
        ) {
            return [];
        }

        return [RuleErrorBuilder::message(
            'proc_open must receive an argv array, not shell-shaped command text. Put intentional shell execution behind an explicitly named boundary.',
        )->identifier('agentLoop.process.shellString')->build()];
    }
}
