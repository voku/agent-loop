<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use Throwable;
use voku\AgentLoop\Workflow\Transparency\TaskTransparencyProjection;
use voku\AgentLoop\Workflow\Transparency\WorkflowTransparencyService;

/**
 * Renders the read-only task-transparency projection.
 *
 * The typed service is the API hosts consume. This command exists so the same
 * projection is inspectable from a terminal without a host, which is also what
 * makes it dogfoodable against a real governed task.
 */
final readonly class WorkflowTransparencyCommand
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        try {
            $taskId = new WorkflowTaskId($args[0] ?? '');
            $json = $this->wantsJson(array_slice($args, 1));
            $projection = (new WorkflowTransparencyService($this->rootPath))->task($taskId->value);
            if ($json) {
                echo json_encode($projection->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

                return 0;
            }

            $this->printText($projection);

            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, '[FAIL] workflow transparency: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    /** @param list<string> $tokens */
    private function wantsJson(array $tokens): bool
    {
        $format = 'text';
        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if ($token !== '--format') {
                throw new InvalidArgumentException('Unknown option: ' . $token);
            }
            if (!isset($tokens[$index + 1]) || str_starts_with($tokens[$index + 1], '--')) {
                throw new InvalidArgumentException('--format requires a value.');
            }
            $format = trim($tokens[++$index]);
        }
        if (!in_array($format, ['text', 'json'], true)) {
            throw new InvalidArgumentException('--format must be text or json.');
        }

        return $format === 'json';
    }

    private function printText(TaskTransparencyProjection $projection): void
    {
        echo 'Task transparency: ' . $projection->taskId . "\n\n";

        $contract = $projection->contract;
        if (!$contract->exists) {
            echo "Contract: missing\n";
        } else {
            echo sprintf(
                "Contract: %s revision %s (%s)\n",
                (string) $contract->status,
                $contract->revision === null ? 'unknown' : (string) $contract->revision,
                $contract->approvedBy === null ? 'not approved' : 'approved by ' . $contract->approvedBy,
            );
            echo 'Approved scope: ' . ($contract->scope === [] ? 'none' : implode(', ', $contract->scope)) . "\n";
            echo 'Acceptance criteria (required, not proof): '
                . ($contract->acceptanceCriteria === [] ? 'none recorded' : implode('; ', $contract->acceptanceCriteria)) . "\n";
        }

        $observation = $projection->observation;
        if (!$observation->isObserved()) {
            echo 'Repository observation: unavailable (' . $observation->status->value . ') — '
                . (string) $observation->unavailableReason . "\n";
        } else {
            echo sprintf(
                "Repository observation from %s: %d changed path(s); %d in scope, %d outside scope\n",
                (string) $observation->baseCommit,
                count($observation->changedFiles),
                count($projection->scopeCoverage->changedInScope),
                count($projection->scopeCoverage->changedOutsideScope),
            );
            echo "Observation is not completion evidence: acceptance is proven by Contract validation, verification and review.\n";
        }

        $implementation = $projection->implementation;
        echo 'Implementation snapshot: ' . $implementation->status->value
            . ($implementation->digest === null ? '' : ' ' . $implementation->digest)
            . ($implementation->reason === null ? '' : ' — ' . $implementation->reason) . "\n";

        $review = $projection->review;
        echo 'Review: ' . ($review->exists ? $review->currency->value : 'missing')
            . ($review->reportStatus === null ? '' : ', report ' . $review->reportStatus)
            . ($review->lifecycleStatus === null ? '' : ', lifecycle ' . $review->lifecycleStatus)
            . ($review->acknowledgedBy === null ? '' : ', acknowledged by ' . $review->acknowledgedBy)
            . "\n";

        echo "\nCategorised facts:\n";
        $items = $projection->items();
        if ($items === []) {
            echo "  - none\n";

            return;
        }
        foreach ($items as $item) {
            echo '  - [' . $item->category->value . '/' . $item->provenance()->value . '] '
                . $this->escapeTerminalText($item->value)
                . ($item->detail === null ? '' : ' — ' . $this->escapeTerminalText($item->detail)) . "\n";
        }
    }

    /**
     * Render arbitrary owner/repository bytes without allowing them to create
     * terminal control sequences or additional output lines.
     *
     * Printable ASCII remains readable. Backslashes are doubled so escaped
     * controls stay unambiguous; every other byte is represented exactly.
     */
    private function escapeTerminalText(string $value): string
    {
        $escaped = '';
        for ($index = 0, $length = strlen($value); $index < $length; ++$index) {
            $byte = $value[$index];
            $ordinal = ord($byte);
            if ($ordinal >= 0x20 && $ordinal <= 0x7e && $byte !== '\\') {
                $escaped .= $byte;
                continue;
            }

            $escaped .= match ($byte) {
                "\n" => '\\n',
                "\r" => '\\r',
                "\t" => '\\t',
                '\\' => '\\\\',
                default => sprintf('\\x%02X', $ordinal),
            };
        }

        return $escaped;
    }
}
