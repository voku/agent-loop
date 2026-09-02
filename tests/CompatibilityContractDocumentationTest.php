<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

final class CompatibilityContractDocumentationTest extends TestCase
{
    public function testCompatibilityContractClassifiesAuthorityWorkingAndDerivedState(): void
    {
        $document = (string) file_get_contents(dirname(__DIR__) . '/docs/compatibility.md');

        self::assertStringContainsString('ExecutionGateway', $document);
        self::assertStringContainsString('public PHP symbol not named', $document);
        self::assertStringContainsString('durable authority', $document);
        self::assertStringContainsString('pruneable, resume-sensitive', $document);
        self::assertStringContainsString('derived/regenerable', $document);
        self::assertStringContainsString('LearningNote', $document);
        self::assertStringContainsString('learning_precedent', $document);
        self::assertStringContainsString('Silent reinterpretation', $document);
        self::assertStringContainsString('issue #328', $document);
    }

    public function testCompatibilityContractDoesNotBlessPrivateStorageOrCliProse(): void
    {
        $document = (string) file_get_contents(dirname(__DIR__) . '/docs/compatibility.md');

        self::assertStringContainsString('private artifact filenames', $document);
        self::assertStringContainsString('Human CLI prose is never a sibling-package API', $document);
        self::assertStringContainsString('must never salvage it by reconstructing', $document);
        self::assertStringContainsString('already-internal', $document);
    }
}
