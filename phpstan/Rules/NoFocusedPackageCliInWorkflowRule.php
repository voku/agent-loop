<?php

declare(strict_types=1);

namespace voku\AgentLoop\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Keep focused-package CLI adapters out of workflow orchestration.
 *
 * The public Dispatcher may still expose package CLI namespaces. Inside
 * voku\AgentLoop\Workflow, orchestration must use typed package APIs or an
 * explicitly injected boundary owned outside the workflow layer.
 *
 * @implements Rule<New_>
 */
final class NoFocusedPackageCliInWorkflowRule implements Rule
{
    /** @var list<class-string> */
    private const array FOCUSED_PACKAGE_CLIS = [
        \voku\AgentKanban\Cli\CliApplication::class,
        \voku\AgentLearning\Cli::class,
        \voku\AgentMap\Cli\AgentMapApplication::class,
        \voku\AgentRecallCompiler\Cli::class,
        \voku\AgentRecallCompiler\Review\ReviewCli::class,
        \voku\AgentSession\Cli::class,
    ];

    public function getNodeType(): string
    {
        return New_::class;
    }

    /**
     * @param New_ $node
     *
     * @return list<\PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $namespace = $scope->getNamespace();
        if ($namespace === null || ($namespace !== 'voku\\AgentLoop\\Workflow' && !str_starts_with($namespace, 'voku\\AgentLoop\\Workflow\\'))) {
            return [];
        }

        if (!$node->class instanceof Node\Name) {
            return [];
        }

        $className = $scope->resolveName($node->class);
        if (!in_array($className, self::FOCUSED_PACKAGE_CLIS, true)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Workflow orchestration must not instantiate focused-package CLI %s. Use the package typed PHP API, or keep an unavoidable CLI boundary outside voku\\AgentLoop\\Workflow.',
                $className,
            ))
                ->identifier('agentLoop.workflow.focusedPackageCli')
                ->build(),
        ];
    }
}
