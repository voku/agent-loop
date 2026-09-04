<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

final class RecallDocumentManifestTest extends TestCase
{
    public function testSelfShapeLifecycleIsRegisteredForItsOwningSurfaces(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/docs/recall-documents.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($manifest);
        self::assertIsArray($manifest['documents'] ?? null);

        $matches = array_values(array_filter(
            $manifest['documents'],
            static fn (mixed $document): bool => is_array($document)
                && ($document['id'] ?? null) === 'skill.agent-loop-self-shaping',
        ));
        self::assertCount(1, $matches);

        $document = $matches[0];
        self::assertSame('skill', $document['type'] ?? null);
        self::assertSame('dogfood/self-shaping.md', $document['source'] ?? null);
        self::assertContains('tools/self-shape-dogfood.php', $document['scope'] ?? []);
        self::assertContains('tools/Dogfood', $document['scope'] ?? []);
        self::assertSame(12000, $document['max_chars'] ?? null);
    }
}
