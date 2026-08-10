<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use Throwable;
use voku\AgentLoop\Run\CanonicalJson;
use voku\AgentLoop\Run\GovernedRunManifestProjector;
use voku\AgentLoop\Run\RunManifest;
use voku\AgentLoop\Run\RunManifestStore;

/** Read-only lifecycle view built from package-owned artifacts. */
final readonly class WorkflowStatusCommand
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        try {
            $taskId = new WorkflowTaskId($args[0] ?? '');
            $format = $this->parseFormat(array_slice($args, 1));
            $manifest = (new GovernedRunManifestProjector($this->rootPath))->project($taskId->value);
            $storage = (new RunManifestStore($this->rootPath))->status($manifest);

            if ($format === 'json') {
                echo CanonicalJson::pretty([
                    'manifest' => $manifest->toArray(),
                    'storage' => $storage,
                ]);

                return $manifest->state === 'blocked' ? 2 : 0;
            }

            $this->renderText($manifest, $storage);

            return $manifest->state === 'blocked' ? 2 : 0;
        } catch (Throwable $exception) {
            fwrite(\STDERR, '[FAIL] workflow status: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @param array{state: 'missing'|'current'|'stale', path: string, current_sha256: string, stored_sha256: string|null} $storage
     */
    private function renderText(RunManifest $manifest, array $storage): void
    {
        echo 'Task ' . $manifest->taskId . "\n";
        printf("  %-19s %-22s %s\n", 'Run:', $manifest->runId, $manifest->mode);
        printf("  %-19s %-22s %s\n", 'Overall:', $manifest->state, 'derived from owning artifacts');
        printf("  %-19s %-22s %s\n", 'Manifest:', $storage['state'], $storage['path']);

        echo "\nLifecycle:\n";
        foreach ($this->orderedReferences($manifest) as $name => $reference) {
            $state = is_string($reference['state'] ?? null) ? $reference['state'] : 'unknown';
            printf(
                "  %-19s %-22s %s\n",
                $this->label($name) . ':',
                $state,
                $this->detail($name, $reference),
            );
        }

        if ($manifest->disagreements !== []) {
            echo "\nDisagreements:\n";
            foreach ($manifest->disagreements as $disagreement) {
                echo sprintf(
                    "  - %s [%s]: %s\n",
                    $disagreement['code'],
                    $disagreement['owner'],
                    $disagreement['message'],
                );
            }
        }

        echo "\nNext:\n  " . $manifest->nextAction . "\n";
    }

    /** @return array<string, array<string, mixed>> */
    private function orderedReferences(RunManifest $manifest): array
    {
        $ordered = [];
        foreach ([
            'board',
            'session',
            'contract',
            'approval',
            'map',
            'search_index',
            'recall',
            'execution_contract',
            'edit',
            'verification',
            'review',
            'learning',
            'outcome_lineage',
        ] as $name) {
            if (isset($manifest->references[$name])) {
                $ordered[$name] = $manifest->references[$name];
            }
        }

        return $ordered;
    }

    private function label(string $name): string
    {
        return match ($name) {
            'search_index' => 'Search index',
            'execution_contract' => 'Execution contract',
            'outcome_lineage' => 'Outcome lineage',
            default => ucfirst($name),
        };
    }

    /** @param array<string, mixed> $reference */
    private function detail(string $name, array $reference): string
    {
        return match ($name) {
            'board' => $this->boardDetail($reference),
            'session' => $this->value($reference, 'session_id', 'agent-session'),
            'contract' => $this->revisionDetail($reference),
            'approval' => $this->approvalDetail($reference),
            'recall' => $this->value($reference, 'compilation_id', $this->pathOrOwner($reference)),
            'execution_contract' => $this->executionContractDetail($reference),
            'learning' => $this->learningDetail($reference),
            default => $this->pathOrOwner($reference),
        };
    }

    /** @param array<string, mixed> $reference */
    private function executionContractDetail(array $reference): string
    {
        $promptIds = $reference['prompt_ids'] ?? null;
        if (is_array($promptIds)) {
            $ids = array_values(array_filter($promptIds, 'is_string'));
            if ($ids !== []) {
                return 'L2: ' . implode(', ', $ids) . $this->sourceSuffix($reference);
            }
        }

        return $this->pathOrOwner($reference);
    }

    /** @param array<string, mixed> $reference */
    private function revisionDetail(array $reference): string
    {
        $revision = $reference['revision'] ?? $reference['contract_revision'] ?? null;
        if (!is_int($revision) && !is_string($revision)) {
            return $this->pathOrOwner($reference);
        }

        return 'revision ' . $revision . $this->sourceSuffix($reference);
    }

    /** @param array<string, mixed> $reference */
    private function approvalDetail(array $reference): string
    {
        $actor = $reference['approved_by'] ?? null;
        $revision = $reference['contract_revision'] ?? null;
        if (!is_string($actor) || (!is_int($revision) && !is_string($revision))) {
            return $this->pathOrOwner($reference);
        }

        return 'revision ' . $revision . ' by ' . $actor;
    }

    /** @param array<string, mixed> $reference */
    private function learningDetail(array $reference): string
    {
        $decision = $reference['decision'] ?? null;
        $actor = $reference['decided_by'] ?? null;
        if (is_string($decision) && is_string($actor)) {
            return $decision . ' by ' . $actor;
        }

        return $this->pathOrOwner($reference);
    }

    /** @param array<string, mixed> $reference */
    private function boardDetail(array $reference): string
    {
        $cardId = $reference['card_id'] ?? null;
        if (!is_string($cardId)) {
            return $this->pathOrOwner($reference);
        }

        $parts = [$cardId];
        if (is_string($reference['lane'] ?? null)) {
            $parts[] = $reference['lane'];
        }
        if (is_string($reference['status'] ?? null)) {
            $parts[] = $reference['status'];
        }

        return implode(' / ', $parts);
    }

    /** @param array<string, mixed> $reference */
    private function sourceSuffix(array $reference): string
    {
        $path = $this->sourcePath($reference);

        return $path === null ? '' : ' (' . $path . ')';
    }

    /** @param array<string, mixed> $reference */
    private function pathOrOwner(array $reference): string
    {
        $sourcePath = $this->sourcePath($reference);
        if ($sourcePath !== null) {
            return $sourcePath;
        }
        if (is_string($reference['path'] ?? null)) {
            return $reference['path'];
        }
        if (is_string($reference['reason'] ?? null)) {
            return $reference['reason'];
        }

        return is_string($reference['owner'] ?? null) ? $reference['owner'] : 'no detail';
    }

    /** @param array<string, mixed> $reference */
    private function sourcePath(array $reference): ?string
    {
        $source = $reference['source'] ?? null;
        if (!is_array($source)) {
            return null;
        }
        $path = $source['path'] ?? null;

        return is_string($path) ? $path : null;
    }

    /** @param array<string, mixed> $reference */
    private function value(array $reference, string $key, string $fallback, string $prefix = ''): string
    {
        $value = $reference[$key] ?? null;

        return is_string($value) && $value !== '' ? $prefix . $value : $fallback;
    }

    /** @param list<string> $tokens @return 'text'|'json' */
    private function parseFormat(array $tokens): string
    {
        $format = 'text';
        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if (str_starts_with($token, '--format=')) {
                $format = $this->format(substr($token, strlen('--format=')));
                continue;
            }
            if ($token !== '--format') {
                throw new InvalidArgumentException('Unknown option: ' . $token);
            }
            if (!isset($tokens[$index + 1])) {
                throw new InvalidArgumentException('--format requires text or json.');
            }
            $format = $this->format($tokens[++$index]);
        }

        return $format;
    }

    /** @return 'text'|'json' */
    private function format(string $value): string
    {
        $format = strtolower(trim($value));
        if (!in_array($format, ['text', 'json'], true)) {
            throw new InvalidArgumentException('--format must be text or json.');
        }

        return $format;
    }
}
