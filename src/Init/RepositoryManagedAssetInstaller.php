<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/** Applies a conflict-free typed install plan without routing through CLI adapters. */
final readonly class RepositoryManagedAssetInstaller
{
    private string $packageRoot;

    public function __construct(private string $rootPath)
    {
        $this->packageRoot = dirname(__DIR__, 2);
    }

    /**
     * @return array{applied:list<ManagedAssetOperation>, blocked:list<ManagedAssetOperation>, messages:list<string>}
     */
    public function apply(ManagedAssetChangePlan $plan, AgentAssetSourcePaths $paths): array
    {
        if ($plan->intent !== ManagedAssetChangePlan::INTENT_INSTALL) {
            throw new InvalidArgumentException('Managed asset installer requires an install plan.');
        }
        if ($plan->blocked !== []) {
            throw new InvalidArgumentException('Refusing to apply an install plan that contains blocked operations.');
        }

        $this->validateOperationTargets($plan, $paths);

        // Resolve and validate every source before the first target write. This
        // prevents a broken later source from leaving an avoidable partial sync.
        $skillSources = $this->skillSources($paths);
        $subagentSources = $this->subagentSources($paths, $plan->agent);
        $this->validateHookSources($paths, $plan);

        $applied = [];
        foreach ([ManagedAssetKind::SKILLS, ManagedAssetKind::SUBAGENTS, ManagedAssetKind::HOOKS] as $kind) {
            $operations = array_values(array_filter(
                $plan->operations,
                static fn (ManagedAssetOperation $operation): bool => $operation->kind === $kind,
            ));
            if ($operations === []) {
                continue;
            }

            if ($kind === ManagedAssetKind::SKILLS) {
                $batch = $this->applySkills($operations, $skillSources, $plan->agent, $paths);
            } elseif ($kind === ManagedAssetKind::SUBAGENTS) {
                $batch = $this->applySubagents($operations, $subagentSources, $plan->agent, $paths);
            } else {
                $batch = $this->applyHooks($operations, $paths, $plan->agent);
            }
            array_push($applied, ...$batch);
        }

        array_push(
            $applied,
            ...(new RepositoryInstructionSynchronizer($this->rootPath))->apply($plan),
        );

        return [
            'applied' => $applied,
            'blocked' => [],
            'messages' => [],
        ];
    }

    /**
     * @return array<string, string> target entry => source directory
     */
    private function skillSources(AgentAssetSourcePaths $paths): array
    {
        $roots = [$paths->absoluteSkillsRoot()];
        foreach (FirstPartySkillRoots::resolve($this->packageRoot) as $root) {
            $roots[] = $root;
        }
        $roots = array_values(array_unique(array_map(
            static fn (string $root): string => rtrim(str_replace('\\', '/', $root), '/'),
            $roots,
        )));

        $sources = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            foreach (scandir($root) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || !is_file($root . '/' . $entry . '/SKILL.md')) {
                    continue;
                }
                $source = $root . '/' . $entry;
                if (isset($sources[$entry]) && realpath($sources[$entry]) !== realpath($source)) {
                    throw new InvalidArgumentException('Multiple skill sources own the same entry: ' . $entry);
                }
                $sources[$entry] = $source;
            }
        }
        ksort($sources, SORT_STRING);

        return $sources;
    }

    /**
     * @return array<string, array{path:string, definition:SubagentDefinition}>
     */
    private function subagentSources(AgentAssetSourcePaths $paths, string $agent): array
    {
        $sources = [];
        $root = $paths->absoluteSubagentsRoot();
        $suffix = (new ManagedAssetTargetCatalog($this->rootPath))->subagentSuffix($agent);
        foreach (is_dir($root) ? (scandir($root) ?: []) : [] as $entry) {
            if ($entry === '.' || $entry === '..' || !str_ends_with($entry, '.md')) {
                continue;
            }
            $path = $root . '/' . $entry;
            if (!is_file($path)) {
                continue;
            }
            $errors = SubagentDefinition::validationErrors($path);
            if ($errors !== []) {
                throw new InvalidArgumentException('Invalid subagent ' . $entry . ': ' . implode('; ', $errors));
            }
            $targetEntry = substr($entry, 0, -3) . $suffix;
            $sources[$targetEntry] = ['path' => $path, 'definition' => SubagentDefinition::fromCanonicalFile($path)];
        }
        ksort($sources, SORT_STRING);

        return $sources;
    }

    /**
     * @param list<ManagedAssetOperation> $operations
     * @param array<string, string> $sources
     * @return list<ManagedAssetOperation>
     */
    private function applySkills(array $operations, array $sources, string $agent, AgentAssetSourcePaths $paths): array
    {
        $target = $this->target($agent, ManagedAssetKind::SKILLS, $paths);
        $projectionSources = [];
        foreach ($target->desiredEntries() ?? [] as $entry) {
            $source = $sources[$entry] ?? null;
            if (!is_string($source)) {
                throw new RuntimeException('No skill source is available for planned entry: ' . $entry);
            }
            $projectionSources[$entry] = ManagedAssetSource::fromPath($this->rootPath, $source, 'skill:' . $entry);
        }

        foreach ($operations as $operation) {
            $source = $sources[$operation->entry] ?? null;
            if (!is_string($source)) {
                throw new RuntimeException('No skill source is available for planned entry: ' . $operation->entry);
            }
            $this->copySkillDirectory($source, $operation->targetPath);
        }

        $this->manifest($target)->writeProjections($projectionSources, [HostCapability::SkillProjection]);

        return $operations;
    }

    /**
     * @param list<ManagedAssetOperation> $operations
     * @param array<string, array{path:string, definition:SubagentDefinition}> $sources
     * @return list<ManagedAssetOperation>
     */
    private function applySubagents(array $operations, array $sources, string $agent, AgentAssetSourcePaths $paths): array
    {
        $target = $this->target($agent, ManagedAssetKind::SUBAGENTS, $paths);
        $projectionSources = [];
        foreach ($target->desiredEntries() ?? [] as $entry) {
            $source = $sources[$entry]['path'] ?? null;
            if (!is_string($source)) {
                throw new RuntimeException('No subagent source is available for planned entry: ' . $entry);
            }
            $projectionSources[$entry] = ManagedAssetSource::fromPath(
                $this->rootPath,
                $source,
                'subagent:' . preg_replace('/(?:\.agent)?\.(?:md|toml)$/', '', basename($entry)),
            );
        }

        $cliPath = (new RepositoryActivation($this->rootPath))->cliPath();
        foreach ($operations as $operation) {
            $definition = $sources[$operation->entry]['definition'] ?? null;
            if (!$definition instanceof SubagentDefinition) {
                throw new RuntimeException('No subagent definition is available for planned entry: ' . $operation->entry);
            }
            $rendered = str_replace('vendor/bin/agent-loop', $cliPath, $definition->renderForClient($agent));
            $this->writeFile($operation->targetPath, $rendered . "\n");
        }

        $this->manifest($target)->writeProjections($projectionSources, [HostCapability::SubagentProjection]);

        return $operations;
    }

    /**
     * @param list<ManagedAssetOperation> $operations
     * @return list<ManagedAssetOperation>
     */
    private function applyHooks(array $operations, AgentAssetSourcePaths $paths, string $agent): array
    {
        if (!in_array($agent, ['codex', 'claude'], true)) {
            throw new InvalidArgumentException('Executable host hooks are not supported for: ' . $agent);
        }

        $target = $this->target($agent, ManagedAssetKind::HOOKS, $paths);
        $projectionSources = [];
        if ($agent === 'codex') {
            $root = $paths->absoluteHooksRoot();
            $definition = CodexHooksDefinition::fromRoot($root);
            foreach ($target->desiredEntries() ?? [] as $entry) {
                $sourcePath = $entry === 'hooks.json' ? $root . '/hooks.json' : $root . '/' . $entry;
                $projectionSources[$entry] = ManagedAssetSource::fromPath($this->rootPath, $sourcePath, 'hooks:codex:' . $entry);
            }
            foreach ($operations as $operation) {
                $content = $operation->entry === 'hooks.json'
                    ? $definition->hooksJsonContent()
                    : file_get_contents($root . '/' . $operation->entry);
                if (!is_string($content)) {
                    throw new RuntimeException('Unable to read planned Codex hook source: ' . $operation->entry);
                }
                $this->writeFile($operation->targetPath, $content);
            }
        } else {
            $root = $paths->absoluteClaudeHooksRoot();
            $definition = ClaudeHooksDefinition::fromRoot($root);
            foreach ($target->desiredEntries() ?? [] as $entry) {
                $sourcePath = $entry === 'settings.json#hooks' ? $root . '/hooks.json' : $root . '/' . $entry;
                $projectionSources[$entry] = ManagedAssetSource::fromPath($this->rootPath, $sourcePath, 'hooks:claude:' . $entry);
            }
            foreach ($operations as $operation) {
                if ($operation->entry === 'settings.json#hooks') {
                    (new ClaudeSettingsHooksWriter(rtrim($target->targetRoot, '/') . '/settings.json'))->write($definition->hooksObject());
                    continue;
                }
                $content = file_get_contents($root . '/' . $operation->entry);
                if (!is_string($content)) {
                    throw new RuntimeException('Unable to read planned Claude hook source: ' . $operation->entry);
                }
                $this->writeFile($operation->targetPath, $content);
            }
        }

        $this->manifest($target)->writeProjections($projectionSources, [
            HostCapability::SessionBootstrap,
            HostCapability::SubagentBootstrap,
            HostCapability::PreToolGuardrail,
        ]);

        return $operations;
    }

    private function validateHookSources(AgentAssetSourcePaths $paths, ManagedAssetChangePlan $plan): void
    {
        if (!$plan->withHooks) {
            return;
        }
        if ($plan->agent === 'codex') {
            $errors = CodexHooksDefinition::validationErrors($paths->absoluteHooksRoot());
        } elseif ($plan->agent === 'claude') {
            $errors = ClaudeHooksDefinition::validationErrors($paths->absoluteClaudeHooksRoot());
        } else {
            throw new InvalidArgumentException('Executable host hooks are only supported for codex or claude.');
        }
        if ($errors !== []) {
            throw new InvalidArgumentException('Invalid bundled hook source: ' . implode('; ', $errors));
        }
    }

    private function validateOperationTargets(ManagedAssetChangePlan $plan, AgentAssetSourcePaths $paths): void
    {
        foreach ($plan->operations as $operation) {
            if ($operation->host !== $plan->agent) {
                throw new InvalidArgumentException('Install plan operation belongs to a different host: ' . $operation->entry);
            }
            if (!in_array($operation->operation, [ManagedAssetOperationKind::ADD, ManagedAssetOperationKind::UPDATE], true)) {
                throw new InvalidArgumentException('Install plan contains a non-install operation: ' . $operation->entry);
            }
            if (!$this->isSafeRelativeEntry($operation->entry)) {
                throw new InvalidArgumentException('Install plan contains an unsafe managed entry: ' . $operation->entry);
            }

            $expectedPath = $operation->kind === ManagedAssetKind::INSTRUCTIONS
                ? rtrim($this->rootPath, '/') . '/' . $operation->entry
                : rtrim($this->target($plan->agent, $operation->kind, $paths)->targetRoot, '/') . '/' . $operation->entry;
            if ($operation->targetPath !== $expectedPath) {
                throw new InvalidArgumentException('Install plan target is outside the managed owner path: ' . $operation->entry);
            }
        }
    }

    private function isSafeRelativeEntry(string $entry): bool
    {
        if ($entry === '' || str_starts_with($entry, '/') || str_contains($entry, '\\')) {
            return false;
        }

        foreach (explode('/', $entry) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function target(string $agent, ManagedAssetKind $kind, AgentAssetSourcePaths $paths): ManagedAssetTarget
    {
        foreach ((new ManagedAssetTargetCatalog($this->rootPath))->targetsForHost($paths, $agent) as $target) {
            if ($target->kind === $kind) {
                return $target;
            }
        }
        throw new RuntimeException('No managed target is defined for ' . $agent . '/' . $kind->value . '.');
    }

    private function manifest(ManagedAssetTarget $target): InitSyncManifest
    {
        return InitSyncManifest::load($target->targetRoot, $target->kind->value, $target->host);
    }

    private function copySkillDirectory(string $sourceDir, string $targetDir): void
    {
        $this->removePath($targetDir);
        if (!mkdir($targetDir, 0o775, true) && !is_dir($targetDir)) {
            throw new InvalidArgumentException('Unable to create target directory: ' . $targetDir);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        $cliPath = (new RepositoryActivation($this->rootPath))->cliPath();
        $vendorRoot = dirname(dirname($cliPath));
        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen(rtrim($sourceDir, '/')) + 1);
            $destinationPath = $targetDir . '/' . $relativePath;
            if ($item->isDir()) {
                if (!is_dir($destinationPath) && !mkdir($destinationPath, 0o775, true) && !is_dir($destinationPath)) {
                    throw new InvalidArgumentException('Unable to create directory: ' . $destinationPath);
                }
                continue;
            }
            $content = file_get_contents($item->getPathname());
            if (!is_string($content)) {
                throw new InvalidArgumentException('Unable to read projected skill file: ' . $item->getPathname());
            }
            $content = str_replace(
                ['vendor/bin/agent-loop', 'vendor/bin/agent-recall-compiler', 'vendor/voku/agent-recall-compiler/'],
                [$cliPath, $vendorRoot . '/bin/agent-recall-compiler', $vendorRoot . '/voku/agent-recall-compiler/'],
                $content,
            );
            $this->writeFile($destinationPath, $content);
        }
    }

    private function writeFile(string $path, string $content): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new InvalidArgumentException('Unable to create target directory: ' . $directory);
        }
        if (file_put_contents($path, $content) === false) {
            throw new InvalidArgumentException('Unable to write managed asset: ' . $path);
        }
    }

    private function removePath(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            if (!unlink($path)) {
                throw new InvalidArgumentException('Unable to replace managed file: ' . $path);
            }
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        if (!rmdir($path)) {
            throw new InvalidArgumentException('Unable to replace managed directory: ' . $path);
        }
    }
}
