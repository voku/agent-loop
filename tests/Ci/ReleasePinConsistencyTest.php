<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests\Ci;

use Composer\Semver\Semver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The installed-consumer workflows pin exact released owner tags on purpose: the pin is the
 * immutable evidence the proof ran against that release. The cost is that one owner release has
 * to be reflected in several places, and a half-updated set only fails in CI. This test makes the
 * disagreement fail locally, in `composer ci`, before the PR is opened.
 *
 * Workflows are parsed as YAML and checked per job. The scanner fails closed: a pin of a
 * composer-managed owner that it cannot interpret is a violation, never a silent skip.
 *
 * Network-free by default. `AGENT_LOOP_VERIFY_RELEASE_PIN_SHAS=1` (or `composer verify:release-pins`)
 * additionally compares each hardcoded commit SHA with the remote tag.
 */
final class ReleasePinConsistencyTest extends TestCase
{
    private const SEMVER = '/^\d+\.\d+\.\d+$/';

    public function testCurrentWorkflowPinsAreConsistentWithComposerJson(): void
    {
        $violations = $this->violations($this->workflows(), $this->constraints());

        self::assertSame([], $violations, implode("\n", $violations));
    }

    public function testTheScannerActuallySeesTheCurrentPins(): void
    {
        $owners = [];
        foreach ($this->workflows() as $yaml) {
            foreach ($this->pins($yaml, 'x.yml', $this->constraints())['pins'] as $pin) {
                $owners[$pin['package']] = true;
            }
        }

        self::assertArrayHasKey('agent-map', $owners);
        self::assertArrayHasKey('agent-edit', $owners);
        self::assertArrayHasKey('agent-recall-compiler', $owners);
    }

    public function testARefOutsideTheComposerConstraintIsReported(): void
    {
        $violations = $this->violations(['a.yml' => $this->workflow('agent-map', '0.21.0')], ['agent-map' => '^0.22.0']);

        self::assertCount(1, $violations);
        self::assertStringContainsString('a.yml', $violations[0]);
        self::assertStringContainsString('0.21.0', $violations[0]);
        self::assertStringContainsString('^0.22.0', $violations[0]);
    }

    public function testARefThatDiffersFromTheResolvedVersionAssertionIsReported(): void
    {
        $yaml = str_replace('index("0.22.0")', 'index("0.21.0")', $this->workflow('agent-map', '0.22.0'));

        $violations = $this->violations(['a.yml' => $yaml], ['agent-map' => '^0.22.0']);

        self::assertCount(1, $violations);
        self::assertStringContainsString('resolved-version assertion', $violations[0]);
    }

    public function testAVersionsJsonThatDiffersFromTheRefIsReported(): void
    {
        $yaml = str_replace('"voku/agent-map": "0.22.0"', '"voku/agent-map": "0.21.0"', $this->workflow('agent-map', '0.22.0'));

        $violations = $this->violations(['a.yml' => $yaml], ['agent-map' => '^0.22.0']);

        self::assertCount(1, $violations);
        self::assertStringContainsString('"versions" pin', $violations[0]);
    }

    public function testAnUnparseableShaPinIsReportedInsteadOfSkipped(): void
    {
        $yaml = preg_replace('/"[0-9a-f]{40}"/', '"not-a-sha"', $this->workflow('agent-map', '0.22.0')) ?? '';

        $violations = $this->violations(['a.yml' => $yaml], ['agent-map' => '^0.22.0']);

        self::assertCount(1, $violations);
        self::assertStringContainsString('unparseable', $violations[0]);
    }

    public function testAnAssertionThatIsNotAPlainVersionIsReportedInsteadOfIgnored(): void
    {
        $yaml = str_replace('index("0.22.0")', 'index("0.22.0-rc1")', $this->workflow('agent-map', '0.22.0'));

        $violations = $this->violations(['a.yml' => $yaml], ['agent-map' => '^0.22.0']);

        self::assertCount(1, $violations);
        self::assertStringContainsString('not a plain X.Y.Z version', $violations[0]);
    }

    public function testAQuotedRefIsStillChecked(): void
    {
        $yaml = str_replace('ref: 0.21.0', "ref: '0.21.0'", $this->workflow('agent-map', '0.21.0'));

        $violations = $this->violations(['a.yml' => $yaml], ['agent-map' => '^0.22.0']);

        self::assertNotSame([], $violations);
        self::assertStringContainsString('outside composer.json constraint', $violations[0]);
    }

