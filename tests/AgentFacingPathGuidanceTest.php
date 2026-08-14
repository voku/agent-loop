<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Skills, subagent briefs and lifecycle docs are read by coding agents as
 * instructions. When they name a retired state path the agent looks in the
 * wrong place and reports the artifact as missing — a failure mode that no
 * amount of product testing catches, because the product is correct and the
 * instructions are not.
 *
 * Migration guides are exempt: naming the old location is their whole job.
 */
final class AgentFacingPathGuidanceTest extends TestCase
{
    /** Paths the compact-layout consolidation retired. */
    private const array RETIRED_PATHS = [
        'session_plan/',
        'recall-output/',
        '.agent-map/',
    ];

    /** Command forms removed by the durable Contract/compact-layout reset. */
    private const array RETIRED_COMMANDS = [
        '--brief-revision',
        'session learning decide',
        'workflow start',
    ];

    /** Documents whose purpose is to map old locations to new ones. */
    private const array MIGRATION_DOCUMENTS = [
        'UPGRADING.md',
        'docs/compact-layout.md',
        'docs/quick-start.md',
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function agentFacingDocuments(): iterable
    {
        $root = dirname(__DIR__);
        foreach ([$root . '/docs/agents', $root . '/docs/architecture'] as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            ) as $file) {
                if ($file->getExtension() !== 'md') {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($root) + 1);
                if (in_array($relative, self::MIGRATION_DOCUMENTS, true)) {
                    continue;
                }

                yield $relative => [$file->getPathname()];
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('agentFacingDocuments')]
    public function testAgentInstructionsDoNotNameRetiredStatePaths(string $path): void
    {
        $contents = (string) file_get_contents($path);

        foreach (self::RETIRED_PATHS as $retired) {
            self::assertStringNotContainsString(
                $retired,
                $contents,
                sprintf(
                    '%s tells an agent to use the retired path "%s". Name the owned root instead, '
                    . 'and point at `agent-loop init paths` when the location is configurable.',
                    basename($path),
                    $retired,
                ),
            );
        }
    }

    public function testExecutableGuidanceDoesNotTeachRetiredCommands(): void
    {
        $root = dirname(__DIR__);
        $documents = [
            $root . '/README.md',
            $root . '/docs/quick-start.md',
        ];
        foreach (self::agentFacingDocuments() as [$path]) {
            $documents[] = $path;
        }

        foreach ($documents as $path) {
            $contents = (string) file_get_contents($path);
            foreach (self::RETIRED_COMMANDS as $retired) {
                self::assertStringNotContainsString(
                    $retired,
                    $contents,
                    basename($path) . ' still teaches the retired command form "' . $retired . '".',
                );
            }
        }
    }

    public function testSelfShapeEvidenceUploadCoversPathsTheHarnessActuallyWrites(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__) . '/.github/workflows/ci.yml');

        foreach (self::RETIRED_PATHS as $retired) {
            self::assertStringNotContainsString(
                $retired,
                $workflow,
                'CI still collects evidence from the retired path "' . $retired
                . '". if-no-files-found: warn means that silently uploads nothing.',
            );
        }

        self::assertStringContainsString('.agent-loop/runs/SELF-SHAPE', $workflow);
        self::assertStringContainsString('.agent-loop/recall/SELF-SHAPE', $workflow);
    }
}
