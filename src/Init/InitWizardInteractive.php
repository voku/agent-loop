<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use Closure;
use InvalidArgumentException;

/**
 * One question at a time in a terminal, driven by the wizard's own step protocol.
 *
 * Loop picks every next question (`InitWizardCommand::next`); this loop only renders it,
 * reads one line and turns it into an answer action. Prompts go to the error stream so the
 * final JSON on standard output stays machine-readable. When input ends before the last
 * question the run fails instead of waiting, so a pipe or CI job never hangs.
 */
final readonly class InitWizardInteractive
{
    /**
     * @param Closure(): ?string $readLine the next typed line, or null when input has ended;
     *                                     called only when a question is waiting for it
     * @param Closure(string): void $output where prompts go
     */
    public function __construct(private InitWizardCommand $wizard, private Closure $readLine, private Closure $output)
    {
    }

    /**
     * Reads one line of a stream per call. Nothing is read ahead, so the next prompt is
     * always shown before the wizard waits for its answer.
     *
     * @param resource $stream
     * @return Closure(): ?string
     */
    public static function lines($stream): Closure
    {
        return static function () use ($stream): ?string {
            $line = fgets($stream);

            return $line === false ? null : $line;
        };
    }

    public function run(): int
    {
        $answers = [];
        while (true) {
            $step = $this->wizard->next($answers);
            if ($step['step'] === null) {
                echo InitWizardCommand::encode([...$step, 'answers_document' => ['version' => InitWizardCommand::VERSION, 'answers' => $step['answers']]]) . "\n";

                return 0;
            }
            $question = $step['step'];
            ($this->output)($this->render($question, $step['progress']));
            $answer = $this->ask($question, $answers);
            if ($answer === null) {
                ($this->output)("[FAIL] setup wizard: input ended before the wizard finished; nothing was changed.\n");

                return 1;
            }
            $answers[$question['id']] = $answer;
        }
    }

    /**
     * Reads lines until one is a valid answer; null when input ends first.
     *
     * @param array<string, mixed> $question
     * @param array<string, mixed> $answers
     * @return array<mixed>|string|null
     */
    private function ask(array $question, array $answers): array|string|null
    {
        while (($line = ($this->readLine)()) !== null) {
            $answer = $this->parse(trim($line), $question);
            try {
                // The wizard is the only judge of an answer; this loop just asks again.
                $this->wizard->next([...$answers, $question['id'] => $answer]);

                return $answer;
            } catch (InvalidArgumentException $exception) {
                ($this->output)('  ' . $exception->getMessage() . "\n> ");
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $question
     * @return array<mixed>|string
     */
    private function parse(string $line, array $question): array|string
    {
        $actions = array_column($question['actions'], 'action');
        if ($line === '') {
            $line = in_array('keep', $actions, true) ? 'k' : 'r';
        }

        return match ($line) {
            'k', 'keep' => ['action' => 'keep'],
            'r', 'recommended' => ['action' => 'recommended'],
            's', 'skip' => ['action' => 'skip'],
            default => in_array($question['kind'], ['multi_choice', 'path_list'], true)
                ? array_values(array_filter(array_map('trim', explode(',', $line)), static fn (string $part): bool => $part !== ''))
                : $line,
        };
    }

    /**
     * @param array<string, mixed> $question
     * @param array{position: int, total: int} $progress
     */
    private function render(array $question, array $progress): string
    {
        $text = sprintf("\n[%d/%d] %s\n  %s\n", $progress['position'], $progress['total'], $question['id'], $question['prompt']);
        $text .= sprintf("  current:     %s (%s)\n", self::value($question['current']), $question['current_source']);
        $text .= sprintf("  recommended: %s (%s)\n", self::value($question['default']), $question['default_source']);
        if (isset($question['options'])) {
            $text .= '  options:     ' . implode(', ', $question['options']) . "\n";
        }
        if (($question['session_override'] ?? null) !== null) {
            $text .= '  this session currently overrides it with: ' . $question['session_override'] . "\n";
        }
        $text .= '  effect:      ' . $question['consequence'] . "\n";

        return $text . '  ' . self::hint($question) . "\n> ";
    }

    /**
     * Only the actions this question allows, so a required question never offers skip.
     *
     * @param array<string, mixed> $question
     */
    private static function hint(array $question): string
    {
        $actions = array_column($question['actions'], 'action');
        $parts = [];
        if (in_array('keep', $actions, true)) {
            $parts[] = 'Enter or k = keep';
        } elseif (in_array('recommended', $actions, true)) {
            $parts[] = 'Enter = recommended';
        }
        if (in_array('recommended', $actions, true)) {
            $parts[] = 'r = recommended';
        }
        if (in_array('skip', $actions, true)) {
            $parts[] = 's = skip';
        }
        $list = in_array($question['kind'], ['multi_choice', 'path_list'], true) ? ' (comma-separated)' : '';

        return ($parts === [] ? 'Type a value' : implode(', ', $parts) . ', or type a value') . $list . '.' . ($question['required'] ? ' Required.' : '');
    }

    /** @param list<string>|string|null $value */
    private static function value(array|string|null $value): string
    {
        return match (true) {
            $value === null, $value === [] => '(none)',
            is_array($value) => implode(', ', $value),
            default => $value,
        };
    }
}
