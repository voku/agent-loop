<?php

declare(strict_types=1);

namespace voku\AgentLoop\GitHooks;

/**
 * Proves that the configured commit convention still behaves the way its own examples say.
 *
 * A hook that is installed and current can still enforce the wrong thing: a pattern that never matches,
 * or an escaped backslash lost on the way into JSON. The project therefore declares, next to the rules,
 * messages the convention must accept (`pass`) and must reject (`fail`); this class runs them through the
 * same {@see CommitMessageValidator} the `commit-msg` hook uses.
 */
final readonly class CommitMessageExamples
{
    public function __construct(private GitHookConfig $config)
    {
    }

    public function declared(): bool
    {
        return $this->passCount() + $this->failCount() > 0;
    }

    public function passCount(): int
    {
        return count($this->config->commitMessageExamples['pass']);
    }

    public function failCount(): int
    {
        return count($this->config->commitMessageExamples['fail']);
    }

    /**
     * @return list<string> one line per example that does not behave as declared; empty when all do
     */
    public function problems(): array
    {
        $validator = new CommitMessageValidator($this->config);
        $problems = [];

        foreach ($this->config->commitMessageExamples['pass'] as $index => $message) {
            $violations = $validator->validate($message);
            if ($violations !== []) {
                $problems[] = sprintf('pass example #%d is rejected: %s', $index + 1, $violations[0]);
            }
        }

        foreach ($this->config->commitMessageExamples['fail'] as $index => $message) {
            if ($validator->validate($message) === []) {
                $problems[] = sprintf('fail example #%d is accepted: "%s"', $index + 1, $this->firstLine($message));
            }
        }

        return $problems;
    }

    private function firstLine(string $message): string
    {
        $lines = preg_split('/\R/', $message) ?: [];

        return trim($lines[0] ?? '');
    }
}
