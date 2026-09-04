<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use voku\AgentLoop\Dispatcher;

/**
 * Skills, subagent briefs and lifecycle docs are read by coding agents as
 * instructions. When they name a retired state path or invented command the
 * agent follows the wrong contract even though the product code itself is
 * correct.
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

    /**
     * Configurable state locations that agent-facing skills must discover via
     * `agent-loop init paths` instead of baking in as physical artifact paths.
     */
    private const array CONFIGURABLE_SKILL_PATHS = [
        'recall/<task-id>/',
        '.agent-loop/risks/',
        '.agent-loop/edit/',
        '.agent-loop/map/history.sqlite',
        '.agent-loop/map/php-symbols.json',
        '.agent-loop/map/search.sqlite',
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
        foreach ([
            $root . '/resources/skills',
            $root . '/resources/subagents',
            $root . '/docs/architecture',
            $root . '/docs/reference',
            $root . '/docs/workflow',
        ] as $directory) {
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function skillDocuments(): iterable
    {
        $root = dirname(__DIR__);
        $directory = $root . '/resources/skills';
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->getFilename() !== 'SKILL.md') {
                continue;
            }

            yield substr($file->getPathname(), strlen($root) + 1) => [$file->getPathname()];
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

    /**
     * Host guidance may route into the lifecycle kernel and present its answer.
     * It may not decide when close is legal.
     *
     * agent-loop-review-close once carried a prose copy of the close gate set.
     * It drifted to eight of the eleven gates WorkflowCloseReadinessInspector
     * runs, and nothing detected that, because prose has no test. This is that
     * test. It recognises the phrasings that claim gate knowledge rather than
     * every possible way to express it - a narrow guard against a defect that
     * actually happened, not a general prose analyser.
     */
    /**
     * Ordinary and always-on host assets own no lifecycle decision.
     *
     * Specialist skills may describe specialist operations; the defect is an
     * asset that becomes required lifecycle authority. agent-loop-discipline
     * matters most here because it is injected at SessionStart, so anything it
     * states about ordering is an always-on rule the kernel cannot correct.
     *
     * This guards the drift shapes that actually occurred, not every possible
     * phrasing. "before mutation" alone is not one of them: authority routing
     * such as "resolve current state through the owning workflow command" is
     * legitimate and stays.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('skillDocuments')]
    public function testSkillsDoNotEmbedLifecyclePolicyTheKernelOwns(string $path): void
    {
        $contents = (string) file_get_contents($path);

        foreach ([
            'PLAN ->' => 'restates the lifecycle phase machine',
            'approval compiles Recall' => 'restates when approval compiles Recall',
            'Mutation requires the approved Contract' => 'restates when mutation becomes legal',
            'execution-contract gate is' => 'has the host determine execution-contract readiness',
            '**before** `workflow approve`' => 'sequences discovery ahead of approval by hand',
        ] as $shape => $why) {
            self::assertStringNotContainsString(
                $shape,
                $contents,
                basename(dirname($path)) . ' ' . $why . ' ("' . $shape . '"). The lifecycle kernel owns '
                . 'that decision: route to `enter`/`finish --format=json` and obey next_action_kind.',
            );
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('skillDocuments')]
    public function testSkillsDoNotRestateTheCloseGateSet(string $path): void
    {
        $contents = (string) file_get_contents($path);

        foreach ([
            'Before claiming completion',
            'gates remain non-bypassable',
            'close also requires',
        ] as $claim) {
            self::assertStringNotContainsString(
                $claim,
                $contents,
                basename(dirname($path)) . ' restates what close requires ("' . $claim
                . '"). The lifecycle kernel owns that decision: route to '
                . '`workflow status <task-id> --format=json` and obey '
                . 'references.<name>.gate, its reason, and next_action.',
            );
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('skillDocuments')]
    public function testSkillsDoNotHardcodeConfigurableArtifactPaths(string $path): void
    {
        $contents = (string) file_get_contents($path);

        foreach (self::CONFIGURABLE_SKILL_PATHS as $hardCoded) {
            self::assertStringNotContainsString(
                $hardCoded,
                $contents,
                basename(dirname($path)) . ' hardcodes configurable state path "' . $hardCoded
                . '". Use an owned <...-root> placeholder and `agent-loop init paths` instead.',
            );
        }
    }

    public function testExecutableGuidanceDoesNotTeachRetiredCommandsEvenWhenWrapped(): void
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
            $contents = preg_replace('/\s+/', ' ', (string) file_get_contents($path));
            self::assertIsString($contents);
            foreach (self::RETIRED_COMMANDS as $retired) {
                self::assertStringNotContainsString(
                    $retired,
                    $contents,
                    basename($path) . ' still teaches the retired command form "' . $retired . '".',
                );
            }
        }
    }

    public function testWorkflowAndInitCommandsShownBySkillsExistInLiveHelp(): void
    {
        $root = dirname(__DIR__);
        $help = [];
        foreach (['workflow', 'init'] as $namespace) {
            ob_start();
            self::assertSame(0, (new Dispatcher($root))->run(['agent-loop', $namespace, 'help']));
            $captured = preg_replace('/\s+/', ' ', (string) ob_get_clean());
            self::assertIsString($captured);
            $help[$namespace] = $captured;
        }

        foreach (self::skillDocuments() as [$path]) {
            $contents = preg_replace('/\s+/', ' ', (string) file_get_contents($path));
            self::assertIsString($contents);
            preg_match_all(
                '#vendor/bin/agent-loop\s+(workflow|init)\s+([a-z][a-z0-9:-]*)#',
                $contents,
                $matches,
                PREG_SET_ORDER,
            );
            foreach ($matches as $match) {
                $namespace = $match[1];
                $command = $match[2];
                self::assertStringContainsString(
                    'agent-loop ' . $namespace . ' ' . $command,
                    $help[$namespace],
                    basename(dirname($path)) . ' teaches unknown ' . $namespace . ' command "' . $command . '".',
                );
            }
        }
    }

    public function testRecallCandidateDogfoodChecksOutTheDeclaredMinimumRelease(): void
    {
        $root = dirname(__DIR__);
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertIsArray($composer['require'] ?? null);
        $constraint = $composer['require']['voku/agent-recall-compiler'] ?? null;
        self::assertIsString($constraint);
        self::assertMatchesRegularExpression('/^\^\d+\.\d+\.\d+$/', $constraint);
        $minimumRelease = substr($constraint, 1);

        foreach (['acceptance-criteria-candidate.yml', 'prompt-primitives-candidate.yml'] as $workflow) {
            $contents = (string) file_get_contents($root . '/.github/workflows/' . $workflow);
            self::assertStringContainsString(
                'ref: ' . $minimumRelease,
                $contents,
                $workflow . ' must dogfood the minimum released Recall version declared by composer.json.',
            );
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
