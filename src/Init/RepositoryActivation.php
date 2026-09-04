<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use voku\AgentLoop\GitWorkTree;
use voku\AgentLoop\PackageResources;
use voku\AgentLoop\PathResolver;

/**
 * Resolves how agent-loop is actually activated in this repository.
 *
 * Printed activation instructions must describe the repository in front of the
 * agent, not a hypothetical Composer consumer. The root package uses
 * bin/agent-loop and its tracked resources/githooks/ directory; consumers normally use
 * vendor/bin/agent-loop and .githooks/. Consumers whose PHP floor cannot host
 * agent-loop may install it as an isolated Composer tool below tools/.
 */
final readonly class RepositoryActivation
{
    /** @var list<string> */
    private const array HOOK_DIRECTORY_CANDIDATES = ['.githooks', PackageResources::GIT_HOOKS];

    private const string HOOK_POLICY_FILE = '.agent-loop/githooks.json';

    private const string COMMIT_TEMPLATE_FILE = '.gitmessage';

    /** One entry that only the package-owned hook set installs. */
    private const string HOOK_MARKER_ENTRY = 'lib/agent-loop-hooks.sh';

    public function __construct(private string $rootPath)
    {
    }

    public function cliPath(): string
    {
        $expected = $this->isRootPackage() ? 'bin/agent-loop' : 'vendor/bin/agent-loop';
        if (is_file($this->absolute($expected))) {
            return $expected;
        }

        $alternative = $expected === 'bin/agent-loop' ? 'vendor/bin/agent-loop' : 'bin/agent-loop';
        if (is_file($this->absolute($alternative))) {
            return $alternative;
        }

        $isolatedToolCli = $this->isolatedToolCliPath();

        return $isolatedToolCli ?? $expected;
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

    public function declaresGitHookPolicy(): bool
    {
        return is_file($this->absolute(self::HOOK_POLICY_FILE));
    }

    public function declaresCommitTemplate(): bool
    {
        return is_file($this->absolute(self::COMMIT_TEMPLATE_FILE));
    }

    public function gitHooksDirectory(): string
    {
        foreach (self::HOOK_DIRECTORY_CANDIDATES as $candidate) {
            if (is_file($this->absolute($candidate . '/' . self::HOOK_MARKER_ENTRY))) {
                return $candidate;
            }
        }

        return self::HOOK_DIRECTORY_CANDIDATES[0];
    }

    /** @return list<string> */
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

    /** @return non-empty-string */
    public function syncGitHooksCommand(): string
    {
        return $this->cliPath() . ' ' . implode(' ', ['init', 'sync-githooks', ...$this->syncGitHooksTokens()]);
    }

    public function installAssetsCommand(): string
    {
        return $this->cliPath() . ' init install-assets --agent=<' . implode('|', InitAgent::canonicalNames()) . '>';
    }

    /**
     * Configuration, current package content, and executable readiness are one
     * activation fact. This deliberately does not claim that Git executed a hook.
     *
     * @return list<InitCheckResult>
     */
    public function localGitIntegrationChecks(): array
    {
        $root = $this->absolute('');
        if (!GitWorkTree::detected($root)) {
            return [];
        }

        $results = [];
        if ($this->declaresGitHookPolicy()) {
            $results[] = $this->hookPathCheck();
        }

        if ($this->declaresCommitTemplate()) {
            $template = GitWorkTree::configValue($root, 'commit.template');
            if ($template === null || $template === '') {
                $results[] = InitCheckResult::warn(
                    'Commit template: ' . self::COMMIT_TEMPLATE_FILE
                    . ' exists but commit.template is unset; run ' . $this->syncGitHooksCommand(),
                );
            } elseif (!$this->configuredFileMatches($template, self::COMMIT_TEMPLATE_FILE)) {
                $results[] = InitCheckResult::warn(
                    'Commit template: commit.template=' . $template
                    . ' does not point to ' . self::COMMIT_TEMPLATE_FILE . '; run ' . $this->syncGitHooksCommand(),
                );
            } else {
                $results[] = InitCheckResult::ok('Commit template: active via commit.template=' . $template);
            }
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

        $configuredDirectory = PathResolver::join($this->absolute(''), $hooksPath);
        if (!is_dir($configuredDirectory)) {
            return InitCheckResult::warn(
                'Git hooks: core.hooksPath=' . $hooksPath
                . ' points at a missing directory; run ' . $this->syncGitHooksCommand(),
            );
        }

        if (!$this->pointsToExecutableCurrentAgentLoopHooks($configuredDirectory)) {
            return InitCheckResult::warn(
                'Git hooks: core.hooksPath=' . $hooksPath
                . ' does not point to executable current agent-loop pre-commit/commit-msg hooks; run '
                . $this->syncGitHooksCommand(),
            );
        }

        return InitCheckResult::ok('Git hooks: active via core.hooksPath=' . $hooksPath);
    }

    private function pointsToExecutableCurrentAgentLoopHooks(string $configuredDirectory): bool
    {
        $packageHooksRoot = PackageResources::gitHooksRoot();

        foreach (['pre-commit', 'commit-msg'] as $hook) {
            $sourcePath = $packageHooksRoot . '/' . $hook;
            $installedPath = rtrim($configuredDirectory, '/') . '/' . $hook;
            if (!is_file($sourcePath) || !is_file($installedPath)) {
                return false;
            }

            $source = file_get_contents($sourcePath);
            $installed = file_get_contents($installedPath);
            if (!is_string($source) || !is_string($installed) || $source !== $installed) {
                return false;
            }

            if (DIRECTORY_SEPARATOR === '/' && !is_executable($installedPath)) {
                return false;
            }
        }

        return true;
    }

    private function configuredFileMatches(string $configuredPath, string $expectedPath): bool
    {
        $configured = realpath(PathResolver::join($this->absolute(''), $configuredPath));
        $expected = realpath($this->absolute($expectedPath));
        if (!is_string($configured) || !is_string($expected)) {
            return false;
        }

        return str_replace('\\', '/', $configured) === str_replace('\\', '/', $expected);
    }

    private function hasUnmanagedHookFiles(string $hooksDirectory): bool
    {
        $target = $this->absolute($hooksDirectory);
        if (!is_file($target . '/' . self::HOOK_MARKER_ENTRY)) {
            return false;
        }

        return !is_file($target . '/' . InitSyncManifest::fileName());
    }

    private function isolatedToolCliPath(): ?string
    {
        $matches = glob($this->absolute('tools/*/vendor/bin/agent-loop'));
        if (!is_array($matches)) {
            return null;
        }

        $matches = array_values(array_filter($matches, is_file(...)));
        if (count($matches) !== 1) {
            return null;
        }

        return PathResolver::relativeTo($this->rootPath, $matches[0]);
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
