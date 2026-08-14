<?php

declare(strict_types=1);

namespace voku\AgentLoop\Dogfood;

/**
 * Asserts that every project PHPStan rule still rejects its own fixture.
 *
 * The fixtures are deliberately broken code, so the expected result is exit 1
 * with a specific diagnostic per rule. A rule that silently stops firing looks
 * exactly like a rule with nothing to report, which is why the expected
 * messages are listed rather than counted.
 *
 * Runs out of process because `PHPStan\Testing\RuleTestCase` cannot share a
 * process with the Composer-based PHPStan discovery agent-map performs in the
 * ordinary PHPUnit run - the rule that guards that is itself in this list.
 */
final readonly class ProjectRuleFixtureCheck
{
    /** @var non-empty-list<non-empty-string> */
    public const array EXPECTED_DIAGNOSTICS = [
        'Workflow orchestration must not instantiate focused-package CLI voku\AgentSession\Cli.',
        'Workflow commands must not accept --learning-root.',
        // Deliberately truncated before the retired path itself: spelling it here
        // would make this list trip the very rule it asserts.
        'Production code must not name retired state root',
        'proc_open must receive an argv array, not shell-shaped command text.',
        'Child PHP commands must place -n immediately after PHP_BINARY',
        'ProjectLayout::learningRoot() result is discarded.',
        'PHPDoc contract tags must be on separate lines',
        'Do not infer Git repository state from is_dir(.git)',
        'do not extend PHPStan\Testing\RuleTestCase in the PHPUnit suite',
    ];

    public function __construct(private ProcessRunner $runner)
    {
    }

    /** @return array{passed: bool, output: string, problems: list<string>} */
    public function run(): array
    {
        $result = $this->runner->run([
            $this->runner->vendorBinary('phpstan'),
            'analyse',
            '--configuration=phpstan/project-rule-test.neon',
            '--no-progress',
            '--error-format=raw',
        ]);
        $output = $result['stdout'] . $result['stderr'];

        return [
            'passed' => ($problems = $this->problems($result['exit_code'], $output)) === [],
            'output' => $output,
            'problems' => $problems,
        ];
    }

    /** @return list<string> */
    private function problems(int $exitCode, string $output): array
    {
        $problems = [];
        if ($exitCode !== 1) {
            $problems[] = sprintf('Expected the fixture analysis to fail with exit 1, got %d.', $exitCode);
        }
        foreach (self::EXPECTED_DIAGNOSTICS as $expected) {
            if (!str_contains($output, $expected)) {
                $problems[] = 'Expected project PHPStan error was not reported: ' . $expected;
            }
        }

        return $problems;
    }
}
