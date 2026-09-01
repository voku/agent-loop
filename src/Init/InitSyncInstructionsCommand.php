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

    private const string ROUTER_FILE = 'AGENTS.md';

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

            if ($agent->isAll() || in_array($agent->canonicalName(), ['gemini', 'antigravity'], true)) {
                $this->syncImportFile('GEMINI.md', '@./AGENTS.md', $dryRun);
            }
        } catch (RuntimeException $exception) {
            fwrite(\STDERR, '[FAIL] sync instructions: ' . $exception->getMessage() . "\n");

            return 1;
        }

        return 0;
    }

    /**
     * Read-only counterpart to run(): true means rerunning sync-instructions for
     * this host would leave every host-visible instruction file unchanged.
     *
     * Invalid host names, unreadable sources, and malformed managed markers are
     * inspection failures rather than another spelling of "not current".
     */
    public function isCurrentFor(string $requestedAgent): bool
    {
        $agent = InitAgent::parse($requestedAgent, InitAgent::canonicalNames());
        $router = $this->routerSource();

        if (!$this->managedFileIsCurrent('AGENTS.md', $router)) {
            return false;
        }

        if ($agent->canonicalName() === 'claude' && !$this->importFileIsCurrent('CLAUDE.md', '@AGENTS.md')) {
            return false;
        }

        if (in_array($agent->canonicalName(), ['gemini', 'antigravity'], true)
            && !$this->importFileIsCurrent('GEMINI.md', '@./AGENTS.md')
        ) {
            return false;
        }

        return true;
    }

    private function syncImportFile(string $relativePath, string $import, bool $dryRun): void
    {
        $absolutePath = $this->rootPath . '/' . $relativePath;
        if ($this->resolvesToRouter($relativePath)) {
            echo '[OK] sync instructions: ' . $relativePath . ' is a symlink to ' . self::ROUTER_FILE
                . '; the import is already satisfied and writing it would overwrite the router.' . "\n";

            return;
        }

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

    private function managedFileIsCurrent(string $relativePath, string $body): bool
    {
        $existing = $this->readOptional($this->rootPath . '/' . $relativePath);
        if ($existing === null) {
            return false;
        }

        return $this->mergeManagedBlock($existing, $body, $relativePath) === $existing;
    }

    private function importFileIsCurrent(string $relativePath, string $import): bool
    {
        // A link to the router already resolves to the router's own content, so
        // there is nothing to repair. Reporting it as stale would make
        // `init host-status` demand the same destructive write forever.
        if ($this->resolvesToRouter($relativePath)) {
            return true;
        }

        $existing = $this->readOptional($this->rootPath . '/' . $relativePath);
        if ($existing === null) {
            return false;
        }
        if (!$this->hasManagedMarker($existing) && $this->alreadyImportsAgents($existing)) {
            return true;
        }

        return $this->mergeManagedBlock($existing, $import, $relativePath) === $existing;
    }

    /**
     * An import file may be a symlink to AGENTS.md. Writing "@AGENTS.md" through
     * that link replaces the router block with an import of itself and destroys
     * the instructions this command exists to project.
     */
    private function resolvesToRouter(string $relativePath): bool
    {
        if ($relativePath === self::ROUTER_FILE) {
            return false;
        }

        $absolutePath = $this->rootPath . '/' . $relativePath;
        if (!is_link($absolutePath)) {
            return false;
        }

        $linked = realpath($absolutePath);
        $router = realpath($this->rootPath . '/' . self::ROUTER_FILE);

        return $linked !== false && $router !== false && $linked === $router;
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