    public function testAReorderedCheckoutIsStillChecked(): void
    {
        $yaml = <<<'YAML'
        jobs:
          proof:
            steps:
              - uses: actions/checkout@v6
                with:
                  path: build/released-agent-map
                  fetch-depth: 0
                  ref: 0.21.0
                  repository: voku/agent-map
        YAML;

        $violations = $this->violations(['a.yml' => $yaml], ['agent-map' => '^0.22.0']);

        self::assertCount(1, $violations);
        self::assertStringContainsString('outside composer.json constraint', $violations[0]);
    }

    public function testARefOfAManagedOwnerThatIsNotAReleaseTagIsReported(): void
    {
        $yaml = str_replace('ref: 0.22.0', 'ref: 0.22', $this->workflow('agent-map', '0.22.0'));

        $violations = $this->violations(['a.yml' => $yaml], ['agent-map' => '^0.22.0']);

        self::assertNotSame([], $violations);
        self::assertStringContainsString('not a release tag', $violations[0]);
    }

    public function testTwoCheckoutsOfOneOwnerInOneJobAreReportedAsAmbiguous(): void
    {
        $yaml = <<<'YAML'
        jobs:
          proof:
            steps:
              - uses: actions/checkout@v6
                with: {repository: voku/agent-map, ref: 0.22.0}
              - uses: actions/checkout@v6
                with: {repository: voku/agent-map, ref: 0.22.1}
        YAML;

        $violations = $this->violations(['a.yml' => $yaml], ['agent-map' => '^0.22.0']);

        self::assertCount(1, $violations);
        self::assertStringContainsString('more than once', $violations[0]);
    }

    public function testTheSameOwnerInTwoJobsIsCheckedPerJob(): void
    {
        $job = static fn (string $v): string => "  proof-{$v}:\n    steps:\n      - uses: actions/checkout@v6\n        with:\n          repository: voku/agent-map\n          ref: {$v}\n";
        $yaml = "jobs:\n" . $job('0.22.0') . $job('0.22.1');

        self::assertSame([], $this->violations(['a.yml' => $yaml], ['agent-map' => '^0.22.0']));
    }

    public function testInvalidYamlIsReportedInsteadOfSkipped(): void
    {
        $violations = $this->violations(['a.yml' => "jobs: [unclosed\n"], ['agent-map' => '^0.22.0']);

        self::assertCount(1, $violations);
        self::assertStringContainsString('not valid YAML', $violations[0]);
    }

    public function testNonReleaseRefsAndOwnersOutsideComposerAreIgnored(): void
    {
        $yaml = "jobs:\n  p:\n    steps:\n      - uses: actions/checkout@v6\n        with:\n          repository: voku/agent-skills\n          ref: c9e3b2966dc462c3ae9e9758f8bd39e0e85e7bf8\n";

        self::assertSame([], $this->violations(['a.yml' => $yaml], ['agent-map' => '^0.22.0']));
    }

    public function testBothWorkflowExtensionsAreDiscovered(): void
    {
        $directory = sys_get_temp_dir() . '/agent-loop-pin-glob-' . bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory . '/a.yml', 'jobs: {}');
        file_put_contents($directory . '/b.yaml', 'jobs: {}');

