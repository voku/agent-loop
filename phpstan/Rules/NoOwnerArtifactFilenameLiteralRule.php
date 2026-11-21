<?php

declare(strict_types=1);

namespace voku\AgentLoop\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Recall owns the filenames inside a compiled Recall output directory.
 *
 * Production code that appends one of them to a directory by hand silently
 * keeps working after Recall renames or reshapes the artifact, and tends to
 * re-implement reading policy (for example treating a corrupt plan as absent).
 * Ask the owner's typed path accessor or reader instead.
 *
 * Only a literal whose whole value is the filename, optionally with a leading
 * slash, is a path segment; prose that merely mentions a file is left alone.
 *
 * @implements Rule<String_>
 */
final class NoOwnerArtifactFilenameLiteralRule implements Rule
{
    /** @var non-empty-list<non-empty-string> */
    private const array OWNER_ARTIFACTS = [
        'system.md',
        'meta.json',
        'recall-log.draft.json',
        'verification-plan.json',
        'verification-key.json',
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
        $namespace = $scope->getNamespace();
        if (
            $namespace === null
            || !str_starts_with($namespace, 'voku\\AgentLoop')
            || str_starts_with($namespace, 'voku\\AgentLoop\\Tests')
            || str_starts_with($namespace, 'voku\\AgentLoop\\PHPStan')
        ) {
            return [];
        }

        $filename = ltrim($node->value, '/');
        if (!in_array($filename, self::OWNER_ARTIFACTS, true)) {
            return [];
        }

        return [RuleErrorBuilder::message(sprintf(
            'Production code must not build Recall-owned artifact path %s by hand. Ask the agent-recall-compiler path accessor or reader instead.',
            $filename,
        ))->identifier('agentLoop.recall.ownerArtifactFilename')->build()];
    }
}
