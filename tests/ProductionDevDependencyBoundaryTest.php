<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use ItpContext\Attribute\Rule;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use voku\AgentLoop\Context\ArchitectureRules;
use voku\AgentLoop\Workflow\WorkflowLearningRecorder;

final class ProductionDevDependencyBoundaryTest extends TestCase
{
    public function testProductionSourceUsesOnlyTheApprovedOptionalItpAnnotation(): void
    {
        $sourceRoot = dirname(__DIR__) . '/src';
        $annotatedSource = $sourceRoot . '/Workflow/WorkflowLearningRecorder.php';
        $violations = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents, 'Production source must be readable: ' . $file->getPathname());
            if ($file->getPathname() === $annotatedSource) {
                $contents = str_replace([
                    'use ItpContext\\Attribute\\Rule;',
                    'use voku\\AgentLoop\\Context\\ArchitectureRules;',
                    '#[Rule(ArchitectureRules::WorkflowActorIsAttribution)]',
                ], '', $contents);
            }

            foreach ([
                'ItpContext\\' => '~ItpContext\\\\~',
                'voku\\AgentLoop\\Context\\ArchitectureRules' => '~voku\\\\AgentLoop\\\\Context\\\\(?:ArchitectureRules|\\{[^}]*\\bArchitectureRules\\b[^}]*\\})~',
            ] as $reference => $pattern) {
                $matches = preg_match($pattern, $contents);
                self::assertNotFalse($matches, 'Forbidden production reference pattern must be valid: ' . $pattern);
                if ($matches === 1) {
                    $violations[] = str_replace($sourceRoot . '/', '', $file->getPathname()) . ': ' . $reference;
                }
            }
        }

        sort($violations);
        self::assertSame(
            [],
            $violations,
            'Production code may carry only the approved source-linked ITP annotation; it must not call ITP or otherwise depend on dev-only architecture metadata.',
        );
        self::assertStringContainsString(
            '#[Rule(ArchitectureRules::WorkflowActorIsAttribution)]',
            (string) file_get_contents($annotatedSource),
            'The narrow optional ITP annotation must remain attached to the actor-validation boundary.',
        );
    }

    public function testActorValidationAnnotationExplainsAttributionBoundary(): void
    {
        $attributes = (new \ReflectionMethod(WorkflowLearningRecorder::class, 'assertAllowedActor'))
            ->getAttributes(Rule::class);

        self::assertCount(1, $attributes);
        self::assertSame(ArchitectureRules::WorkflowActorIsAttribution, $attributes[0]->newInstance()->id);

        $definition = ArchitectureRules::WorkflowActorIsAttribution->getDefinition();
        self::assertStringContainsString('do not authenticate', $definition->statement);
        self::assertStringContainsString('workflow consistency only', $definition->rationale ?? '');
        self::assertNotNull($definition->changeGuidance);
        self::assertContains('docs/workflow/learning-boundary.md', $definition->refs);
        self::assertContains(self::class, $definition->verifiedBy);
    }
}
