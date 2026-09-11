<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Loop may configure a focused package's mount root. It must not spell out the
 * filenames and directories the owner keeps below it, because then an owner's
 * layout change breaks Loop silently instead of at its own boundary.
 */
final class WorkflowReviewReportPathOwnerBoundaryTest extends TestCase
{
    public function testWorkflowReviewReaderUsesRecallOwnerPathProjection(): void
    {
        $source = $this->source('src/Workflow/WorkflowReviewReportReader.php');

        self::assertStringContainsString('->jsonPath($taskId, $outputDirectory)', $source);
        self::assertStringNotContainsString("'/reviews/'", $source);
        self::assertStringNotContainsString('blindspots.json', $source);
    }

    public function testTheHumanReviewWorkbenchAsksRecallWhereItsReviewsLive(): void
    {
        // The `.human.html` filename is Loop's own; the directory it sits in is
        // Recall's, so only the filename is composed here.
        $source = $this->source('src/Workflow/WorkflowHumanReviewCommand.php');

        self::assertStringContainsString('->reviewsDirectory($outputDirectory)', $source);
        self::assertStringNotContainsString("'/reviews/'", $source);
    }

    public function testEditVerificationTakesTheMapIndexPathFromItsOwner(): void
    {
        // agent-map names its own index. A default spelled out here would keep
        // working right up until the owner renames the artifact.
        foreach ([
            'src/Edit/Refactor/RefactorVerifyCommand.php',
            'src/Edit/Refactor/MethodRemovalVerifyCommand.php',
            'src/Edit/Refactor/PropertyRemovalVerifyCommand.php',
            'src/Edit/Refactor/ClassConstantRemovalVerifyCommand.php',
        ] as $path) {
            $source = $this->source($path);

            self::assertStringContainsString('->mapIndex()', $source, $path);
            self::assertStringNotContainsString('php-symbols.json', $source, $path);
        }
    }

    private function source(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__) . '/' . $relativePath);
        self::assertIsString($source, $relativePath);

        return $source;
    }
}
