<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use Throwable;
use voku\AgentLoop\Run\CanonicalJson;
use voku\AgentLoop\Run\RunManifest;
use voku\AgentLoop\Run\RunManifestProjector;
use voku\AgentLoop\Run\RunManifestStore;

/**
 * The paved, read-only lifecycle view for humans and coding agents.
 *
 * The status command consumes the same run projection as `workflow manifest`.
 * That keeps board, session, repository, recall, execution, verification,
 * review and learning states in one model instead of politely disagreeing in
 * six independently green status commands.
 */
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
            $manifest = (new RunManifestProjector($this->rootPath))->project($taskId->value);
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
        printf("  %-17s %-22s %s\n", 'Run:', $manifest->runId, $manifest->mode);
        printf("  %-17s %-22s %s\n", 'Overall:', $manifest->state, 'derived from owning artifacts');
        printf("  %-17s %-22s %s\n", 'Manifest:', $storage['state'], $storage['path']);

        echo "\nLifecycle:\n";
        foreach ($this->orderedReferences($manifest) as $name => $reference) {
            $state = is_string($reference['state'] ?? null) ? $reference['state'] : 'unknown';
            printf(
                "  %-17s %-22s %s\n",
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

    /**
     * @return array<string, array<string, mixed>>
     */
    private function orderedReferences(RunManifest $manifest): array
    {
        $ordered = [];
        foreach ([
            'board',
            'session',
            'work_brief',
            'approval',
            'map',
            'search_index',
            'recall',
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
            'work_brief' => 'Work brief',
            'search_index' => 'Search index',
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
            'work_brief' => isset($reference['revision'])
                ? 'revision ' . (string) $reference['revision'] . $this->sourceSuffix($reference)
                : $this->pathOrOwner($reference),
            'approval' => isset($reference['approved_by'])
                ? 'revision ' . (string) ($reference['work_brief_revision'] ?? '?') . ' by ' . (string) $reference['approved_by']
                : $this->pathOrOwner($reference),
            'recall' => $this->value($reference, 'compilation_id', $this->pathOrOwner($reference)),
            'learning' => isset($reference['decided_by'])
                ? 'recorded by ' . (string) $reference['decided_by']
                : $this->pathOrOwner($reference),
            default => $this->pathOrOwner($reference),
        };
    }

    /** @param array<string, mixed> $reference */
    private function boardDetail(array $reference): string
    {
        if (isset($reference['card_id'])) {
            $parts = [(string) $reference['card_id']];
            if (isset($reference['lane'])) {
                $parts[] = (string) $reference['lane'];
            }
            if (isset($reference['status'])) {
                $parts[] = (string) $reference['status'];
            }

            return implode(' / ', $parts);
        }

        return $this->pathOrOwner($reference);
    }

    /** @param array<string, mixed> $reference */
    private function sourceSuffix(array $reference): string
    {
        $path = $reference['source']['path'] ?? null;

        return is_string($path) ? ' (' . $path . ')' : '';
    }

    /** @param array<string, mixed> $reference */
    private function pathOrOwner(array $reference): string
    {
        $sourcePath = $reference['source']['path'] ?? null;
        if (is_string($sourcePath)) {
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
    private function value(array $reference, string $key, string $fallback): string
    {
        return is_string($reference[$key] ?? null) && $reference[$key] !== ''
            ? $reference[$key]
            : $fallback;
    }

    /**
     * @param list<string> $tokens
     * @return 'text'|'json'
     */
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
