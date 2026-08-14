<?php

declare(strict_types=1);

namespace voku\AgentLoop\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Repository validation must not inherit machine-local php.ini behavior.
 *
 * @implements Rule<FuncCall>
 */
final class PhpChildProcessMustDisableIniRule implements Rule
{
    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /**
     * @return list<\PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $namespace = $scope->getNamespace();
        $command = $node->getArgs()[0]->value ?? null;
        if (
            !$node->name instanceof Name
            || strtolower($node->name->toString()) !== 'proc_open'
            || !$command instanceof Array_
            || $namespace === null
            || !str_starts_with($namespace, 'voku\\AgentLoop')
            || str_starts_with($namespace, 'voku\\AgentLoop\\Tests')
            || str_starts_with($namespace, 'voku\\AgentLoop\\PHPStan')
        ) {
            return [];
        }

        $executable = ($command->items[0] ?? null)?->value;
        if (!$executable instanceof ConstFetch || strtoupper($executable->name->toString()) !== 'PHP_BINARY') {
            return [];
        }

        $noIni = ($command->items[1] ?? null)?->value;
        if ($noIni instanceof String_ && $noIni->value === '-n') {
            return [];
        }

        return [RuleErrorBuilder::message(
            'Child PHP commands must place -n immediately after PHP_BINARY so validation is independent of ambient php.ini.',
        )->identifier('agentLoop.process.phpIni')->build()];
    }
}
