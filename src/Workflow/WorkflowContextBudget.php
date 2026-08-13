<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

final class WorkflowContextBudget
{
    /** @var array<string, true> */
    private const array UNVERIFIED_CATEGORIES = [
        'candidate_navigation' => true,
        'candidate_context' => true,
    ];

    /** @var list<string> */
    private array $lines = [];
    /** @var list<string> */
    private array $lineCategories = [];
    /** @var array<string, int> */
    private array $omitted = [];
    /** @var list<string> */
    private array $skipped = [];
    private int $bytes = 0;

    public function __construct(private readonly int $maxLines, private readonly int $maxBytes)
    {
    }

    public function section(string $name): void
    {
        $this->add('section', $name . ':');
    }

    public function add(string $category, string $line): void
    {
        $lineBytes = strlen($line) + 1;
        if (!$this->fits($lineBytes) && !$this->reclaimUnverifiedFor($category, $lineBytes)) {
            $this->omitted[$category] = ($this->omitted[$category] ?? 0) + 1;

            return;
        }
        $this->lines[] = $line;
        $this->lineCategories[] = $category;
        $this->bytes += $lineBytes;
    }

    public function skip(string $message): void
    {
        if (!in_array($message, $this->skipped, true)) {
            $this->skipped[] = $message;
        }
    }

    public function finish(): void
    {
        do {
            $required = $this->requiredLines();
            $requiredBytes = array_sum(array_map(static fn (string $line): int => strlen($line) + 1, $required));
            $removedAny = false;
            while ($this->lines !== [] && (count($this->lines) + count($required) > $this->maxLines || $this->bytes + $requiredBytes > $this->maxBytes)) {
                $this->dropLine($this->leastAuthoritativeLineIndex());
                $removedAny = true;
            }
        } while ($removedAny);
        foreach ($required as $line) {
            $this->lines[] = $line;
            $this->lineCategories[] = str_starts_with($line, '[SKIP]') ? 'skip' : 'omission summary';
            $this->bytes += strlen($line) + 1;
        }
    }

    /** @return list<string> */
    public function lines(): array
    {
        return $this->lines;
    }

    /** @return array<string, int> */
    public function omitted(): array
    {
        return $this->omitted;
    }

    /** @return list<string> */
    public function skipped(): array
    {
        return $this->skipped;
    }

    private function fits(int $lineBytes): bool
    {
        return count($this->lines) < $this->maxLines && $this->bytes + $lineBytes <= $this->maxBytes;
    }

    private function reclaimUnverifiedFor(string $category, int $lineBytes): bool
    {
        if (isset(self::UNVERIFIED_CATEGORIES[$category])) {
            return false;
        }
        for ($index = count($this->lines) - 1; $index >= 0 && !$this->fits($lineBytes); --$index) {
            if (isset(self::UNVERIFIED_CATEGORIES[$this->lineCategories[$index]])) {
                $this->dropLine($index);
            }
        }

        return $this->fits($lineBytes);
    }

    private function leastAuthoritativeLineIndex(): int
    {
        for ($index = count($this->lines) - 1; $index >= 0; --$index) {
            if (isset(self::UNVERIFIED_CATEGORIES[$this->lineCategories[$index]])) {
                return $index;
            }
        }

        return count($this->lines) - 1;
    }

    private function dropLine(int $index): void
    {
        if (!isset($this->lines[$index])) {
            return;
        }
        $line = $this->lines[$index];
        $category = $this->lineCategories[$index] ?? 'context line';
        $this->bytes -= strlen($line) + 1;
        $this->omitted[$category] = ($this->omitted[$category] ?? 0) + 1;
        array_splice($this->lines, $index, 1);
        array_splice($this->lineCategories, $index, 1);
    }

    /** @return list<string> */
    private function requiredLines(): array
    {
        $required = array_map(static fn (string $skip): string => '[SKIP] ' . $skip, $this->skipped);
        $summary = [];
        foreach ($this->omitted as $category => $count) {
            if ($category !== 'section' && $category !== 'skip') {
                $summary[] = $count . ' additional ' . $category . ($count === 1 ? '' : 's');
            }
        }
        if ($summary !== []) {
            array_unshift($required, 'Omitted: ' . implode(', ', $summary));
        }

        return $required;
    }
}
