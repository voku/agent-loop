<?php

declare(strict_types=1);

namespace voku\AgentLoop\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A layout migration is incomplete while production code still names retired roots.
 *
 * @implements Rule<String_>
 */
final class NoRetiredStatePathLiteralRule implements Rule
{
    /** @var non-empty-list<non-empty-string> */
    private const array RETIRED_ROOTS = [
        'infra/doc/agent-learning',
        'session_plan',
        '.agent-map/',
    ];

    public function getNodeType(): string
    {
        return String_::class;
    }

    /**
     * @return list<\PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof String_) {
            return [];
        }

        $namespace = $scope->getNamespace();
        if (
            $namespace === null
            || !str_starts_with($namespace, 'voku\\AgentLoop')
            || str_starts_with($namespace, 'voku\\AgentLoop\\Tests')
            || str_starts_with($namespace, 'voku\\AgentLoop\\PHPStan')
        ) {
            return [];
        }

        foreach (self::RETIRED_ROOTS as $retiredRoot) {
            if (str_contains($node->value, $retiredRoot)) {
                return [RuleErrorBuilder::message(sprintf(
                    'Production code must not name retired state root %s. Ask ProjectLayout for the current project path.',
                    $retiredRoot,
                ))->identifier('agentLoop.layout.retiredStatePath')->build()];
            }
        }

        return [];
    }
}
