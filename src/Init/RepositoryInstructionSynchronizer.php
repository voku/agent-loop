<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use RuntimeException;

/**
 * Typed owner mutation for the small marker-owned instruction surface.
 *
 * Project content outside the managed markers is never replaced. A malformed
 * marker boundary is projected as a blocked plan operation, so a host can show
 * the conflict without learning marker semantics or attempting a partial write.
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
                $operations[] = new ManagedAssetOperation(
                    ManagedAssetOperationKind::BLOCKED,
                    $agent,
                    ManagedAssetKind::INSTRUCTIONS,
                    $relativePath,
                    $absolutePath,
                    $exception->getMessage(),
                );
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

    /**
     * Applies only instruction operations present in the exact plan.
     *
     * @return list<ManagedAssetOperation>
     */
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

            $directory = dirname($absolutePath);
            if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create directory for ' . $operation->entry . '.');
            }
            if (file_put_contents($absolutePath, $updated) === false) {
                throw new RuntimeException('Unable to write ' . $operation->entry . '.');
            }
            $applied[] = $operation;
        }

        return $applied;
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
        $begin = InitSyncInstructionsCommand::BEGIN_MARKER;
        $end = InitSyncInstructionsCommand::END_MARKER;
        $beginCount = substr_count($existing, $begin);
        $endCount = substr_count($existing, $end);
        if ($beginCount !== $endCount || $beginCount > 1) {
            throw new RuntimeException(
                $relativePath . ' has malformed or duplicate agent-loop instruction markers; refusing to rewrite project-owned content.',
            );
        }

        $block = $begin . "\n" . trim($body) . "\n" . $end;
        if ($beginCount === 0) {
            if ($existing === '') {
                return $block . "\n";
            }

            return $existing . (str_ends_with($existing, "\n") ? "\n" : "\n\n") . $block . "\n";
        }

        $beginOffset = strpos($existing, $begin);
        $endOffset = strpos($existing, $end);
        if ($beginOffset === false || $endOffset === false || $endOffset < $beginOffset) {
            throw new RuntimeException($relativePath . ' has invalid agent-loop instruction marker order.');
        }

        return substr($existing, 0, $beginOffset)
            . $block
            . substr($existing, $endOffset + strlen($end));
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
}
