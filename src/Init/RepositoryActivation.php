<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use voku\AgentLoop\GitWorkTree;

/**
 * Resolves how agent-loop is actually activated in *this* repository.
 *
 * Every activation instruction agent-loop prints used to be written for a
 * hypothetical consumer project: `vendor/bin/agent-loop`, hooks in `.githooks`,
 * a bare `init sync-githooks`. In the package's own repository none of that is
 * true - the binary lives at `bin/agent-loop`, the package-owned hooks are the
 * tracked `githooks/` sources, and the bare command would install a second,
 * untracked copy beside them. A fresh agent that follows the printed command
 * therefore fails on step one and cannot derive the working variant.
 *
 * So the layout is resolved once, here, and every command that tells an agent
 * what to run next asks this class instead of hard-coding a consumer path.
 */
final readonly class RepositoryActivation
{
    /**
     * Ordered by precedence: the conventional consumer directory first, then the
     * package's own tracked hook sources.
     *
     * @var list<string>
     */
    private const array HOOK_DIRECTORY_CANDIDATES = ['.githooks', 'githooks'];

    private const string HOOK_POLICY_FILE = '.agent-loop/githooks.json';

    private const string COMMIT_TEMPLATE_FILE = '.gitmessage';

    /** One entry that only the package-owned hook set installs. */
    private const string HOOK_MARKER_ENTRY = 'lib/agent-loop-hooks.sh';

    public function __construct(private string $rootPath)
    {
    }

    /**
     * The command an agent should type in this repository.
     *
     * In the package's own checkout `vendor/bin/agent-loop` is never created,
     * because Composer does not link the root package's own binaries.
     */
    public function cliPath(): string
    {
        $expected = $this->isRootPackage() ? 'bin/agent-loop' : 'vendor/bin/agent-loop';
        if (is_file($this->absolute($expected))) {
            return $expected;
        }

        // A fork under a different package name is still its own root package.
        // Naming a path that exists beats naming the one the convention predicts.
        $alternative = $expected === 'bin/agent-loop' ? 'vendor/bin/agent-loop' : 'bin/agent-loop';

        return is_file($this->absolute($alternative)) ? $alternative : $expected;
    }

    public function cliAvailable(): bool
    {
        return is_file($this->absolute($this->cliPath()));
    }

    public function cliCheck(): InitCheckResult
    {
        if ($this->cliAvailable()) {
            return InitCheckResult::ok('CLI: ' . $this->cliPath());
        }

        return InitCheckResult::warn(
            'CLI: ' . $this->cliPath() . ' is missing; install the Composer dependencies before running any other init command',
        );
    }

    /** Whether this repository asked for the package-owned local Git hooks at all. */
    public function declaresGitHookPolicy(): bool
    {
        return is_file($this->absolute(self::HOOK_POLICY_FILE));
    }

    public function declaresCommitTemplate(): bool
    {
        return is_file($this->absolute(self::COMMIT_TEMPLATE_FILE));
    }

    /**
     * The directory `init sync-githooks` should write to here.
     *
     * A directory that already carries the package-owned hook set wins over the
     * default, so the command maintains the hooks this repository has instead of
     * creating a second copy of them somewhere else.
     */
    public function gitHooksDirectory(): string
    {
        foreach (self::HOOK_DIRECTORY_CANDIDATES as $candidate) {
            if (is_file($this->absolute($candidate . '/' . self::HOOK_MARKER_ENTRY))) {
                return $candidate;
            }
        }

        return self::HOOK_DIRECTORY_CANDIDATES[0];
    }

    /**
     * Exact `init sync-githooks` arguments for this repository.
     *
     * `--adopt-existing` is added only when the resolved directory already holds
     * hook files that no sync manifest claims: without it the command refuses to
     * touch them, which is precisely the failure a fresh agent cannot diagnose.
     *
     * @return list<string>
     */
    public function syncGitHooksTokens(): array
    {
        $hooksDirectory = $this->gitHooksDirectory();
        $tokens = ['--hooks-dir=' . $hooksDirectory];

        if ($this->declaresCommitTemplate()) {
            $tokens[] = '--commit-template=' . self::COMMIT_TEMPLATE_FILE;
        }

        if ($this->hasUnmanagedHookFiles($hooksDirectory)) {
            $tokens[] = '--adopt-existing';
        }

        return $tokens;
    }

    public function syncGitHooksCommand(): string
    {
        return implode(' ', [$this->cliPath(), 'init', 'sync-githooks', ...$this->syncGitHooksTokens()]);
    }

    public function installAssetsCommand(): string
    {
        return $this->cliPath() . ' init install-assets --agent=<' . implode('|', InitAgent::canonicalNames()) . '>';
    }

    /**
     * Whether Git in this checkout is pointed at the repository's hook policy and
     * commit template.
     *
     * A tracked hook directory or `.gitmessage` proves intent, never execution:
     * Git runs `.git/hooks` until `core.hooksPath` says otherwise, and a template
     * that `commit.template` does not reference is never shown to anybody.
     *
     * @return list<InitCheckResult>
     */
    public function localGitIntegrationChecks(): array
    {
        if (!GitWorkTree::detected($this->absolute(''))) {
            return [];
        }

        $results = [];
        if ($this->declaresGitHookPolicy()) {
            $results[] = $this->hookPathCheck();
        }

        if ($this->declaresCommitTemplate()) {
            $template = GitWorkTree::configValue($this->absolute(''), 'commit.template');
            $results[] = $template === null || $template === ''
                ? InitCheckResult::warn(
                    'Commit template: ' . self::COMMIT_TEMPLATE_FILE
                    . ' exists but commit.template is unset; run ' . $this->syncGitHooksCommand(),
                )
                : InitCheckResult::ok('Commit template: commit.template=' . $template);
        }

        return $results;
    }

    private function hookPathCheck(): InitCheckResult
    {
        $hooksPath = GitWorkTree::configValue($this->absolute(''), 'core.hooksPath');
        if ($hooksPath === null || $hooksPath === '') {
            return InitCheckResult::warn(
                'Git hooks: project policy exists but core.hooksPath is unset; run ' . $this->syncGitHooksCommand(),
            );
        }

        $configuredDirectory = str_starts_with($hooksPath, '/') ? $hooksPath : $this->absolute($hooksPath);
        if (!is_dir($configuredDirectory)) {
            return InitCheckResult::warn(
                'Git hooks: core.hooksPath=' . $hooksPath . ' points at a missing directory; run ' . $this->syncGitHooksCommand(),
            );
        }

        return InitCheckResult::ok('Git hooks: core.hooksPath=' . $hooksPath);
    }

    private function hasUnmanagedHookFiles(string $hooksDirectory): bool
    {
        $target = $this->absolute($hooksDirectory);
        if (!is_file($target . '/' . self::HOOK_MARKER_ENTRY)) {
            return false;
        }

        return !is_file($target . '/' . InitSyncManifest::fileName());
    }

    private function isRootPackage(): bool
    {
        $composerFile = $this->absolute('composer.json');
        if (!is_file($composerFile)) {
            return false;
        }

        $content = file_get_contents($composerFile);
        if (!is_string($content)) {
            return false;
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) && ($decoded['name'] ?? null) === 'voku/agent-loop';
    }

    private function absolute(string $relativePath): string
    {
        $root = rtrim($this->rootPath, '/');

        return $relativePath === '' ? $root : $root . '/' . $relativePath;
    }
}
