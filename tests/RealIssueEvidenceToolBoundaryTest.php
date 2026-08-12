<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Real-issue acceptance invokes `voku/itp-context` and `voku/slop-scan` as
 * external agent-facing evidence tools. They are deliberately not dependencies
 * of this package: `agent-map` requires Simple-PHP-Code-Parser ^0.22 while
 * `slop-scan` requires ^0.21, and both tools require PHP 8.3+, which a library
 * under test must not be forced to adopt.
 *
 * Two failure modes are cheap to prevent and expensive to notice later: the
 * dependency arriving quietly, and agent-facing instructions naming a binary
 * path the installation boundary does not provide.
 */
final class RealIssueEvidenceToolBoundaryTest extends TestCase
{
    /** Packages the workflow invokes but does not install. */
    private const array EXTERNAL_EVIDENCE_TOOLS = [
        'voku/itp-context',
        'voku/slop-scan',
    ];

    private const string ACCEPTANCE_DOCUMENT = 'docs/agents/dogfood/real-issue-acceptance.md';

    /** Isolated tool projects that own the external binaries. */
    private const array TOOL_PROJECT_PREFIXES = [
        'tools/agent-loop/',
        'tools/slop-scan/',
    ];

    public function testExternalEvidenceToolsAreNotPackageDependencies(): void
    {
        $decoded = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/composer.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($decoded);

        foreach (['require', 'require-dev'] as $section) {
            $constraints = $decoded[$section] ?? [];
            self::assertIsArray($constraints);

            foreach (self::EXTERNAL_EVIDENCE_TOOLS as $package) {
                self::assertArrayNotHasKey(
                    $package,
                    $constraints,
                    sprintf(
                        '%s is declared in composer.json "%s". Real-issue acceptance invokes it as an '
                        . 'external tool from an isolated tool project. Promote it only with the run '
                        . 'evidence and the boundary update described in %s.',
                        $package,
                        $section,
                        self::ACCEPTANCE_DOCUMENT,
                    ),
                );
            }
        }
    }

    public function testAcceptanceInstructionsRunExternalToolsFromIsolatedToolProjects(): void
    {
        $document = (string) file_get_contents(dirname(__DIR__) . '/' . self::ACCEPTANCE_DOCUMENT);

        $matches = [];
        preg_match_all('#(\S*)vendor/bin/(itp-context|slop-scan)#', $document, $matches);

        self::assertNotSame([], $matches[0], 'The acceptance document no longer shows how to invoke the tools.');

        foreach ($matches[0] as $index => $invocation) {
            $prefix = $matches[1][$index];

            $isolated = false;
            foreach (self::TOOL_PROJECT_PREFIXES as $toolProject) {
                if (str_ends_with($prefix, $toolProject)) {
                    $isolated = true;
                    break;
                }
            }

            self::assertTrue(
                $isolated,
                sprintf(
                    '"%s" tells an agent to run an external evidence tool from a vendor root this package '
                    . 'does not install. Name the isolated tool project (%s) or the pinned PHAR instead.',
                    $invocation,
                    implode(', ', self::TOOL_PROJECT_PREFIXES),
                ),
            );
        }
    }

    public function testDogfoodSkillRoutesToTheAcceptanceModel(): void
    {
        $skill = (string) file_get_contents(
            dirname(__DIR__) . '/docs/agents/skills/agent-loop-dogfood/SKILL.md',
        );

        self::assertStringContainsString(
            self::ACCEPTANCE_DOCUMENT,
            $skill,
            'The dogfood skill is what an agent reads first; it must route real-issue runs to the '
            . 'acceptance model instead of leaving it as an unreferenced document.',
        );
    }
}
