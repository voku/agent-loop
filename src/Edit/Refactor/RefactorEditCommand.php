<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit\Refactor;

use HelgeSverre\Toon\Toon;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;
use voku\AgentLoop\Edit\EditMutationLock;
use voku\AgentLoop\Edit\EditRunResult;
use voku\AgentLoop\Edit\WorkingTreeSnapshotter;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Workflow\ExecutionContractStore;
use voku\AgentMap\Index\IndexReader;

/** CLI boundary for consuming one already-produced, versioned agent-map refactor plan. */
final readonly class RefactorEditCommand
{
    /** Wires the governed mutation boundary to project-local evidence services. */
    public function __construct(
        private string $projectRoot,
        private RenamePlanApplier $applier = new RenamePlanApplier(),
        private ClassMovePlanApplier $classMoveApplier = new ClassMovePlanApplier(),
        private MethodRemovalPlanApplier $removalApplier = new MethodRemovalPlanApplier(),
        private PropertyRemovalPlanApplier $propertyRemovalApplier = new PropertyRemovalPlanApplier(),
        private ClassConstantRemovalPlanApplier $classConstantRemovalApplier = new ClassConstantRemovalPlanApplier(),
        private EditMutationLock $mutationLock = new EditMutationLock(),
        private IndexReader $reader = new IndexReader(),
        private WorkingTreeSnapshotter $snapshotter = new WorkingTreeSnapshotter(),
    ) {
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        if ($tokens === [] || in_array($tokens[0], ['help', '--help', '-h'], true)) {
            return $this->help();
        }

        try {
            $request = $this->parse($tokens);
            $this->ensureDirectory($request['output_directory']);
            $before = $this->snapshotter->capture($this->projectRoot);

            /** @var array<string, mixed>|null $plan */
            $plan = null;
            $planSha256 = null;
            $mapDigest = null;
            $mapIndexSha256 = null;

            $operation = function () use ($request, &$plan, &$planSha256, &$mapDigest, &$mapIndexSha256): EditRunResult {
                [$plan, $planSha256] = $this->readPlan($request['plan']);
                $map = $this->reader->read($request['map_index']);
                $mapDigest = $map->mapDigest();
                $rawMapHash = hash_file('sha256', $request['map_index']);
                if (!is_string($rawMapHash)) {
                    throw new RuntimeException('Unable to hash agent-map index: ' . $request['map_index']);
                }
                $mapIndexSha256 = 'sha256:' . $rawMapHash;
                $applier = match ($plan['type'] ?? null) {
                    'class_move_plan' => $this->classMoveApplier,
                    'method_removal_plan' => $this->removalApplier,
                    'property_removal_plan' => $this->propertyRemovalApplier,
                    'class_constant_removal_plan' => $this->classConstantRemovalApplier,
                    default => $this->applier,
                };

                if ($request['dry_run']) {
                    $prepared = $applier->preflight($plan, $map, $request['map_root']);

                    return new EditRunResult(
                        status: 'prepared',
                        exitCode: 0,
                        stdout: sprintf(
                            "%s validated %d edit(s) and %d move(s); no source was changed.\n",
                            $prepared['plan_type'],
                            $prepared['edit_count'],
                            $prepared['move_count'],
                        ),
                    );
                }

                return $applier->apply($plan, $map, $request['map_root']);
            };

            if ($request['dry_run']) {
                $result = $operation();
            } else {
                (new ExecutionContractStore($this->projectRoot))->assertReadyForMutation($request['task_id']);
                $result = $this->mutationLock->synchronized($this->projectRoot, $operation);
            }

            if (!is_array($plan) || !is_string($planSha256) || !is_string($mapDigest) || !is_string($mapIndexSha256)) {
                throw new RuntimeException('Refactor execution completed without complete plan/map evidence.');
            }

            $after = $this->snapshotter->capture($this->projectRoot);
            $executionPath = $request['output_directory'] . '/execution.json';
            $runnerName = match ($plan['type'] ?? null) {
                'class_move_plan' => 'class-move-plan',
                'method_removal_plan' => 'method-removal-plan',
                'property_removal_plan' => 'property-removal-plan',
                'class_constant_removal_plan' => 'class-constant-removal-plan',
                default => 'rename-plan',
            };
            $this->write($executionPath, $this->json([
                'schema_version' => '1.0',
                'status' => $result->status,
                'task_id' => $request['task_id'],
                'plan' => [
                    'path' => $request['plan'],
                    'sha256' => $planSha256,
                    'type' => $plan['type'] ?? null,
                    'contract_version' => $plan['contract_version'] ?? null,
                    'target_id' => $plan['target_id'] ?? null,
                ],
                'map_digest' => $mapDigest,
                'map_index_sha256' => $mapIndexSha256,
                'runner' => [
                    'name' => $runnerName,
                    'exit_code' => $result->exitCode,
                    'dry_run' => $request['dry_run'],
                    'model_input_tokens' => 0,
                    'model_tool_calls' => 0,
                ],
                'changed_files' => $after->changedPathsSince($before),
                'changed_files_source' => $before->available && $after->available ? 'git_status_diff' : 'unavailable',
            ]));

            echo "Refactor execution bundle prepared: {$request['output_directory']}\n";
            echo '- plan: ' . (string) ($plan['type'] ?? 'unknown') . "\n";
            echo '- target: ' . (string) ($plan['target_id'] ?? 'unknown') . "\n";
            echo "- status: {$result->status}\n";
            echo "- execution: {$executionPath}\n";

            return $result->succeeded() ? 0 : 1;
        } catch (Throwable $exception) {
            fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @param list<string> $tokens
     * @return array{
     *     task_id: string,
     *     plan: string,
     *     map_index: string,
     *     map_root: string,
     *     output_directory: string,
     *     dry_run: bool
     * }
     */
    private function parse(array $tokens): array
    {
        $root = realpath($this->projectRoot);
        if (!is_string($root) || !is_dir($root)) {
            throw new InvalidArgumentException('Project root not found: ' . $this->projectRoot);
        }
        $root = str_replace('\\', '/', $root);
        $layout = new ProjectLayout($root);

        /** @var array<string, string> $values */
        $values = [];
        $dryRun = false;
        $plan = null;
        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if ($token === '--dry-run') {
                $dryRun = true;
                continue;
            }
            if (!str_starts_with($token, '--')) {
                if ($plan !== null) {
                    throw new InvalidArgumentException('Unexpected refactor argument: ' . $token);
                }
                $plan = $token;
                continue;
            }

            $raw = substr($token, 2);
            if (str_contains($raw, '=')) {
                [$name, $value] = explode('=', $raw, 2);
            } else {
                $name = $raw;
                $value = $tokens[$index + 1] ?? null;
                if (!is_string($value) || str_starts_with($value, '--')) {
                    throw new InvalidArgumentException('Missing value for refactor option: --' . $name);
                }
                ++$index;
            }
            if (!in_array($name, ['task', 'map-index', 'map-root', 'output-dir'], true)) {
                throw new InvalidArgumentException('Unknown refactor option: --' . $name);
            }
            if ($value === '' || isset($values[$name])) {
                throw new InvalidArgumentException('Invalid or duplicate refactor option: --' . $name);
            }
            $values[$name] = $value;
        }

        if (!is_string($plan) || trim($plan) === '') {
            throw new InvalidArgumentException('edit refactor requires exactly one plan JSON/TOON path.');
        }
        $taskId = trim($values['task'] ?? '');
        if ($taskId === '' || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $taskId) !== 1 || str_contains($taskId, '..')) {
            throw new InvalidArgumentException('edit refactor requires a valid explicit --task ID.');
        }

        $mapRoot = $this->existingDirectory($root, $values['map-root'] ?? $root, 'map root');
        $mapIndex = $this->existingFile($root, $values['map-index'] ?? $layout->mapIndex(), 'map index');
        $planPath = $this->existingFile($root, $plan, 'refactor plan');
        $output = $this->resolvePath($root, $values['output-dir'] ?? $layout->editBundle($taskId));

        return [
            'task_id' => $taskId,
            'plan' => $planPath,
            'map_index' => $mapIndex,
            'map_root' => $mapRoot,
            'output_directory' => $output,
            'dry_run' => $dryRun,
        ];
    }

    /** @return array{0: array<string, mixed>, 1: string} */
    private function readPlan(string $path): array
    {
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            throw new RuntimeException('Unable to read refactor plan: ' . $path);
        }

        if (str_ends_with(strtolower($path), '.toon')) {
            $decoded = Toon::decode($raw);
        } else {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Refactor plan document must decode to an object: ' . $path);
        }

        return [$decoded, 'sha256:' . hash('sha256', $raw)];
    }

    /** Resolves an existing in-scope file path for a required refactor input. */
    private function existingFile(string $root, string $path, string $label): string
    {
        $resolved = $this->resolvePath($root, $path);
        $real = realpath($resolved);
        if (!is_string($real) || !is_file($real)) {
            throw new InvalidArgumentException('Refactor ' . $label . ' not found: ' . $path);
        }

        return str_replace('\\', '/', $real);
    }

    /** Resolves an existing in-scope directory for a required refactor input. */
    private function existingDirectory(string $root, string $path, string $label): string
    {
        $resolved = $this->resolvePath($root, $path);
        $real = realpath($resolved);
        if (!is_string($real) || !is_dir($real)) {
            throw new InvalidArgumentException('Refactor ' . $label . ' not found: ' . $path);
        }

        return str_replace('\\', '/', $real);
    }

    /** Resolves a project-relative or absolute refactor path without requiring existence. */
    private function resolvePath(string $root, string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new InvalidArgumentException('Refactor path must not be empty.');
        }
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, '/') || preg_match('~^[A-Za-z]:/~', $path) === 1) {
            return rtrim($path, '/');
        }

        return rtrim($root, '/') . '/' . ltrim($path, '/');
    }

    /** Creates the evidence directory when it does not already exist. */
    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create refactor evidence directory: ' . $directory);
        }
    }

    /** Atomically writes one evidence file by renaming a same-directory staging file. */
    private function write(string $path, string $content): void
    {
        $this->ensureDirectory(dirname($path));
        $temporary = $path . '.tmp-' . getmypid();
        if (file_put_contents($temporary, $content) === false) {
            throw new RuntimeException('Unable to write refactor evidence: ' . $temporary);
        }
        if (!rename($temporary, $path)) {
            if (is_file($temporary) && !unlink($temporary)) {
                throw new RuntimeException('Unable to publish refactor evidence and cleanup temporary file: ' . $path);
            }
            throw new RuntimeException('Unable to publish refactor evidence: ' . $path);
        }
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): string
    {
        try {
            return json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode refactor evidence JSON: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /** Prints the supported governed refactor CLI contract. */
    private function help(): int
    {
        echo <<<'TXT'
Usage:
  agent-loop edit refactor PLAN [options]

Consumes one safe versioned agent-map refactor plan through agent-loop's mutation boundary.
The fixed allowlist covers the six rename-plan contracts, class_move_plan@1.0, plus
method_removal_plan@1.0, property_removal_plan@1.0, and class_constant_removal_plan@1.0.
Each owner family keeps its own wire decoder and semantic invariants; arbitrary edit plans and Rector
execution remain rejected.

Options:
  --task ID            Required governed task ID.
  --map-index PATH     Current agent-map JSON/TOON. Default: .agent-loop/map/php-symbols.json
  --map-root PATH      Runtime source root for hash/currentness checks. Default: project root.
  --output-dir PATH    Evidence bundle. Default: .agent-loop/edit/<task-id>
  --dry-run            Validate the complete plan and current source without mutation.

Mutation requires the task's current execution contract to be ready. Every source hash, inclusive
byte range, expected token and plan provenance is revalidated under the shared project mutation lock.
All rewritten PHP is staged and syntax-checked before publication; every source is restored on any
publication failure. Preconditioned file moves are published in the same transaction as their edits.

TXT;

        return 0;
    }
}
