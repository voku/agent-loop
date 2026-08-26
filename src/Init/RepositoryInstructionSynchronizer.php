<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use RuntimeException;

/**
 * Typed owner mutation for the small marker-owned instruction surface.
 *
 * Project content outside the managed markers is never replaced. Malformed
 * marker boundaries are projected as blocked operations, so a host can show a
 * conflict without learning marker semantics or attempting a partial write.
 */
final readonly class RepositoryInstructionSynchronizer
{
    private const string CLI_PLACEHOLDER = '{{agent_loop_cli}}';

    public function __construct(private string $rootPath)
    {
    }

    /** @return list<ManagedAssetOperation> */
    public function plan(string $agent): array
    {
        $operations = [];
        foreach ($this->desiredFiles($agent) as $relativePath => $body) {
            $absolutePath = $this->rootPath . '/' . $relativePath;
            $existing = $this->readOptional($absolutePath);
            if ($this->isCurrent($relativePath, $body, $existing)) {
                continue;
            }

            try {
                $this->desiredContent($relativePath, $body, $existing ?? '');
            } catch (RuntimeException $exception) {
                $operations[] = $this->blocked($agent, $relativePath, $absolutePath, $exception->getMessage());
                continue;
            }

            $operations[] = new ManagedAssetOperation(
                $existing === null ? ManagedAssetOperationKind::ADD : ManagedAssetOperationKind::UPDATE,
                $agent,
                ManagedAssetKind::INSTRUCTIONS,
                $relativePath,
                $absolutePath,
            );
        }

        return $operations;
    }

    /** @return list<ManagedAssetOperation> */
    public function planUninstall(string $agent): array
    {
        $operations = [];
        foreach ($this->ownedRelativePaths($agent) as $relativePath) {
            $absolutePath = $this->rootPath . '/' . $relativePath;
            $existing = $this->readOptional($absolutePath);
            if ($existing === null || !$this->hasManagedMarker($existing)) {
                continue;
            }

            try {
                $this->withoutManagedBlock($existing, $relativePath);
            } catch (RuntimeException $exception) {
                $operations[] = $this->blocked($agent, $relativePath, $absolutePath, $exception->getMessage());
                continue;
            }

            $operations[] = new ManagedAssetOperation(
                ManagedAssetOperationKind::REMOVE,
                $agent,
                ManagedAssetKind::INSTRUCTIONS,
                $relativePath,
                $absolutePath,
            );
        }

        return $operations;
    }

    /** @return list<string> absolute marker-owned files that may affect a setup plan */
    public function stateFiles(string $agent): array
    {
        $files = array_map(
            fn (string $relativePath): string => $this->rootPath . '/' . $relativePath,
            $this->ownedRelativePaths($agent),
        );
        sort($files, SORT_STRING);

        return $files;
    }

    /** @return list<ManagedAssetOperation> */
    public function apply(ManagedAssetChangePlan $plan): array
    {
        $desired = $this->desiredFiles($plan->agent);
        $applied = [];

        foreach ($plan->operations as $operation) {
            if ($operation->kind !== ManagedAssetKind::INSTRUCTIONS) {
                continue;
            }
            $body = $desired[$operation->entry] ?? null;
            if (!is_string($body)) {
                throw new RuntimeException('Instruction plan contains an entry not owned for this host: ' . $operation->entry);
            }

            $absolutePath = $this->rootPath . '/' . $operation->entry;
            if ($absolutePath !== $operation->targetPath) {
                throw new RuntimeException('Instruction plan target changed before application: ' . $operation->entry);
            }

            $existing = $this->readOptional($absolutePath) ?? '';
            $updated = $this->desiredContent($operation->entry, $body, $existing);
            if ($updated === $existing) {
                continue;
            }

            $this->writeFile($absolutePath, $updated);
            $applied[] = $operation;
        }

        return $applied;
    }

    /**
     * Removes only marker-owned instruction content and preserves every byte
     * outside the managed block.
     *
     * @return array{applied:list<ManagedAssetOperation>, blocked:list<ManagedAssetOperation>, messages:list<string>}
     */
    public function applyUninstall(ManagedAssetChangePlan $plan): array
    {
        $applied = [];
        $blocked = [];

        foreach ($plan->operations as $operation) {
            if ($operation->kind !== ManagedAssetKind::INSTRUCTIONS) {
                continue;
            }
            if ($operation->operation !== ManagedAssetOperationKind::REMOVE) {
                throw new RuntimeException('Instruction uninstaller only accepts removal operations.');
            }
            if (!in_array($operation->entry, $this->ownedRelativePaths($plan->agent), true)) {
                throw new RuntimeException('Instruction removal plan contains an entry not owned for this host: ' . $operation->entry);
            }

            $absolutePath = $this->rootPath . '/' . $operation->entry;
            if ($absolutePath !== $operation->targetPath) {
                throw new RuntimeException('Instruction removal target changed before application: ' . $operation->entry);
            }

            $existing = $this->readOptional($absolutePath);
            if ($existing === null) {
                $blocked[] = $this->blocked($plan->agent, $operation->entry, $absolutePath, 'The managed instruction file disappeared before removal.');
                continue;
            }

            try {
                $updated = $this->withoutManagedBlock($existing, $operation->entry);
            } catch (RuntimeException $exception) {
                $blocked[] = $this->blocked($plan->agent, $operation->entry, $absolutePath, $exception->getMessage());
                continue;
            }

            if (trim($updated) === '') {
                if (!unlink($absolutePath)) {
                    $blocked[] = $this->blocked($plan->agent, $operation->entry, $absolutePath, 'The managed instruction file could not be removed.');
                    continue;
                }
            } elseif (file_put_contents($absolutePath, $updated) === false) {
                $blocked[] = $this->blocked($plan->agent, $operation->entry, $absolutePath, 'The managed instruction block could not be removed.');
                continue;
            }

            $applied[] = $operation;
        }

        return [
            'applied' => $applied,
            'blocked' => $blocked,
            'messages' => $applied === [] ? [] : ['Removed ' . count($applied) . ' managed instruction file/block(s).'],
        ];
    }

    /** @return array<string, string> relative path => desired managed body */
    private function desiredFiles(string $agent): array
    {
        $files = ['AGENTS.md' => $this->routerSource()];
        if ($agent === 'claude') {
            $files['CLAUDE.md'] = '@AGENTS.md';
        }
        if (in_array($agent, ['gemini', 'antigravity'], true)) {
            $files['GEMINI.md'] = '@./AGENTS.md';
        }

        return $files;
    }

    /** @return list<string> */
    private function ownedRelativePaths(string $agent): array
    {
        $paths = ['AGENTS.md'];
        if ($agent === 'claude') {
            $paths[] = 'CLAUDE.md';
        }
        if (in_array($agent, ['gemini', 'antigravity'], true)) {
            $paths[] = 'GEMINI.md';
        }

        return $paths;
    }

    private function isCurrent(string $relativePath, string $body, ?string $existing): bool
    {
        if ($existing === null) {
            return false;
        }
        if ($relativePath !== 'AGENTS.md'
            && !$this->hasManagedMarker($existing)
            && $this->alreadyImportsAgents($existing)
        ) {
            return true;
        }

        return $this->mergeManagedBlock($existing, $body, $relativePath) === $existing;
    }

    private function desiredContent(string $relativePath, string $body, string $existing): string
    {
        if ($relativePath !== 'AGENTS.md'
            && !$this->hasManagedMarker($existing)
            && $this->alreadyImportsAgents($existing)
        ) {
            return $existing;
        }

        return $this->mergeManagedBlock($existing, $body, $relativePath);
    }

    private function mergeManagedBlock(string $existing, string $body, string $relativePath): string
    {
        $range = $this->managedBlockRange($existing, $relativePath);
        $block = InitSyncInstructionsCommand::BEGIN_MARKER . "\n" . trim($body) . "\n" . InitSyncInstructionsCommand::END_MARKER;
        if ($range === null) {
            if ($existing === '') {
                return $block . "\n";
            }

            return $existing . (str_ends_with($existing, "\n") ? "\n" : "\n\n") . $block . "\n";
        }

        return substr($existing, 0, $range['start'])
            . $block
            . substr($existing, $range['end']);
    }

    private function withoutManagedBlock(string $existing, string $relativePath): string
    {
        $range = $this->managedBlockRange($existing, $relativePath);
        if ($range === null) {
            throw new RuntimeException($relativePath . ' no longer contains an agent-loop managed instruction marker.');
        }

        return substr($existing, 0, $range['start']) . substr($existing, $range['end']);
    }

    /** @return array{start:int,end:int}|null */
    private function managedBlockRange(string $existing, string $relativePath): ?array
    {
        $begin = InitSyncInstructionsCommand::BEGIN_MARKER;
        $end = InitSyncInstructionsCommand::END_MARKER;
        $beginCount = substr_count($existing, $begin);
        $endCount = substr_count($existing, $end);
        if ($beginCount !== $endCount || $beginCount > 1) {
            throw new RuntimeException(
                $relativePath . ' has malformed or duplicate agent-loop instruction markers; refusing to rewrite project-owned content.',
            );
        }
        if ($beginCount === 0) {
            return null;
        }

        $beginOffset = strpos($existing, $begin);
        $endOffset = strpos($existing, $end);
        if ($beginOffset === false || $endOffset === false || $endOffset < $beginOffset) {
            throw new RuntimeException($relativePath . ' has invalid agent-loop instruction marker order.');
        }

        return [
            'start' => $beginOffset,
            'end' => $endOffset + strlen($end),
        ];
    }

    private function routerSource(): string
    {
        $path = dirname(__DIR__, 2) . '/docs/agents/project-instructions.md';
        $content = file_get_contents($path);
        if (!is_string($content) || trim($content) === '') {
            throw new RuntimeException('Package project instruction source is missing or empty: ' . $path);
        }

        return str_replace(
            self::CLI_PLACEHOLDER,
            (new RepositoryActivation($this->rootPath))->cliPath(),
            $content,
        );
    }

    private function readOptional(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $content = file_get_contents($path);
        if (!is_string($content)) {
            throw new RuntimeException('Unable to read project instruction file: ' . $path);
        }

        return $content;
    }

    private function alreadyImportsAgents(string $content): bool
    {
        return preg_match('/(?m)^\s*@(?:\.\/)?AGENTS\.md\s*$/', $content) === 1;
    }

    private function hasManagedMarker(string $content): bool
    {
        return str_contains($content, InitSyncInstructionsCommand::BEGIN_MARKER)
            || str_contains($content, InitSyncInstructionsCommand::END_MARKER);
    }

    private function writeFile(string $path, string $content): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create directory for ' . basename($path) . '.');
        }
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException('Unable to write ' . basename($path) . '.');
        }
    }

    private function blocked(string $agent, string $entry, string $targetPath, string $reason): ManagedAssetOperation
    {
        return new ManagedAssetOperation(
            ManagedAssetOperationKind::BLOCKED,
            $agent,
            ManagedAssetKind::INSTRUCTIONS,
            $entry,
            $targetPath,
            $reason,
        );
    }
}
