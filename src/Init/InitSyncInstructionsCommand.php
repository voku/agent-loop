<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use RuntimeException;
use voku\AgentLoop\Cli\OptionTokens;

/**
 * Projects the small always-on agent-loop router into host instruction files.
 *
 * Only the content between the agent-loop markers is owned by this command.
 * Existing project instructions outside the markers remain untouched.
 */
final readonly class InitSyncInstructionsCommand
{
    public const string BEGIN_MARKER = '<!-- agent-loop:project-instructions:begin -->';
    public const string END_MARKER = '<!-- agent-loop:project-instructions:end -->';

    private const string CLI_PLACEHOLDER = '{{agent_loop_cli}}';

    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        $argumentError = $this->validateTokens($tokens);
        if ($argumentError !== null) {
            fwrite(\STDERR, $argumentError . "\n");

            return 1;
        }

        $requestedAgent = OptionTokens::value($tokens, 'agent');
        if ($requestedAgent === null) {
            fwrite(\STDERR, "Missing required option: --agent\n");

            return 1;
        }

        try {
            $agent = InitAgent::parse($requestedAgent, InitAgent::canonicalNames(), true);
            $router = $this->routerSource();
        } catch (InvalidArgumentException|RuntimeException $exception) {
            fwrite(\STDERR, $exception->getMessage() . "\n");

            return 1;
        }

        foreach ($agent->messages() as $message) {
            echo $message . "\n";
        }

        $dryRun = in_array('--dry-run', $tokens, true);

        try {
            $this->syncManagedFile('AGENTS.md', $router, $dryRun);

            if ($agent->isAll() || $agent->canonicalName() === 'claude') {
                $this->syncImportFile('CLAUDE.md', '@AGENTS.md', $dryRun);
            }

            if ($agent->isAll() || $agent->canonicalName() === 'antigravity') {
                $this->syncImportFile('GEMINI.md', '@./AGENTS.md', $dryRun);
            }
        } catch (RuntimeException $exception) {
            fwrite(\STDERR, '[FAIL] sync instructions: ' . $exception->getMessage() . "\n");

            return 1;
        }

        return 0;
    }

    private function syncImportFile(string $relativePath, string $import, bool $dryRun): void
    {
        $absolutePath = $this->rootPath . '/' . $relativePath;
        $existing = $this->readOptional($absolutePath);
        if ($existing !== null && !$this->hasManagedMarker($existing) && $this->alreadyImportsAgents($existing)) {
            echo '[OK] sync instructions: ' . $relativePath . ' already imports AGENTS.md; existing import preserved.' . "\n";

            return;
        }

        $this->syncManagedFile($relativePath, $import, $dryRun);
    }

    private function syncManagedFile(string $relativePath, string $body, bool $dryRun): void
    {
        $absolutePath = $this->rootPath . '/' . $relativePath;
        $existing = $this->readOptional($absolutePath) ?? '';
        $updated = $this->mergeManagedBlock($existing, $body, $relativePath);

        if ($updated === $existing) {
            echo '[OK] sync instructions: ' . $relativePath . ' is current.' . "\n";

            return;
        }

        if ($dryRun) {
            echo '[DRY-RUN] sync instructions: update ' . $relativePath . '.' . "\n";

            return;
        }

        $directory = dirname($absolutePath);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create directory for ' . $relativePath . '.');
        }

        if (file_put_contents($absolutePath, $updated) === false) {
            throw new RuntimeException('Unable to write ' . $relativePath . '.');
        }

        echo '[OK] sync instructions: updated ' . $relativePath . '.' . "\n";
    }

    private function mergeManagedBlock(string $existing, string $body, string $relativePath): string
    {
        $beginCount = substr_count($existing, self::BEGIN_MARKER);
        $endCount = substr_count($existing, self::END_MARKER);
        if ($beginCount !== $endCount || $beginCount > 1) {
            throw new RuntimeException(
                $relativePath . ' has malformed or duplicate agent-loop instruction markers; refusing to rewrite project-owned content.',
            );
        }

        $block = self::BEGIN_MARKER . "\n" . trim($body) . "\n" . self::END_MARKER;
        if ($beginCount === 0) {
            if ($existing === '') {
                return $block . "\n";
            }

            $separator = str_ends_with($existing, "\n") ? "\n" : "\n\n";

            return $existing . $separator . $block . "\n";
        }

        $begin = strpos($existing, self::BEGIN_MARKER);
        $end = strpos($existing, self::END_MARKER);
        if ($begin === false || $end === false || $end < $begin) {
            throw new RuntimeException($relativePath . ' has invalid agent-loop instruction marker order.');
        }

        $after = $end + strlen(self::END_MARKER);

        return substr($existing, 0, $begin) . $block . substr($existing, $after);
    }

    /**
     * The router is the only agent-loop text a host loads without being asked,
     * so the commands in it have to be the ones that work here. The package
     * source keeps a CLI token that projection resolves: a consumer gets
     * `vendor/bin/agent-loop`, the package's own checkout gets `bin/agent-loop`,
     * which is the only path that exists there.
     */
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
        return str_contains($content, self::BEGIN_MARKER) || str_contains($content, self::END_MARKER);
    }

    /** @param list<string> $tokens */
    private function validateTokens(array $tokens): ?string
    {
        $count = count($tokens);
        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if ($token === '--dry-run') {
                continue;
            }

            if ($token === '--agent') {
                $candidate = $tokens[$index + 1] ?? null;
                if (!is_string($candidate) || str_starts_with($candidate, '--')) {
                    return 'Missing value for init sync-instructions option: --agent';
                }
                ++$index;
                continue;
            }

            if (str_starts_with($token, '--agent=') && substr($token, strlen('--agent=')) !== '') {
                continue;
            }

            return 'Unknown init sync-instructions argument: ' . $token;
        }

        return null;
    }
}