        try {
            self::assertSame(['a.yml', 'b.yaml'], array_keys($this->workflowFiles($directory)));
        } finally {
            unlink($directory . '/a.yml');
            unlink($directory . '/b.yaml');
            rmdir($directory);
        }
    }

    public function testHardcodedShasMatchTheRemoteTagsWhenVerificationIsRequested(): void
    {
        if (getenv('AGENT_LOOP_VERIFY_RELEASE_PIN_SHAS') !== '1') {
            self::markTestSkipped('Set AGENT_LOOP_VERIFY_RELEASE_PIN_SHAS=1 (or run `composer verify:release-pins`) to compare SHAs with the remote tags.');
        }

        $checked = 0;
        $mismatches = [];
        foreach ($this->workflows() as $file => $yaml) {
            foreach ($this->pins($yaml, $file, $this->constraints())['pins'] as $pin) {
                if ($pin['sha'] === null) {
                    continue;
                }
                $remote = $this->remoteTagCommit($pin['package'], $pin['ref']);
                ++$checked;
                if ($remote !== $pin['sha']) {
                    $mismatches[] = sprintf(
                        '%s: voku/%s %s is pinned to %s but the remote tag points at %s.',
                        $file,
                        $pin['package'],
                        $pin['ref'],
                        $pin['sha'],
                        $remote ?? 'nothing (tag not found)',
                    );
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'No SHA pins were found to verify.');
        self::assertSame([], $mismatches, implode("\n", $mismatches));
    }

    /**
     * @param array<string, string> $files       workflow path => YAML contents
     * @param array<string, string> $constraints owner (without vendor) => composer constraint
     *
     * @return list<string>
     */
    private function violations(array $files, array $constraints): array
    {
        $violations = [];
        foreach ($files as $file => $yaml) {
            $scan = $this->pins($yaml, $file, $constraints);
            $violations = [...$violations, ...$scan['problems']];

            foreach ($scan['pins'] as $pin) {
                $package = $pin['package'];
                $ref = $pin['ref'];

                if (isset($constraints[$package]) && !Semver::satisfies($ref, $constraints[$package])) {
                    $violations[] = sprintf('%s: voku/%s is pinned to %s, outside composer.json constraint %s.', $file, $package, $ref, $constraints[$package]);
                }
                foreach ($pin['versions'] as $version) {
                    if ($version !== $ref) {
                        $violations[] = sprintf('%s: voku/%s ref %s disagrees with its "versions" pin %s.', $file, $package, $ref, $version);
                    }
                }
                foreach ($pin['resolved'] as $version) {
                    if ($version !== $ref) {
                        $violations[] = sprintf('%s: voku/%s ref %s disagrees with its resolved-version assertion %s.', $file, $package, $ref, $version);
                    }
                }
                if ($pin['shaDeclared'] && $pin['sha'] === null) {
                    $violations[] = sprintf('%s: voku/%s has a revision check whose commit SHA is unparseable.', $file, $package);
                }
            }
        }

        return $violations;
    }

    /**
     * Release pins of one workflow, read per job. Commit-SHA and expression refs are not releases and
     * are skipped, but only for owners composer.json does not manage; for a managed owner anything
     * that is not an exact `X.Y.Z` tag is a problem, never a silent skip.
     *
     * @param array<string, string> $constraints
     *
     * @return array{pins: list<array{package: string, ref: string, versions: list<string>, resolved: list<string>, sha: ?string, shaDeclared: bool}>, problems: list<string>}
     */
    private function pins(string $yaml, string $file, array $constraints): array
    {
        $pins = [];
        $problems = [];

        try {
            $workflow = Yaml::parse($yaml);
        } catch (ParseException $exception) {
            return ['pins' => [], 'problems' => [sprintf('%s: not valid YAML (%s); its release pins cannot be checked.', $file, $exception->getMessage())]];
        }

        $jobs = is_array($workflow) && is_array($workflow['jobs'] ?? null) ? $workflow['jobs'] : [];
        foreach ($jobs as $jobName => $job) {
            if (!is_array($job)) {
                continue;
            }
            $steps = is_array($job['steps'] ?? null) ? $job['steps'] : [];

            $runs = '';
            $checkouts = [];
            foreach ($steps as $step) {
                if (!is_array($step)) {
                    continue;
                }
                if (is_string($step['run'] ?? null)) {
                    $runs .= "\n" . $step['run'];
                }
                $with = is_array($step['with'] ?? null) ? $step['with'] : [];
                $repository = $with['repository'] ?? null;
                if (is_string($repository) && preg_match('#^voku/(agent-[a-z-]+)$#', $repository, $match) === 1) {
                    $checkouts[$match[1]][] = isset($with['ref']) ? (string) $with['ref'] : '';
                }
            }

            foreach ($checkouts as $package => $refs) {
                if (count($refs) > 1) {
                    $problems[] = sprintf('%s: job "%s" checks out voku/%s more than once, so its pins cannot be attributed.', $file, $jobName, $package);
                    continue;
                }
                $ref = $refs[0];
                if (preg_match(self::SEMVER, $ref) !== 1) {
                    $isOtherRevision = preg_match('/^[0-9a-f]{40}$/', $ref) === 1 || str_contains($ref, '${{');
                    if (isset($constraints[$package]) && !$isOtherRevision) {
                        $problems[] = sprintf('%s: job "%s" pins voku/%s to "%s", which is not a release tag (X.Y.Z).', $file, $jobName, $package, $ref);
                    }
                    continue;
                }

                $quoted = preg_quote($package, '/');
                $versions = $this->plainVersions('/"voku\/' . $quoted . '":\s*"([^"]*)"/', $runs, $file, $package, '"versions" pin', $problems);
                $resolved = $this->plainVersions('/index\("([^"]*)"\)[^\n]*resolved-' . $quoted . '\.json/', $runs, $file, $package, 'resolved-version assertion', $problems);
                $shaDeclared = preg_match('/released-' . $quoted . '\s+rev-parse/', $runs) === 1;
                $sha = preg_match('/released-' . $quoted . '\s+rev-parse[^\n]*=\s*"([0-9a-f]{40})"/', $runs, $shaMatch) === 1 ? $shaMatch[1] : null;

                $pins[] = [
                    'package' => $package,
                    'ref' => $ref,
                    'versions' => $versions,
                    'resolved' => $resolved,
                    'sha' => $sha,
                    'shaDeclared' => $shaDeclared,
                ];
            }
        }

        return ['pins' => $pins, 'problems' => $problems];
    }

    /**
     * @param list<string> $problems
     *
     * @return list<string>
     */
    private function plainVersions(string $pattern, string $text, string $file, string $package, string $label, array &$problems): array
    {
        preg_match_all($pattern, $text, $matches);
        $versions = [];
        foreach ($matches[1] as $version) {
            if (preg_match(self::SEMVER, $version) === 1) {
                $versions[] = $version;
                continue;
            }
            $problems[] = sprintf('%s: voku/%s %s "%s" is not a plain X.Y.Z version, so it cannot be compared with the checkout ref.', $file, $package, $label, $version);
        }

        return $versions;
    }

    /** @return array<string, string> workflow path => contents */
    private function workflows(): array
    {
        $files = $this->workflowFiles(dirname(__DIR__, 2) . '/.github/workflows');
        self::assertNotSame([], $files, 'No workflows found.');

        return $files;
    }

    /** @return array<string, string> file name => contents, for both .yml and .yaml */
    private function workflowFiles(string $directory): array
    {
        $paths = array_merge(glob($directory . '/*.yml') ?: [], glob($directory . '/*.yaml') ?: []);
        sort($paths);

        $files = [];
        foreach ($paths as $path) {
            $files[basename($path)] = (string) file_get_contents($path);
        }

        return $files;
    }

    /** @return array<string, string> */
    private function constraints(): array
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $constraints = [];
        foreach (['require', 'require-dev'] as $section) {
            foreach ($composer[$section] ?? [] as $name => $constraint) {
                if (preg_match('#^voku/(agent-[a-z-]+)$#', (string) $name, $match) === 1) {
                    $constraints[$match[1]] = (string) $constraint;
                }
            }
        }

        return $constraints;
    }

    private function remoteTagCommit(string $package, string $tag): ?string
    {
        $url = 'https://github.com/voku/' . $package . '.git';
        $output = [];
        exec(
            'git ls-remote ' . escapeshellarg($url) . ' ' . escapeshellarg('refs/tags/' . $tag . '^{}') . ' ' . escapeshellarg('refs/tags/' . $tag) . ' 2>/dev/null',
            $output,
        );
        $commits = [];
        foreach ($output as $line) {
            [$sha, $ref] = array_pad(explode("\t", $line, 2), 2, '');
            $commits[$ref] = $sha;
        }

        return $commits['refs/tags/' . $tag . '^{}'] ?? $commits['refs/tags/' . $tag] ?? null;
    }

    private function workflow(string $package, string $version): string
    {
        $sha = '0123456789abcdef0123456789abcdef01234567';

        return <<<YAML
        jobs:
          proof:
            steps:
              - uses: actions/checkout@v6
                with:
                  repository: voku/{$package}
                  ref: {$version}
                  path: build/released-{$package}
              - name: Prove revision
                run: |
                  test "\$(git -C build/released-{$package} rev-parse 'HEAD^{commit}')" = "{$sha}"
              - name: Prove resolution
                run: |
                  echo '{"versions": {"voku/{$package}": "{$version}"}}'
                  jq -e '.versions | index("{$version}") != null' resolved-{$package}.json >/dev/null
        YAML;
    }
}
