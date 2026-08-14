<?php

declare(strict_types=1);

namespace voku\AgentLoop\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Workflow orchestration consumes ProjectLayout-owned paths; it must not
 * resurrect historical state roots as private literals.
 *
 * @implements Rule<String_>
 */
final class NoWorkflowPrivateStatePathRule implements Rule
{
    private const array FORBIDDEN_PATH_FRAGMENTS = [
        'session_plan',
        'infra/doc/agent-learning',
    ];

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
        if ($namespace === null || ($namespace !== 'voku\\AgentLoop\\Workflow' && !str_starts_with($namespace, 'voku\\AgentLoop\\Workflow\\'))) {
            return [];
        }

        foreach (self::FORBIDDEN_PATH_FRAGMENTS as $fragment) {
            if (!str_contains(str_replace('\\', '/', $node->value), $fragment)) {
                continue;
            }

            return [
                RuleErrorBuilder::message(sprintf(
                    'Workflow orchestration must not own historical state path "%s". Resolve owner state through ProjectLayout or the persisted Run binding.',
                    $fragment,
                ))
                    ->identifier('agentLoop.workflow.privateStatePath')
                    ->build(),
            ];
        }

        return [];
    }
}
