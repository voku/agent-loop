<?php

declare(strict_types=1);

namespace voku\AgentLoop\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Project configuration is the only workflow Learning-root authority.
 *
 * @implements Rule<String_>
 */
final class NoWorkflowLearningRootOptionRule implements Rule
{
    public function getNodeType(): string
    {
        return String_::class;
    }

    /**
     * @param String_ $node
     *
     * @return list<\PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $namespace = $scope->getNamespace();
        if (
            $node->value !== '--learning-root'
            || $namespace === null
            || ($namespace !== 'voku\\AgentLoop\\Workflow' && !str_starts_with($namespace, 'voku\\AgentLoop\\Workflow\\'))
        ) {
            return [];
        }

        return [RuleErrorBuilder::message(
            'Workflow commands must not accept --learning-root. Resolve Learning state from ProjectLayout and preserve the selected root in the governed Run.',
        )->identifier('agentLoop.workflow.learningRootOption')->build()];
    }
}
