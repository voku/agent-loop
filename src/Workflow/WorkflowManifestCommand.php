<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use Throwable;
use voku\AgentLoop\Run\CanonicalJson;
use voku\AgentLoop\Run\RunManifestProjector;
use voku\AgentLoop\Run\RunManifestStore;

/**
 * Renders the current task-scoped run projection. Reading is the default;
 * persistence is explicit because a status command that quietly mutates the
 * state it observes would be a rather inventive definition of observability.
 */
final readonly class WorkflowManifestCommand
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        try {
            $taskId = new WorkflowTaskId($args[0] ?? '');
            $options = $this->parse(array_slice($args, 1));
            $manifest = (new RunManifestProjector($this->rootPath))->project($taskId->value);
            $store = new RunManifestStore($this->rootPath);

            if ($options['write']) {
                $store->write($manifest);
            }
            $storage = $store->status($manifest);

            if ($options['format'] === 'json') {
                echo CanonicalJson::pretty([
                    'manifest' => $manifest->toArray(),
                    'storage' => $storage,
                ]);

                return $manifest->disagreements === [] ? 0 : 2;
            }

            echo 'Run ' . $manifest->runId . "\n";
            echo '  Task:      ' . $manifest->taskId . "\n";
            echo '  Mode:      ' . $manifest->mode . "\n";
            echo '  State:     ' . $manifest->state . "\n";
            echo '  Manifest:  ' . $storage['state'] . ' (' . $storage['path'] . ")\n";
            echo "\nReferences:\n";
            foreach ($manifest->references as $name => $reference) {
                $owner = is_string($reference['owner'] ?? null) ? $reference['owner'] : 'unknown';
                $state = is_string($reference['state'] ?? null) ? $reference['state'] : 'unknown';
                printf("  %-17s %-22s %s\n", $name . ':', $state, $owner);
            }

            if ($manifest->disagreements !== []) {
                echo "\nDisagreements:\n";
                foreach ($manifest->disagreements as $disagreement) {
                    echo '  - ' . $disagreement['code'] . ': ' . $disagreement['message'] . "\n";
                }
            }

            echo "\nNext:\n  " . $manifest->nextAction . "\n";

            return $manifest->disagreements === [] ? 0 : 2;
        } catch (Throwable $exception) {
            fwrite(\STDERR, '[FAIL] workflow manifest: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @param list<string> $tokens
     * @return array{format: 'text'|'json', write: bool}
     */
    private function parse(array $tokens): array
    {
        $format = 'text';
        $write = false;

        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if ($token === '--write') {
                $write = true;

                continue;
            }
            if ($token !== '--format') {
                throw new InvalidArgumentException('Unknown option: ' . $token);
            }
            if (!isset($tokens[$index + 1])) {
                throw new InvalidArgumentException('--format requires text or json.');
            }
            $candidate = strtolower(trim($tokens[++$index]));
            if (!in_array($candidate, ['text', 'json'], true)) {
                throw new InvalidArgumentException('--format must be text or json.');
            }
            $format = $candidate;
        }

        return ['format' => $format, 'write' => $write];
    }
}
