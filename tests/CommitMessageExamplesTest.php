<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\GitHooks\CommitMessageExamples;
use voku\AgentLoop\GitHooks\GitHookConfig;
use voku\AgentLoop\GitHooks\GitHooksCli;

/**
 * @internal
 */
final class CommitMessageExamplesTest extends TestCase
{
    private const string GOOD = "fix(parser): reject empty input\n\nWHY:\n- Empty input crashed the tokenizer last week.\n\nRefs: PROJ-12\n";

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-commit-examples-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/.agent-loop', 0o775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testExamplesThatBehaveAsDeclaredProduceNoProblems(): void
    {
        $examples = new CommitMessageExamples($this->config(
            pass: [self::GOOD],
            fail: ["Fixed things\n\nWHY:\n- x\n", self::GOOD . "Co-Authored-By: Some Assistant <noreply@example.test>\n"],
        ));

        self::assertTrue($examples->declared());
        self::assertSame(1, $examples->passCount());
        self::assertSame(2, $examples->failCount());
        self::assertSame([], $examples->problems());
    }

    public function testAPassExampleThatTheRulesRejectIsReported(): void
    {
        $examples = new CommitMessageExamples($this->config(pass: ["fix(parser): reject empty input\n"], fail: []));

        $problems = $examples->problems();

        self::assertCount(1, $problems);
        self::assertStringStartsWith('pass example #1 is rejected: ', $problems[0]);
    }

    public function testAFailExampleThatTheRulesAcceptIsReportedWithItsFirstLine(): void
    {
        // The forbidden pattern misses the text it was written for: the exact failure a plain "hook is installed" check cannot see.
        $examples = new CommitMessageExamples($this->config(
            pass: [],
            fail: [self::GOOD . "Co-Authored-By: Some Assistant <noreply@example.test>\n"],
            forbidden: [['pattern' => '/^Signed-Off-By:/im', 'message' => 'no sign-off trailer']],
        ));

        self::assertSame(
            ['fail example #1 is accepted: "fix(parser): reject empty input"'],
            $examples->problems(),
        );
    }

    public function testWithoutDeclaredExamplesNothingIsClaimed(): void
    {
        $examples = new CommitMessageExamples(GitHookConfig::fromArray([]));

        self::assertFalse($examples->declared());
        self::assertSame([], $examples->problems());
    }

    public function testEmptyAndNonStringEntriesAreIgnored(): void
    {
        $config = GitHookConfig::fromArray(['commit_msg' => ['examples' => ['pass' => ['', 3, null], 'fail' => 'text']]]);

        self::assertFalse((new CommitMessageExamples($config))->declared());
    }

    public function testVerifyCommandReportsSuccessAndFailureThroughItsExitCode(): void
    {
        $this->writeRules(pass: [self::GOOD], fail: ["Fixed things\n"]);

        [$exit, $output] = $this->verify();
        self::assertSame(0, $exit);
        self::assertSame("Commit message examples verified: 1 pass, 1 fail.\n", $output);

        $this->writeRules(pass: [self::GOOD], fail: [self::GOOD]);

        [$exit, $output] = $this->verify();
        self::assertSame(1, $exit);
        self::assertStringContainsString('fail example #1 is accepted: "fix(parser): reject empty input"', $output);
    }

    public function testVerifyCommandWithoutExamplesIsANoOp(): void
    {
        file_put_contents($this->root . '/.agent-loop/githooks.json', "{}\n");

        [$exit, $output] = $this->verify();

        self::assertSame(0, $exit);
        self::assertSame("No commit_msg.examples declared; nothing to verify.\n", $output);
    }

    /**
     * @param list<string>                                      $pass
     * @param list<string>                                      $fail
     * @param list<array{pattern: string, message: string}>|null $forbidden
     */
    private function config(array $pass, array $fail, ?array $forbidden = null): GitHookConfig
    {
        return GitHookConfig::fromArray(['commit_msg' => $this->commitMessageRules($pass, $fail, $forbidden)]);
    }

    /**
     * @param list<string>                                      $pass
     * @param list<string>                                      $fail
     * @param list<array{pattern: string, message: string}>|null $forbidden
     *
     * @return array<string, mixed>
     */
    private function commitMessageRules(array $pass, array $fail, ?array $forbidden = null): array
    {
        return [
            'header_pattern' => '/^(feat|fix|docs)(\([a-z0-9-]+\))?: .+$/',
            'header_hint' => 'type(scope): subject',
            'trivial_header_pattern' => '/^docs/',
            'required_section' => 'WHY',
            'forbidden_patterns' => $forbidden ?? [
                ['pattern' => '/^\s*Co-Authored-By:.*assistant/im', 'message' => 'no assistant trailer'],
            ],
            'examples' => ['pass' => $pass, 'fail' => $fail],
        ];
    }

    /**
     * @param list<string> $pass
     * @param list<string> $fail
     */
    private function writeRules(array $pass, array $fail): void
    {
        file_put_contents(
            $this->root . '/.agent-loop/githooks.json',
            json_encode(['commit_msg' => $this->commitMessageRules($pass, $fail)], \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function verify(): array
    {
        ob_start();
        $exit = (new GitHooksCli($this->root))->run(['verify']);

        return [$exit, (string) ob_get_clean()];
    }
}
