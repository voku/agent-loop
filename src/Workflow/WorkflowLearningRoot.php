<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;

/** Resolves the conventional learning root used by a scaffolded project. */
final class WorkflowLearningRoot
{
    public static function resolve(string $rootPath, ?string $explicitRoot): string
    {
        if ($explicitRoot !== null) {
            return $explicitRoot;
        }

        $root = rtrim($rootPath, '/');
        foreach (['infra/doc/agent-learning', 'learning-root'] as $relativePath) {
            $candidate = $root . '/' . $relativePath;
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        throw new InvalidArgumentException('no agent-learning root found; run "agent-loop init scaffold" or pass --learning-root <path>.');
    }
}
