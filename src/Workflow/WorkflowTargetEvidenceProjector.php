<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use Closure;
use RuntimeException;
use Throwable;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Workflow\Transparency\ApprovedScope;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\SymbolEntry;
use voku\AgentMap\Inspect\MapReadinessInspector;
use voku\AgentMap\MapArtifactPaths;

/**
 * Projects which files structurally reference an explicitly named symbol.
 *
 * The host names the behavior it is about to change (a method, a persisted
 * property, a class or a function) and gets back the declaring file and the
 * files whose Map relations point at it, tests listed separately. This is
 * structural evidence from an already-current Map, not a ranking and not a
 * scope decision: a referencing test is a file to read, and whether it asserts
 * the changed behavior stays the host's judgment. Nothing is persisted, no
 * index is built, and an absent or stale Map abstains instead of answering.
 */
final readonly class WorkflowTargetEvidenceProjector
{
    public const int MAX_TARGETS = 5;
    private const int MAX_TARGET_BYTES = 512;
    private const int MAX_FILES_PER_ROLE = 8;

    /** Relation kinds that mean "this file depends on the target's behavior or shape". */
    private const array REFERENCE_KINDS = [
        'calls',
        'property_access',
        'instantiates',
        'references_type',
        'extends',
        'implements',
        'overrides',
        'uses_trait',
    ];

    /** Every "is ambiguous" failure, from agent-map's method lookup and from this projector, starts like this. */
    private const string AMBIGUOUS_PREFIX = '/^[A-Za-z]+ target is ambiguous: /';

    public function __construct(private string $rootPath)
    {
    }

    /**
     * @param list<string> $targets
     * @return array<string, mixed>
     */
    public function project(array $targets, ?TaskContract $contract): array
    {
        // `\App\Foo` and `App\Foo` name the same symbol; one row each.
        $targets = array_values(array_unique(array_map(static fn (string $target): string => ltrim(trim($target), '\\'), $targets)));
        if (count($targets) > self::MAX_TARGETS) {
            return $this->abstained('too_many_targets', ['max_targets' => self::MAX_TARGETS]);
        }

        $artifacts = $this->mapArtifacts();
        $readiness = (new MapReadinessInspector())->inspect($artifacts);
        $map = $readiness->currentMap();
        if ($map === null || $readiness->mapSnapshot === null) {
            return $this->abstained('map_not_current', ['map_state' => $readiness->mapState]);
        }

        // Only an approved Contract has a scope worth marking; a draft's paths are still a proposal.
        $scope = $contract?->status === TaskContract::APPROVED ? ApprovedScope::fromContract($contract) : null;
        $rows = [];
        foreach ($targets as $target) {
            $rows[] = $this->targetEvidence($map, $target, $scope);
        }

        if (!$this->stillCurrent($readiness->mapSnapshot)) {
            return $this->abstained('evidence_became_stale', ['map_snapshot' => $readiness->mapSnapshot]);
        }

        $projection = [
            'owner' => 'agent-map',
            'status' => 'ready',
            'evidence_status' => 'current_structural_evidence',
            'authority' => 'none',
            'map_snapshot' => $readiness->mapSnapshot,
            'targets' => $rows,
        ];
        if ($contract !== null) {
            $projection['contract'] = ['revision' => $contract->revision, 'status' => $contract->status];
        }

        return $projection;
    }

    /**
     * Whether the Map the evidence came from is still current: same fingerprint and no
     * source file edited since. The fingerprint alone is stored, so it stays equal when a
     * source changes; only the readiness state notices that.
     */
    public function stillCurrent(string $mapSnapshot): bool
    {
        $after = (new MapReadinessInspector())->inspect($this->mapArtifacts(), false);

        return $after->mapState === 'ready'
            && $after->mapSnapshot !== null
            && hash_equals($mapSnapshot, $after->mapSnapshot);
    }

    /**
     * Runs a projection and abstains when it throws. The evidence is optional context for
     * `enter`; an owner failure while building it must not replace the lifecycle result.
     *
     * @param Closure(): array<string, mixed> $projection
     * @return array<string, mixed>
     */
    public static function abstainOnFailure(Closure $projection): array
    {
        try {
            return $projection();
        } catch (Throwable $exception) {
            return [
                'owner' => 'agent-map',
                'status' => 'abstained',
                'reason' => 'projection_failed',
                'authority' => 'none',
                'detail' => strtok($exception->getMessage(), "\n"),
            ];
        }
    }

    /** @param array<string, mixed> $projection */
    public static function renderText(array $projection): string
    {
        $status = is_string($projection['status'] ?? null) ? $projection['status'] : 'unavailable';
        $text = 'Target evidence (agent-map, ' . $status . ", structural, not scope):\n";
        if ($status !== 'ready') {
            return $text . '  Abstained: ' . (is_string($projection['reason'] ?? null) ? $projection['reason'] : 'unavailable') . "\n";
        }

        foreach (is_array($projection['targets'] ?? null) ? $projection['targets'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $target = is_string($row['target'] ?? null) ? $row['target'] : '';
            if (($row['status'] ?? null) !== 'resolved') {
                $text .= '  ' . $target . ': abstained (' . (is_string($row['reason'] ?? null) ? $row['reason'] : 'unavailable') . ")\n";
                continue;
            }
            $declaration = is_array($row['declaration'] ?? null) ? $row['declaration'] : [];
            $text .= '  ' . $target . ' declared in ' . self::pathText($declaration) . "\n";
            if (($row['references'] ?? null) === 'not_indexed_for_language') {
                $text .= '    references not indexed for ' . (is_string($row['language'] ?? null) ? $row['language'] : 'this language') . " (capability gap, not evidence)\n";
                continue;
            }
            foreach (['test_references' => 'test', 'source_references' => 'source'] as $key => $label) {
                foreach (is_array($row[$key] ?? null) ? $row[$key] : [] as $reference) {
                    if (is_array($reference)) {
                        $counts = is_array($reference['relation_counts'] ?? null) ? $reference['relation_counts'] : [];
                        $text .= '    ' . $label . ' ' . self::pathText($reference) . ' ('
                            . implode(', ', array_map(static fn ($kind, $count): string => $kind . ' ' . (is_int($count) ? $count : 0), array_keys($counts), $counts))
                            . ")\n";
                    }
                }
            }
            if (($row['test_references'] ?? []) === [] && ($row['source_references'] ?? []) === []) {
                $text .= "    no structural references in the current Map (not proof that nothing depends on it)\n";
            }
            $omitted = is_array($row['omitted'] ?? null) ? $row['omitted'] : [];
            if ((int) ($omitted['test_files'] ?? 0) + (int) ($omitted['source_files'] ?? 0) > 0) {
                $text .= sprintf("    omitted: %d test file(s), %d source file(s)\n", (int) ($omitted['test_files'] ?? 0), (int) ($omitted['source_files'] ?? 0));
            }
        }

        return $text;
    }

    /** @param array<mixed> $row */
    private static function pathText(array $row): string
    {
        $path = is_string($row['path'] ?? null) ? $row['path'] : 'unknown';
        if (!array_key_exists('in_contract_scope', $row)) {
            return $path;
        }

        return $path . ($row['in_contract_scope'] === true ? ' [in scope]' : ' [outside scope]');
    }

    /** @SuppressWarnings("PHPMD.StaticAccess") */
    private function mapArtifacts(): MapArtifactPaths
    {
        return MapArtifactPaths::forProject($this->rootPath, (new ProjectLayout($this->rootPath))->mapRoot());
    }

    /** @return array<string, mixed> */
    private function targetEvidence(AgentMapIndex $map, string $target, ?ApprovedScope $scope): array
    {
        $failure = $this->targetFailure($target);
        if ($failure !== null) {
            return ['target' => $target, 'status' => 'abstained', 'reason' => $failure];
        }

        try {
            $resolved = $this->resolve($map, $target);
        } catch (Throwable $exception) {
            return [
                'target' => $target,
                'status' => 'abstained',
                'reason' => preg_match(self::AMBIGUOUS_PREFIX, $exception->getMessage()) === 1 ? 'target_ambiguous' : 'target_unresolved',
                'detail' => strtok($exception->getMessage(), "\n"),
            ];
        }
        if ($resolved === null) {
            return ['target' => $target, 'status' => 'abstained', 'reason' => 'target_unresolved'];
        }

        [$symbolIds, $declarationPath] = $resolved;
        $row = [
            'target' => $target,
            'status' => 'resolved',
            'symbol_id' => $symbolIds[0],
            'declaration' => $this->pathRow($declarationPath, $scope),
        ];
        $language = $map->file($declarationPath)->language ?? 'php';
        if ($language !== 'php') {
            // agent-map records declarations only for these files: an empty reference list
            // would read as "nothing depends on it" when the Map simply cannot tell.
            return [...$row, 'references' => 'not_indexed_for_language', 'language' => $language];
        }

        return [...$row, 'references' => 'resolved_relations', ...$this->references($map, $symbolIds, $declarationPath, $scope)];
    }

    private function targetFailure(string $target): ?string
    {
        if ($target === '') {
            return 'target_empty';
        }
        if (strlen($target) > self::MAX_TARGET_BYTES || str_contains($target, "\0") || preg_match('//u', $target) !== 1) {
            return 'target_invalid';
        }

        return null;
    }

    /**
     * Resolves a target to the Map symbol ids its references point at and the declaring file.
     *
     * @return array{0: non-empty-list<string>, 1: string}|null
     */
    private function resolve(AgentMapIndex $map, string $target): ?array
    {
        $target = ltrim($target, '\\');
        $separator = strrpos($target, '::');
        if ($separator !== false && str_starts_with(substr($target, $separator + 2), '$')) {
            return $this->resolveProperty($map, $target, $separator);
        }
        if ($separator !== false) {
            $method = $map->resolveMethod($target);

            return [[$method->id], $method->file->path];
        }

        $qualified = $this->qualifyType($map, $target);
        $type = $qualified === null ? null : $map->symbolById('type:' . $qualified);
        if ($type !== null) {
            return [$this->typeMemberIds($map, $type['symbol']), $type['file']->path];
        }
        // An exact name wins, so a global `helper()` stays reachable next to `App\helper()`.
        $function = $map->symbolById('function:' . $target);
        if ($function === null) {
            $qualifiedFunction = $this->qualifyFunction($map, $target);
            $function = $qualifiedFunction === null ? null : $map->symbolById('function:' . $qualifiedFunction);
        }

        return $function === null ? null : [[$function['symbol']->id()], $function['file']->path];
    }

    /**
     * The fully qualified name for an unqualified function target that names no global
     * function, when exactly one function in the Map carries that short name.
     */
    private function qualifyFunction(AgentMapIndex $map, string $name): ?string
    {
        if (str_contains($name, '\\') || str_contains($name, '#')) {
            return null;
        }
        $matches = [];
        foreach ($map->files as $file) {
            foreach ($file->symbols as $symbol) {
                if ($symbol->kind === 'function' && $symbol->name === $name) {
                    $matches[] = ltrim($symbol->fqn, '\\');
                }
            }
        }
        $matches = array_values(array_unique($matches));
        if (count($matches) > 1) {
            throw new RuntimeException('Function target is ambiguous: ' . $name . ' matches ' . implode(', ', $matches));
        }

        return $matches[0] ?? null;
    }

    /**
     * The fully qualified name for a class-like target. A short name resolves only when exactly
     * one class, interface, trait or enum in the Map carries it, like agent-map's method lookup.
     */
    private function qualifyType(AgentMapIndex $map, string $name): ?string
    {
        if ($name === '' || str_contains($name, '\\') || str_contains($name, '#')) {
            return $name === '' ? null : $name;
        }
        $matches = [];
        foreach ($map->files as $file) {
            foreach ($file->symbols as $symbol) {
                if ($symbol->name === $name && in_array($symbol->kind, ['class', 'interface', 'trait', 'enum'], true)) {
                    $matches[] = ltrim($symbol->fqn, '\\');
                }
            }
        }
        $matches = array_values(array_unique($matches));
        if (count($matches) > 1) {
            throw new RuntimeException('Type target is ambiguous: ' . $name . ' matches ' . implode(', ', $matches));
        }

        return $matches[0] ?? null;
    }

    /**
     * A type is used through itself and through its members, so its references include both.
     *
     * @return non-empty-list<string>
     */
    private function typeMemberIds(AgentMapIndex $map, SymbolEntry $symbol): array
    {
        $typeId = 'type:' . ltrim($symbol->fqn, '\\');
        $ids = [$symbol->id(), $typeId];
        foreach ($symbol->methods as $method) {
            $ids[] = $symbol->methodId($method);
        }
        foreach ($map->outgoing($typeId, 'declares_property') as $relation) {
            array_push($ids, ...$relation->targetIds);
        }

        return array_values(array_unique($ids));
    }

    /** @return array{0: non-empty-list<string>, 1: string}|null */
    private function resolveProperty(AgentMapIndex $map, string $target, int $separator): ?array
    {
        $class = $this->qualifyType($map, substr($target, 0, $separator));
        if ($class === null) {
            return null;
        }
        $propertyId = 'property:' . $class . substr($target, $separator);
        $declarations = $map->incoming($propertyId, 'declares_property');
        $paths = array_values(array_unique(array_map(static fn (RelationEntry $relation): string => $relation->file, $declarations)));
        if (count($paths) > 1) {
            throw new RuntimeException('Property target is ambiguous: ' . $target);
        }

        return $paths === [] ? null : [[$propertyId], $paths[0]];
    }

    /**
     * @param non-empty-list<string> $symbolIds
     * @return array{test_references: list<array<string, mixed>>, source_references: list<array<string, mixed>>, omitted: array{test_files: int, source_files: int}}
     */
    private function references(AgentMapIndex $map, array $symbolIds, string $declarationPath, ?ApprovedScope $scope): array
    {
        $byPath = [];
        $seen = [];
        foreach ($symbolIds as $symbolId) {
            foreach ($map->incoming($symbolId) as $relation) {
                if (isset($seen[$relation->id]) || !in_array($relation->kind, self::REFERENCE_KINDS, true)) {
                    continue;
                }
                $seen[$relation->id] = true;
                if ($relation->file === $declarationPath) {
                    continue;
                }
                $byPath[$relation->file][$relation->kind] = ($byPath[$relation->file][$relation->kind] ?? 0) + 1;
            }
        }

        $tests = [];
        $sources = [];
        foreach ($byPath as $path => $counts) {
            ksort($counts);
            $row = [...$this->pathRow($path, $scope), 'relation_counts' => $counts];
            if ($map->looksLikeTestPath($path)) {
                $tests[] = $row;
            } else {
                $sources[] = $row;
            }
        }

        return [
            'test_references' => array_slice($this->sorted($tests), 0, self::MAX_FILES_PER_ROLE),
            'source_references' => array_slice($this->sorted($sources), 0, self::MAX_FILES_PER_ROLE),
            'omitted' => [
                'test_files' => max(0, count($tests) - self::MAX_FILES_PER_ROLE),
                'source_files' => max(0, count($sources) - self::MAX_FILES_PER_ROLE),
            ],
        ];
    }

    /**
     * Most references first, then path, so the same Map always yields the same order.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function sorted(array $rows): array
    {
        usort($rows, static function (array $left, array $right): int {
            $total = static fn (array $row): int => is_array($row['relation_counts'] ?? null) ? (int) array_sum($row['relation_counts']) : 0;

            return $total($right) <=> $total($left) ?: (string) $left['path'] <=> (string) $right['path'];
        });

        return $rows;
    }

    /** @return array<string, mixed> */
    private function pathRow(string $path, ?ApprovedScope $scope): array
    {
        $row = ['path' => $path];
        if ($scope !== null) {
            $row['in_contract_scope'] = $scope->contains($path);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function abstained(string $reason, array $details = []): array
    {
        return [
            'owner' => 'agent-map',
            'status' => 'abstained',
            'reason' => $reason,
            'authority' => 'none',
            ...$details,
        ];
    }
}
