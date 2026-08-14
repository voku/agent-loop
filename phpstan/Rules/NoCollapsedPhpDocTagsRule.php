<?php

declare(strict_types=1);

namespace voku\AgentLoop\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * PHPDoc parsers consume a second type tag on the same line as description text.
 *
 * @implements Rule<ClassMethod>
 */
final class NoCollapsedPhpDocTagsRule implements Rule
{
    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    /**
     * @return list<\PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof ClassMethod) {
            return [];
        }

        $comment = $node->getDocComment();
        if ($comment === null) {
            return [];
        }

        foreach (preg_split('/\R/', $comment->getText()) ?: [] as $line) {
            if (preg_match('/@(param|return|var|throws|template)\b[^\r\n]*\s@(param|return|var|throws|template)\b/', $line) === 1) {
                return [RuleErrorBuilder::message(
                    'PHPDoc contract tags must be on separate lines; a later tag on the same line is parsed as description text.',
                )->identifier('agentLoop.phpDoc.collapsedTags')->build()];
            }
        }

        return [];
    }
}
