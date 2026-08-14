<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit\Verify;

/**
 * Grades the agent's probe answers against the verifier-owned key.
 *
 * An accepted answer in the key is one complete acceptable answer, and the agent answers with a
 * list of ids. Both sides are reduced to a canonical set before comparison, so ordering and
 * duplication never decide a grade - and equality is required, not containment: a probe asking
 * which callers exist is not answered by naming one of three.
 *
 * An empty slot grades as `missing`, never as `failed`. "Did not answer" and "answered wrongly"
 * are different facts about an agent, and collapsing them would hide which one happened.
 */
final readonly class ProbeGrader
{
    /**
     * @param list<array<string, mixed>> $probes plan entries
     * @param array<string, mixed> $key `probes` section of verification-key.json
     * @param array<string, mixed> $answers `probe_answers` from agent-result.json
     *
     * @return list<array{id: string, status: string, given: list<string>, accepted: list<string>}>
     */
    public function grade(array $probes, array $key, array $answers): array
    {
        $graded = [];
        foreach ($probes as $probe) {
            $id = $probe['id'] ?? null;
            if (!is_string($id) || $id === '') {
                continue;
            }

            $given = $this->canonical($answers[$id] ?? null);
            $accepted = $this->acceptedAnswers($key[$id] ?? null);

            $graded[] = [
                'id' => $id,
                'status' => $this->status($given, $accepted),
                'given' => $given,
                'accepted' => $accepted,
            ];
        }

        return $graded;
    }

    /**
     * @param list<string> $given
     * @param list<string> $accepted
     */
    private function status(array $given, array $accepted): string
    {
        if ($given === []) {
            return 'missing';
        }
        // A probe with no key entry cannot be graded either way; treating it as passed would invent
        // a result the key never asserted.
        if ($accepted === []) {
            return 'missing';
        }

        foreach ($accepted as $candidate) {
            if ($given === $this->canonical($candidate)) {
                return 'passed';
            }
        }

        return 'failed';
    }

    /**
     * @return list<string>
     */
    private function acceptedAnswers(mixed $entry): array
    {
        if (!is_array($entry) || !is_array($entry['accepted_answers'] ?? null)) {
            return [];
        }

        $accepted = [];
        foreach ($entry['accepted_answers'] as $answer) {
            if (is_string($answer) && trim($answer) !== '') {
                $accepted[] = $answer;
            }
        }

        return $accepted;
    }

    /**
     * Reduces an answer - a list, or one string that may itself name several ids - to a canonical
     * set, so `a, b`, `["b","a"]` and `["a","b","a"]` all compare equal.
     *
     * @return list<string>
     */
    private function canonical(mixed $answer): array
    {
        $parts = [];
        foreach (is_array($answer) ? $answer : [$answer] as $item) {
            if (!is_string($item)) {
                continue;
            }
            foreach (preg_split('/[,\s]+/', $item) ?: [] as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $parts[$part] = true;
                }
            }
        }

        // PHP turns an integer-like array key into an int, so a numeric evidence id
        // came back out of `array_keys()` as 7 rather than "7" and reached the graded
        // report - and the declared `list<string>` - as a JSON number.
        $canonical = array_map(strval(...), array_keys($parts));
        sort($canonical, SORT_STRING);

        return $canonical;
    }
}
