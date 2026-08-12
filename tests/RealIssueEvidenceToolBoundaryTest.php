<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use ItpContext\Attribute\Rule;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use voku\AgentLoop\Context\ArchitectureRules;
use voku\AgentLoop\ProjectLayout;

/**
 * The two external evidence tools ended up on opposite sides of the dependency
 * line, for reasons that are worth keeping executable.
 *
 * `voku/itp-context` is a real dependency: declaring architecture rules means
 * implementing its `RuleIdentifier` and using its `Rule` attribute, so the
 * package has to be installed for the rules to exist at all. It requires only
 * PHP >= 8.3, which this package already requires.
 *
 * `voku/slop-scan` cannot be one yet. 0.1.4 requires Simple-PHP-Code-Parser
 * ^0.21 while `agent-map` requires ^0.22, so the two cannot co-resolve, and the
 * 0.1.5 release that moves to ^0.22.2 has no Git tag — Packagist still serves
 * 0.1.4. It therefore runs from the isolated tool project, and the workflow
 * must keep working when it is absent.
 */
final class RealIssueEvidenceToolBoundaryTest extends TestCase
{
    private const string ACCEPTANCE_DOCUMENT = 'docs/agents/dogfood/real-issue-acceptance.md';

    /** Isolated tool projects that own the binaries this package does not install. */
    private const array TOOL_PROJECT_PREFIXES = [
        'tools/itp-context/',
        'tools/slop-scan/',
        'tools/agent-loop/',
    ];

    /**
     * @return array<string, mixed>
     */
    private function composerManifest(): array
    {
        $decoded = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/composer.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testSlopScanIsNotAPackageDependency(): void
    {
        $manifest = $this->composerManifest();

        foreach (['require', 'require-dev'] as $section) {
            $constraints = $manifest[$section] ?? [];
            self::assertIsArray($constraints);
            self::assertArrayNotHasKey(
                'voku/slop-scan',
                $constraints,
                sprintf(
                    'voku/slop-scan is declared in composer.json "%s". It cannot co-resolve with agent-map until a '
                    . '0.1.5 tag exists on Packagist, and CI runs it from tools/slop-scan instead. See %s.',
                    $section,
                    self::ACCEPTANCE_DOCUMENT,
                ),
            );
        }
    }

    public function testItpContextIsARuntimeDependencyBecauseTheRulesNeedIt(): void
    {
        $manifest = $this->composerManifest();
        $require = $manifest['require'] ?? [];
        self::assertIsArray($require);

        self::assertArrayHasKey(
            'voku/itp-context',
            $require,
            'ArchitectureRules implements ItpContext\Contract\RuleIdentifier and src/ carries its Rule attribute, so '
            . 'the package is required at runtime, not in require-dev.',
        );
    }

    /** A rule that names no symbol is a comment with extra steps. */
    public function testDeclaredRulesAreAttachedToRealSymbols(): void
    {
        $attached = [];
        foreach ($this->productionClasses() as $class) {
            foreach ((new ReflectionClass($class))->getAttributes(Rule::class) as $attribute) {
                $attached[$attribute->newInstance()->id->name] = true;
            }
        }

        foreach (ArchitectureRules::cases() as $case) {
            self::assertArrayHasKey(
                $case->name,
                $attached,
                sprintf('ArchitectureRules::%s is declared but annotates no symbol.', $case->name),
            );
        }
    }

    /** `verified_by` is a check an agent can run, not a claim it has to trust. */
    public function testEveryRuleNamesProofThatExists(): void
    {
        foreach (ArchitectureRules::cases() as $case) {
            $definition = $case->getDefinition();

            self::assertNotSame([], $definition->verifiedBy, $case->name . ' names no proof.');
            foreach ($definition->verifiedBy as $proof) {
                self::assertTrue(
                    class_exists($proof),
                    sprintf('ArchitectureRules::%s names missing proof %s.', $case->name, $proof),
                );
            }

            foreach ($definition->refs as $ref) {
                self::assertFileExists(
                    dirname(__DIR__) . '/' . $ref,
                    sprintf('ArchitectureRules::%s references missing %s.', $case->name, $ref),
                );
            }
        }
    }

    /**
     * A tool project is resolved on one machine and installed on every other
     * one. Without a pinned platform, Composer locks whatever the author's PHP
     * allows: a lock generated on 8.4 pulled symfony/string 8.1, which requires
     * PHP >= 8.4.1, and CI could no longer install the tool on 8.3 — the
     * version this package itself supports.
     *
     * @return iterable<string, array{string}>
     */
    public static function toolProjects(): iterable
    {
        $root = dirname(__DIR__);
        foreach (glob($root . '/tools/*/composer.json') ?: [] as $manifest) {
            yield basename(dirname($manifest)) => [$manifest];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('toolProjects')]
    public function testToolProjectsResolveAgainstTheLowestSupportedPhp(string $manifestPath): void
    {
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);

        $platform = $manifest['config']['platform']['php'] ?? null;
        self::assertIsString(
            $platform,
            sprintf(
                '%s does not pin config.platform.php, so its lock file depends on whichever PHP generated it.',
                $manifestPath,
            ),
        );

        $require = $this->composerManifest()['require'] ?? [];
        self::assertIsArray($require);
        $packagePhp = $require['php'] ?? '';
        self::assertIsString($packagePhp);

        $lowestSupported = ltrim($packagePhp, '^~>= ');
        if ($lowestSupported === '') {
            self::fail('this package declares no PHP constraint to pin against');
        }

        self::assertStringStartsWith(
            $lowestSupported,
            $platform,
            sprintf('%s pins a different PHP than this package supports (%s).', $manifestPath, $packagePhp),
        );
    }

    public function testAcceptanceInstructionsRunSlopScanFromAnIsolatedToolProject(): void
    {
        $document = (string) file_get_contents(dirname(__DIR__) . '/' . self::ACCEPTANCE_DOCUMENT);

        self::assertStringContainsString(
            'tools/slop-scan/slop-scan.php',
            $document,
            'the acceptance document no longer shows how to invoke slop-scan',
        );

        $matches = [];
        preg_match_all('#(\S*)vendor/bin/slop-scan#', $document, $matches);

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
                    '"%s" tells an agent to run slop-scan from a vendor root this package does not install. Name the '
                    . 'isolated tool project (%s) or the pinned PHAR instead.',
                    $invocation,
                    implode(', ', self::TOOL_PROJECT_PREFIXES),
                ),
            );
        }
    }

    /**
     * @return list<class-string>
     */
    private function productionClasses(): array
    {
        // The annotated symbols are few by design; naming them keeps this test
        // from depending on a class-map scan of the whole package.
        return [
            ProjectLayout::class,
            \voku\AgentLoop\Init\InitToolsCommand::class,
            \voku\AgentLoop\Workflow\WorkflowApproveCommand::class,
            \voku\AgentLoop\Workflow\WorkflowCloseCommand::class,
        ];
    }
}
