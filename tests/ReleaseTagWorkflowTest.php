<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

final class ReleaseTagWorkflowTest extends TestCase
{
    private const string TARGET_SHA = '1111111111111111111111111111111111111111';
    private const string VERSION = '1.0.0';

    public function testExistingMatchingTagSkipsHistoricalChangelogValidation(): void
    {
        $result = $this->runReleaseScript('matching', '# Changelog');

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringNotContainsString('show ' . self::TARGET_SHA . ':CHANGELOG.md', $result['git_log']);
        self::assertStringNotContainsString('tag -a', $result['git_log']);
    }

    public function testExistingTagAtAnotherCommitFails(): void
    {
        $result = $this->runReleaseScript('mismatched', '# Changelog');

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('Tag ' . self::VERSION . ' exists at 2222222222222222222222222222222222222222', $result['stderr']);
    }

    public function testNewTagRequiresChangelogSectionAtItsExactTarget(): void
    {
        $result = $this->runReleaseScript('absent', '# Changelog');

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('Release target ' . self::TARGET_SHA . ' does not contain the ' . self::VERSION . ' changelog section', $result['stderr']);
        self::assertStringNotContainsString('tag -a', $result['git_log']);
    }

    public function testNewTagAcceptsLargeTargetChangelogBeforeCreatingTheTag(): void
    {
        $result = $this->runReleaseScript(
            'absent',
            '## ' . self::VERSION . ' - 2026-09-19' . "\n" . str_repeat("- historical entry\n", 5000),
        );

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringContainsString('show ' . self::TARGET_SHA . ':CHANGELOG.md', $result['git_log']);
        self::assertStringContainsString('tag -a ' . self::VERSION . ' ' . self::TARGET_SHA, $result['git_log']);
        self::assertStringContainsString('push origin refs/tags/' . self::VERSION, $result['git_log']);
    }

    /**
     * @return array{exit: int, stderr: string, git_log: string}
     */
    private function runReleaseScript(string $tagState, string $targetChangelog): array
    {
        $root = sys_get_temp_dir() . '/agent-loop-release-tag-' . bin2hex(random_bytes(8));
        $bin = $root . '/bin';
        $markerDirectory = $root . '/.release';
        $gitLog = $root . '/git.log';
        $scriptPath = $root . '/release-tag.sh';

        self::assertTrue(mkdir($root));
        self::assertTrue(mkdir($bin));
        self::assertTrue(mkdir($markerDirectory));

        try {
            self::assertNotFalse(file_put_contents($markerDirectory . '/' . self::VERSION . '.json', json_encode([
                'version' => self::VERSION,
                'target_sha' => self::TARGET_SHA,
            ], JSON_THROW_ON_ERROR)));
            self::assertNotFalse(file_put_contents($root . '/CHANGELOG.md', '# Changelog'));
            self::assertNotFalse(file_put_contents($scriptPath, $this->releaseScript()));
            self::assertTrue(chmod($scriptPath, 0700));
            self::assertNotFalse(file_put_contents($bin . '/git', $this->fakeGitScript()));
            self::assertTrue(chmod($bin . '/git', 0700));
            self::assertNotFalse(file_put_contents($bin . '/jq', $this->fakeJqScript()));
            self::assertTrue(chmod($bin . '/jq', 0700));

            $result = $this->runProcess(['bash', $scriptPath], $root, [
                'FAKE_GIT_LOG' => $gitLog,
                'FAKE_TAG_STATE' => $tagState,
                'FAKE_TARGET_CHANGELOG' => $targetChangelog,
                'FAKE_TARGET_SHA' => self::TARGET_SHA,
                'FAKE_VERSION' => self::VERSION,
                'PATH' => $bin . ':' . getenv('PATH'),
            ]);

            return [
                'exit' => $result['exit'],
                'stderr' => $result['stderr'],
                'git_log' => file_exists($gitLog) ? (string) file_get_contents($gitLog) : '',
            ];
        } finally {
            foreach ([$markerDirectory . '/' . self::VERSION . '.json', $root . '/CHANGELOG.md', $scriptPath, $bin . '/git', $bin . '/jq', $gitLog] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($bin);
            rmdir($markerDirectory);
            rmdir($root);
        }
    }

    private function releaseScript(): string
    {
        $workflow = file_get_contents(__DIR__ . '/../.github/workflows/release-tag.yml');
        self::assertIsString($workflow);

        self::assertSame(1, preg_match('~^        run: \|\R(?<script>(?:^ {10}.*\R|^\R)*)~m', $workflow, $matches));
        if (!isset($matches['script'])) {
            throw new \RuntimeException('Release workflow has no executable tag script.');
        }
        $script = preg_replace('~^ {10}~m', '', $matches['script']);
        self::assertIsString($script);

        return $script;
    }

    private function fakeGitScript(): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

printf '%s\n' "$*" >> "$FAKE_GIT_LOG"
case "$1" in
  rev-parse)
    if [[ "$2" == 'HEAD' ]]; then
      printf '%s\n' "$FAKE_TARGET_SHA"
      exit 0
    fi
    if [[ "$2" == '-q' && "$3" == '--verify' ]]; then
      [[ "$FAKE_TAG_STATE" == 'absent' ]] && exit 1
      exit 0
    fi
    ;;
  cat-file|merge-base|config|tag|push)
    exit 0
    ;;
  rev-list)
    if [[ "$FAKE_TAG_STATE" == 'mismatched' ]]; then
      printf '%s\n' '2222222222222222222222222222222222222222'
    else
      printf '%s\n' "$FAKE_TARGET_SHA"
    fi
    exit 0
    ;;
  show)
    printf '%s\n' "$FAKE_TARGET_CHANGELOG"
    exit 0
    ;;
esac

printf 'Unexpected git invocation: %s\n' "$*" >&2
exit 1
BASH;
    }

    private function fakeJqScript(): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

case "$2" in
  .version)
    printf '%s\n' "$FAKE_VERSION"
    ;;
  .target_sha)
    printf '%s\n' "$FAKE_TARGET_SHA"
    ;;
  *)
    printf 'Unexpected jq query: %s\n' "$2" >&2
    exit 1
    ;;
esac
BASH;
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $environment
     *
     * @return array{exit: int, stderr: string}
     */
    private function runProcess(array $command, string $cwd, array $environment): array
    {
        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $cwd,
            $environment,
        );
        self::assertIsResource($process);

        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        self::assertIsString($stderr);

        return ['exit' => $exit, 'stderr' => $stderr];
    }
}
