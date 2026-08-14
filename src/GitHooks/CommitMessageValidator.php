<?php

declare(strict_types=1);

namespace voku\AgentLoop\GitHooks;

/**
 * Validates a commit message against the repository's convention.
 *
 * The rules are always the same shape - a header that matches a pattern, no
 * unreplaced template markers, a required section that actually says something,
 * and a nudge when that section is short and vague. The pattern, the section name,
 * and the wording come from `.agent-loop/githooks.json`, so a project changes its
 * convention without touching a hook script.
 *
 * Every rule reads the message Git will *store*, never the file the hook is handed.
 * At `commit-msg` time that file still carries Git's own comment block, the
 * `commit.template` this package installs, and whatever blank lines the editor left.
 * Judging the raw file made the template's own `WHY: [FILL]` guide line trip the
 * rule that guide exists to explain - an unfixable commit, because the offending
 * text is not something the committer typed - and made a message that merely started
 * one blank line too low report an empty header.
 *
 * What Git will store is not something this class is allowed to guess: whether
 * commentary survives, and what commentary even is, come from `commit.cleanup` and
 * `core.commentString`. {@see GitCommitCleanup} owns that question.
 */
final readonly class CommitMessageValidator
{
    private GitCommitCleanup $cleanup;

    public function __construct(private GitHookConfig $config, ?GitCommitCleanup $cleanup = null)
    {
        $this->cleanup = $cleanup ?? GitCommitCleanup::forMode('strip');
    }

    /**
     * @return list<string> violations, empty when the message is acceptable
     */
    public function validate(string $message): array
    {
        $lines = $this->cleanup->committedLines($message);
        $header = trim((string) ($lines[0] ?? ''));

        if ($header === '') {
            return $this->withHint('Empty commit header.');
        }

        if ($this->config->headerPattern !== '' && preg_match($this->config->headerPattern, $header) !== 1) {
            return $this->withHint('Invalid commit header: ' . $header);
        }

        $committedMessage = implode("\n", $lines);
        $violations = [];
        foreach ($this->config->forbiddenPatterns as $forbidden) {
            if (preg_match($forbidden['pattern'], $committedMessage) === 1) {
                $violations[] = $forbidden['message'];
            }
        }
        if ($violations !== []) {
            return $violations;
        }

        $section = $this->config->requiredSection;
        if ($section === null || $this->isTrivial($header)) {
            return [];
        }

        $body = $this->bodyLines($lines);
        if ($body === []) {
            return ['Missing commit body: a non-trivial commit needs a ' . $section . ' section.'];
        }

        $sectionContent = $this->sectionContent($body, $section);
        if ($sectionContent === null) {
            return [
                'No ' . $section . ' section found.',
                'Add what broke, which constraint forced this, the ticket, the trade-off, or the revert impact.',
            ];
        }

        if ($sectionContent === []) {
            return [$section . ' section is empty: add at least one concrete reason.'];
        }

        return $this->vagueSectionViolations($sectionContent, $section);
    }

    private function isTrivial(string $header): bool
    {
        $trivialPattern = $this->config->trivialHeaderPattern;

        return $trivialPattern !== null && preg_match($trivialPattern, $header) === 1;
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function bodyLines(array $lines): array
    {
        $body = [];
        foreach (array_slice($lines, 1) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $body[] = $trimmed;
        }

        return $body;
    }

    /**
     * @param list<string> $body
     * @return list<string>|null null when the section is absent, otherwise its content lines
     */
    private function sectionContent(array $body, string $section): ?array
    {
        $sectionPattern = '/^' . preg_quote($section, '/') . ':/i';
        $content = [];
        $insideSection = false;

        foreach ($body as $line) {
            if (preg_match($sectionPattern, $line) === 1) {
                $insideSection = true;
                $inline = trim((string) preg_replace($sectionPattern, '', $line));
                if ($inline !== '') {
                    $content[] = $inline;
                }

                continue;
            }

            if (!$insideSection) {
                continue;
            }

            // Another all-caps section header ends this one.
            if (preg_match('/^[A-Z]+:/', $line) === 1) {
                break;
            }

            $content[] = $line;
        }

        if (!$insideSection) {
            return null;
        }

        return array_values(array_filter(
            $content,
            static fn (string $line): bool => trim(str_replace(['-', '*', '•'], '', $line)) !== '',
        ));
    }

    /**
     * @param list<string> $sectionContent
     * @return list<string>
     */
    private function vagueSectionViolations(array $sectionContent, string $section): array
    {
        if ($this->config->vaguePhrases === []) {
            return [];
        }

        $text = strtolower(implode("\n", $sectionContent));
        if (str_word_count($text) >= $this->config->vagueWordThreshold) {
            return [];
        }

        foreach ($this->config->vaguePhrases as $phrase) {
            if (preg_match('/\b' . preg_quote(strtolower($phrase), '/') . '\b/', $text) === 1) {
                return [
                    $section . ' section is brief and vague ("' . $phrase . '").',
                    'Name the metric, the failure, the ticket, or the risk instead.',
                ];
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function withHint(string $violation): array
    {
        $violations = [$violation];
        if ($this->config->headerHint !== '') {
            $violations[] = 'Required header: ' . $this->config->headerHint;
        }

        return $violations;
    }
}
