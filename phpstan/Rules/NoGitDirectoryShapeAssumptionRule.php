<?php

declare(strict_types=1);

namespace voku\AgentLoop\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Git owns the shape of its metadata. A linked worktree legitimately uses a
 * `.git` file, so project code must not gate Git support on `is_dir(.git)`.
 *
 * @implements Rule<FuncCall>
 */
final class NoGitDirectoryShapeAssumptionRule implements Rule
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
        if (!$node->name instanceof Name || strtolower($node->name->toString()) !== 'is_dir' || !isset($node->args[0])) {
            return [];
        }

        if (!$this->containsGitMetadataLiteral($node->args[0]->value)) {
            return [];
        }

        return [
            RuleErrorBuilder::message('Do not infer Git repository support from is_dir(.git); linked worktrees may use a .git file. Ask Git for repository/worktree state instead.')
                ->identifier('agentLoop.git.metadataShape')
                ->build(),
        ];
    }

    private function containsGitMetadataLiteral(Expr $expression): bool
    {
        if ($expression instanceof String_) {
            return preg_match('~(?:^|/)\\.git(?:/|$)~', str_replace('\\\\', '/', $expression->value)) === 1;
        }

        if ($expression instanceof Concat) {
            return $this->containsGitMetadataLiteral($expression->left)
                || $this->containsGitMetadataLiteral($expression->right);
        }

        return false;
    }
}
