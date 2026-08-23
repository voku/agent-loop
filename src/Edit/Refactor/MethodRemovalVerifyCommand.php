<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit\Refactor;

use HelgeSverre\Toon\Toon;
use JsonException;
use RuntimeException;
use Throwable;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexReader;

/** Verifies one applied method-removal bundle against current source and Map evidence. */
final readonly class MethodRemovalVerifyCommand
{
    public const FILE_NAME = 'verification-result.json';

    public function __construct(
        private string $projectRoot,
        private IndexReader $reader = new IndexReader(),
    ) {
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        if (in_array($tokens[0] ?? '', ['help', '--help', '-h'], true)) {
            echo $this->help();

            return 0;
        }

        try {
            $options = $this->options($tokens);
            $result = $this->verify($options['bundle'], $options['map_index'], $options['map_root']);
            $this->write($options['bundle'] . '/' . self::FILE_NAME, $this->json($result));
        } catch (Throwable $exception) {
            fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . "\n");

            return 2;
        }

        echo "Method removal verification: passed\n";
        echo '- bundle: ' . $options['bundle'] . "\n";
        echo '- target: ' . $result['plan']['target_id'] . "\n";
        echo '- result: ' . $options['bundle'] . '/' . self::FILE_NAME . "\n";

        return 0;
    }

    /**
     * @return array{
     *   schema_version: string,
     *   kind: string,
     *   status: string,
     *   task_id: string,
     *   plan: array{type: string, contract_version: string, target_id: string, sha256: string},
     *   map: array{backend: string, digest: string},
     *   changed_files: list<string>,
     *   checks: array{execution_binding: string, current_map: string, changed_files: string, target_absent: string, source_hashes: string}
     * }
     */
    private function verify(string $bundle, string $mapIndex, string $mapRoot): array
    {
        $execution = $this->readJson($bundle . '/execution.json');
        if (($execution['status'] ?? null) !== 'runner_succeeded'
            || ($execution['runner']['name'] ?? null) !== 'method-removal-plan'
            || ($execution['runner']['dry_run'] ?? null) !== false
        ) {
            throw new RuntimeException('Method removal verification requires one successfully applied non-dry removal execution.');
        }

        $taskId = $this->requiredString($execution, 'task_id', 'execution.json');
        $planEvidence = $execution['plan'] ?? null;
        if (!is_array($planEvidence)) {
            throw new RuntimeException('Method removal execution is missing bound plan evidence.');
        }
        $planPath = $this->requiredString($planEvidence, 'path', 'execution plan evidence');
        $planSha256 = $this->requiredString($planEvidence, 'sha256', 'execution plan evidence');
        $planRaw = file_get_contents($planPath);
        if (!is_string($planRaw) || !hash_equals($planSha256, 'sha256:' . hash('sha256', $planRaw))) {
            throw new RuntimeException('Bound method removal plan changed after execution.');
        }
        $plan = $this->decodePlan($planPath, $planRaw);
        $document = MethodRemovalPlanDocument::fromArray($plan);
        if (($planEvidence['type'] ?? null) !== 'method_removal_plan'
            || ($planEvidence['contract_version'] ?? null) !== '1.0'
            || ($planEvidence['target_id'] ?? null) !== $document->targetId
        ) {
            throw new RuntimeException('Execution evidence is not bound to the loaded method removal plan identity.');
        }

        $map = $this->withRuntimeRoot($this->reader->read($mapIndex), $mapRoot);
        if ($map->staleEntries() !== []) {
            throw new RuntimeException('Current agent-map evidence is stale after method removal execution.');
        }
        if (!str_ends_with($map->backend, '+phpstan')) {
            throw new RuntimeException('Method removal verification requires a PHPStan-backed current map.');
        }

        $changedFiles = $execution['changed_files'] ?? null;
        if (!is_array($changedFiles) || ($execution['changed_files_source'] ?? null) !== 'git_status_diff') {
            throw new RuntimeException('Method removal verification requires independently observed changed_files evidence.');
        }
        $actualChanged = [];
        foreach ($changedFiles as $path) {
            if (!is_string($path) || $path === '') {
                throw new RuntimeException('Method removal execution contains invalid changed_files evidence.');
            }
            $actualChanged[] = $this->relativePath($path);
        }
        $actualChanged = array_values(array_unique($actualChanged));
        sort($actualChanged, SORT_STRING);

        $expectedChanged = [];
        foreach ($document->edits as $edit) {
            $expectedChanged[$this->relativePath($edit->path)] = true;
        }
        $expected = array_keys($expectedChanged);
        sort($expected, SORT_STRING);
        if ($actualChanged !== $expected) {
            throw new RuntimeException(sprintf(
                'Observed method-removal changed_files do not exactly match plan scope (expected %s; got %s).',
                implode(', ', $expected),
                implode(', ', $actualChanged),
            ));
        }

        if ($map->resolvedMethodById($document->targetId) !== null) {
            throw new RuntimeException('Removed method is still present in the current Map: ' . $document->targetId);
        }

        foreach ($expected as $path) {
            $file = $map->file($path);
            if ($file === null) {
                throw new RuntimeException('Current Map does not contain rewritten method-removal source: ' . $path);
            }
            $hash = hash_file('sha256', $mapRoot . '/' . $path);
            if (!is_string($hash) || !hash_equals($file->sha256, 'sha256:' . $hash)) {
                throw new RuntimeException('Current Map hash does not match rewritten method-removal source: ' . $path);
            }
        }

        return [
            'schema_version' => '1.0',
            'kind' => 'method_removal_plan_verification',
            'status' => 'passed',
            'task_id' => $taskId,
            'plan' => [
                'type' => 'method_removal_plan',
                'contract_version' => '1.0',
                'target_id' => $document->targetId,
                'sha256' => $planSha256,
            ],
            'map' => [
                'backend' => $map->backend,
                'digest' => $map->mapDigest(),
            ],
            'changed_files' => $actualChanged,
            'checks' => [
                'execution_binding' => 'passed',
                'current_map' => 'passed',
                'changed_files' => 'passed',
                'target_absent' => 'passed',
                'source_hashes' => 'passed',
            ],
        ];
    }

    /** @param list<string> $tokens @return array{bundle: string, map_index: string, map_root: string} */
    private function options(array $tokens): array
    {
        $values = [];
        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if (!str_starts_with($token, '--')) {
                throw new RuntimeException('Unexpected method removal verify argument: ' . $token);
            }
            $raw = substr($token, 2);
            if (str_contains($raw, '=')) {
                [$name, $value] = explode('=', $raw, 2);
            } else {
                $name = $raw;
                $value = $tokens[$index + 1] ?? null;
                if (!is_string($value) || str_starts_with($value, '--')) {
                    throw new RuntimeException('Missing value for method removal verify option: --' . $name);
                }
                ++$index;
            }
            if (!in_array($name, ['bundle', 'map-index', 'map-root'], true) || $value === '' || isset($values[$name])) {
                throw new RuntimeException('Invalid or duplicate method removal verify option: --' . $name);
            }
            $values[$name] = $value;
        }

        $root = realpath($this->projectRoot);
        if (!is_string($root)) {
            throw new RuntimeException('Project root not found: ' . $this->projectRoot);
        }
        $bundle = $this->insideExistingDirectory($root, $values['bundle'] ?? '', 'bundle');
        $mapIndex = $this->insideExistingFile($root, $values['map-index'] ?? '.agent-loop/map/php-symbols.json', 'map index');
        $mapRoot = $this->insideExistingDirectory($root, $values['map-root'] ?? '.', 'map root');

        return ['bundle' => $bundle, 'map_index' => $mapIndex, 'map_root' => $mapRoot];
    }

    /** @return array<string, mixed> */
    private function decodePlan(string $path, string $raw): array
    {
        $decoded = str_ends_with(strtolower($path), '.toon') ? Toon::decode($raw) : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Method removal plan document must decode to an object: ' . $path);
        }

        return $decoded;
    }

    private function withRuntimeRoot(AgentMapIndex $map, string $root): AgentMapIndex
    {
        $runtimeRoot = rtrim(str_replace('\\', '/', $root), '/');
        if (rtrim(str_replace('\\', '/', $map->root), '/') === $runtimeRoot) {
            return $map;
        }

        return new AgentMapIndex(
            $map->schemaVersion,
            $runtimeRoot,
            $map->backend,
            $map->files,
            $map->relations,
            $map->diagnostics,
            $map->fingerprint,
        );
    }

    /** @param array<string, mixed> $data */
    private function requiredString(array $data, string $key, string $source): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException($source . ' is missing non-empty ' . $key . '.');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            throw new RuntimeException('Unable to read method removal verification input: ' . $path);
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Malformed method removal verification input ' . $path . ': ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Method removal verification input must decode to an object: ' . $path);
        }

        return $decoded;
    }

    private function insideExistingFile(string $root, string $path, string $label): string
    {
        $resolved = $this->insidePath($root, $path, $label);
        if (!is_file($resolved)) {
            throw new RuntimeException('Method removal verify ' . $label . ' not found: ' . $path);
        }

        return $resolved;
    }

    private function insideExistingDirectory(string $root, string $path, string $label): string
    {
        if ($path === '') {
            throw new RuntimeException('Method removal verify requires --' . str_replace(' ', '-', $label) . '.');
        }
        $resolved = $this->insidePath($root, $path, $label);
        if (!is_dir($resolved)) {
            throw new RuntimeException('Method removal verify ' . $label . ' not found: ' . $path);
        }

        return $resolved;
    }

    private function insidePath(string $root, string $path, string $label): string
    {
        $candidate = str_starts_with($path, '/') ? $path : $root . '/' . $path;
        $real = realpath($candidate);
        if (!is_string($real)) {
            throw new RuntimeException('Method removal verify ' . $label . ' not found: ' . $path);
        }
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $real = str_replace('\\', '/', $real);
        if ($real !== $root && !str_starts_with($real, $root . '/')) {
            throw new RuntimeException('Method removal verify ' . $label . ' escapes the project root.');
        }

        return $real;
    }

    private function relativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || str_starts_with($path, '/') || preg_match('~^[A-Za-z]:/~', $path) === 1) {
            throw new RuntimeException('Method removal verification requires project-relative source paths.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('Method removal verification source path is not normalized: ' . $path);
            }
        }

        return $path;
    }

    private function write(string $path, string $content): void
    {
        $temporary = $path . '.tmp-' . getmypid();
        if (file_put_contents($temporary, $content) === false) {
            throw new RuntimeException('Unable to write method removal verification result: ' . $temporary);
        }
        if (!rename($temporary, $path)) {
            if (is_file($temporary) && !unlink($temporary)) {
                throw new RuntimeException('Unable to publish method removal verification result and cleanup temporary file: ' . $path);
            }
            throw new RuntimeException('Unable to publish method removal verification result: ' . $path);
        }
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    private function help(): string
    {
        return <<<'TXT'
Usage:
  agent-loop edit refactor verify --bundle=.agent-loop/edit/TASK [--map-index PATH] [--map-root PATH]

For a method-removal execution, verifies immutable plan binding, exact observed changed-file scope,
a current PHPStan-backed Map whose hashes match source, and that the removed method ID is absent.

TXT;
    }
}
