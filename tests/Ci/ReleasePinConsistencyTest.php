<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests\Ci;

use Composer\Semver\Semver;
use PHPUnit\Framework\TestCase;

/**
 * The installed-consumer workflows pin exact released owner tags on purpose: the pin is the
 * immutable evidence the proof ran against that release. The cost is that one owner release has
 * to be reflected in several places, and a half-updated set only fails in CI. This test makes the
 * disagreement fail locally, in `composer ci`, before the PR is opened.
 *
 * Network-free by default. `AGENT_LOOP_VERIFY_RELEASE_PIN_SHAS=1` (or `composer verify:release-pins`)
 * additionally compares each hardcoded commit SHA with the remote tag.
 */
final class ReleasePinConsistencyTest extends TestCase
{
    private const OWNER = '/voku\/(agent-[a-z-]+)/';

    public function testCurrentWorkflowPinsAreConsistentWithComposerJson(): void
    {
        $violations = $this->violations($this->workflows(), $this->constraints());

        self::assertSame([], $violations, implode("\n", $violations));
    }

    public function testTheScannerActuallySeesTheCurrentPins(): void
    {
        $pins = [];
        foreach ($this->workflows() as $yaml) {
            foreach ($this->pins($yaml) as $pin) {
                $pins[$pin['package']] = true;
            }
        }

        self::assertArrayHasKey('agent-map', $pins);
        self::assertArrayHasKey('agent-edit', $pins);
        self::assertArrayHasKey('agent-recall-compiler', $pins);
    }

    public function testARefOutsideTheComposerConstraintIsReported(): void
    {
        $violations = $this->violations(
            ['a.yml' => $this->workflow('agent-map', '0.21.0')],
            ['agent-map' => '^0.22.0'],
        );

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

    public function testNonReleaseRefsAndOwnersOutsideComposerAreIgnored(): void
    {
        $yaml = "repository: voku/agent-skills\n          ref: c9e3b2966dc462c3ae9e9758f8bd39e0e85e7bf8\n";

        self::assertSame([], $this->violations(['a.yml' => $yaml], ['agent-map' => '^0.22.0']));
    }

    public function testHardcodedShasMatchTheRemoteTagsWhenVerificationIsRequested(): void
    {
        if (getenv('AGENT_LOOP_VERIFY_RELEASE_PIN_SHAS') !== '1') {
            self::markTestSkipped('Set AGENT_LOOP_VERIFY_RELEASE_PIN_SHAS=1 (or run `composer verify:release-pins`) to compare SHAs with the remote tags.');
        }

        $checked = 0;
        $mismatches = [];
        foreach ($this->workflows() as $file => $yaml) {
            foreach ($this->pins($yaml) as $pin) {
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
     * @param array<string, string> $files    workflow path => contents
     * @param array<string, string> $constraints owner (without vendor) => composer constraint
     *
     * @return list<string>
     */
    private function violations(array $files, array $constraints): array
    {
        $violations = [];
        foreach ($files as $file => $yaml) {
            foreach ($this->pins($yaml) as $pin) {
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
     * Release pins of one workflow: exact `X.Y.Z` tag checkouts of a voku owner, with the pins that
     * must agree with them. Commit-SHA refs (not releases) are deliberately out of scope.
     *
     * @return list<array{package: string, ref: string, versions: list<string>, resolved: list<string>, sha: ?string, shaDeclared: bool}>
     */
    private function pins(string $yaml): array
    {
        $pins = [];
        preg_match_all('/repository:\s*voku\/(agent-[a-z-]+)\s*\n\s*ref:\s*(\d+\.\d+\.\d+)\b/', $yaml, $checkouts, PREG_SET_ORDER);
        foreach ($checkouts as $checkout) {
            [, $package, $ref] = $checkout;
            preg_match_all('/"voku\/' . preg_quote($package, '/') . '":\s*"(\d+\.\d+\.\d+)"/', $yaml, $versions);
            preg_match_all('/index\("(\d+\.\d+\.\d+)"\)[^\n]*resolved-' . preg_quote($package, '/') . '\.json/', $yaml, $resolved);
            $shaDeclared = preg_match('/released-' . preg_quote($package, '/') . '\s+rev-parse/', $yaml) === 1;
            $sha = preg_match('/released-' . preg_quote($package, '/') . '\s+rev-parse[^\n]*=\s*"([0-9a-f]{40})"/', $yaml, $match) === 1
                ? $match[1]
                : null;

            $pins[] = [
                'package' => $package,
                'ref' => $ref,
                'versions' => $versions[1],
                'resolved' => $resolved[1],
                'sha' => $sha,
                'shaDeclared' => $shaDeclared,
            ];
        }

        return $pins;
    }

    /** @return array<string, string> workflow path => contents */
    private function workflows(): array
    {
        $files = [];
        foreach (glob(dirname(__DIR__, 2) . '/.github/workflows/*.yml') ?: [] as $path) {
            $files['.github/workflows/' . basename($path)] = (string) file_get_contents($path);
        }
        self::assertNotSame([], $files, 'No workflows found.');

        return $files;
    }

    /** @return array<string, string> */
    private function constraints(): array
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $constraints = [];
        foreach (['require', 'require-dev'] as $section) {
            foreach ($composer[$section] ?? [] as $name => $constraint) {
                if (preg_match(self::OWNER, (string) $name, $match) === 1 && str_starts_with((string) $name, 'voku/')) {
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
        return <<<YAML
        - uses: actions/checkout@v6
          with:
            repository: voku/{$package}
            ref: {$version}
            path: build/released-{$package}
        - run: |
            test "\$(git -C build/released-{$package} rev-parse 'HEAD^{commit}')" = "0123456789abcdef0123456789abcdef01234567"
        - run: |
            {"versions": {"voku/{$package}": "{$version}"}}
            jq -e '.versions | index("{$version}") != null' resolved-{$package}.json >/dev/null
        YAML;
    }
}
