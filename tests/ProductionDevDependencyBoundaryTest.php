<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ProductionDevDependencyBoundaryTest extends TestCase
{
    public function testProductionSourceDoesNotReferenceDevOnlyArchitectureMetadata(): void
    {
        $sourceRoot = dirname(__DIR__) . '/src';
        $violations = [];
        $patterns = [
            'ItpContext\\' => '~ItpContext\\\\~',
            'voku\\AgentLoop\\Context\\ArchitectureRules' => '~voku\\\\AgentLoop\\\\Context\\\\(?:ArchitectureRules|\{[^}]*\bArchitectureRules\b[^}]*\})~',
        ];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents, 'Production source must be readable: ' . $file->getPathname());
            foreach ($patterns as $reference => $pattern) {
                $matches = preg_match($pattern, $contents);
                self::assertNotFalse($matches, 'Forbidden production reference pattern must be valid: ' . $pattern);
                if ($matches !== 1) {
                    continue;
                }

                $violations[] = str_replace($sourceRoot . '/', '', $file->getPathname()) . ': ' . $reference;
            }
        }

        sort($violations);
        self::assertSame(
            [],
            $violations,
            'Production code must not reference dev-only architecture metadata. Keep it under tools/, tests/, or phpstan/.',
        );
    }
}
