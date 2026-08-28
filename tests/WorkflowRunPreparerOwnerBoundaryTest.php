<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

final class WorkflowRunPreparerOwnerBoundaryTest extends TestCase
{
    public function testRecallDocumentManifestsComeOnlyFromProjectLayout(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/Workflow/WorkflowRunPreparer.php');
        self::assertIsString($source);

        self::assertStringContainsString('->recallDocumentManifests()', $source);
        self::assertStringNotContainsString("'/recall-documents.json'", $source);
        self::assertStringNotContainsString('learningDocumentManifest', $source);
    }
}
