<?php

declare(strict_types=1);

namespace voku\AgentLoop\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * `.git` is not always a directory, so its shape cannot answer "is this a repository".
 *
 * `git worktree add` writes `.git` as a *file* pointing at the shared
 * repository, and so does a submodule. `is_dir($root . '/.git')` therefore
 * reported a valid linked worktree as "not a repository": `init doctor` warned
 * that Git was missing, and `init sync-githooks` silently skipped
 * `core.hooksPath`/`commit.template` while still writing the hook files, so the
 * hooks were installed but never reachable.
 *
 * Ask Git (`GitWorkTree`) instead of the filesystem.
 *
 * Tests are out of scope: a test may legitimately assert the shape itself —
 * that a linked worktree's `.git` really is a file is what makes the runtime
 * defect reproducible.
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
     * @return list<\PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Name || strtolower($node->name->toString()) !== 'is_dir') {
            return [];
        }

        $namespace = $scope->getNamespace();
        if ($namespace !== null && str_starts_with($namespace, 'voku\\AgentLoop\\Tests')) {
            return [];
        }

        $argument = $node->getArgs()[0]->value ?? null;
        if ($argument === null || !$this->namesGitMetadata($argument)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Do not infer Git repository state from is_dir(.git); a linked worktree stores .git as a file. Ask Git through GitWorkTree instead.',
            )->identifier('agentLoop.git.metadataShape')->build(),
        ];
    }

    private function namesGitMetadata(Node $node): bool
    {
        if ($node instanceof String_) {
            $value = rtrim(str_replace('\\', '/', $node->value), '/');

            return $value === '.git' || str_ends_with($value, '/.git');
        }

        if ($node instanceof Concat) {
            return $this->namesGitMetadata($node->right);
        }

        return false;
    }
}
