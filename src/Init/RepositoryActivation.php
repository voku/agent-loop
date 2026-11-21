<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use voku\AgentLoop\GitHooks\CommitMessageExamples;
use voku\AgentLoop\GitHooks\GitHookConfig;
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
        return $this->cliPath() . ' init install-assets --agent=<' . implode('|', InitAgent::canonicalNames()) . '>'
            . $this->installAssetsGitSuffix();
    }

    /**
     * Installing host assets and registering Git hooks are separate steps with
     * separate effects. When the repository declares a hook policy, a printed
     * asset step therefore carries --skip-git-config, and the Git step is named
     * on its own as {@see syncGitHooksCommand()}.
     */
    public function installAssetsGitSuffix(): string
    {
        return $this->declaresGitHookPolicy() ? ' --skip-git-config' : '';
    }

    /**
     * The effective core.hooksPath, or null when it is unset.
     */
    public function configuredHooksPath(): ?string
    {
        $value = GitWorkTree::configPathValue($this->absolute(''), 'core.hooksPath');

        return $value === null || trim($value) === '' ? null : trim($value);
    }

    /**
     * Whether Git runs other executable hooks now, from core.hooksPath or, when
     * it is unset, from the Git directory's hooks/ (where CaptainHook, GrumPHP,
     * pre-commit and husky v4 install). Pointing core.hooksPath at $hooksDir
     * would silently disable them, so that is the repository owner's decision,
     * not a sync side effect. A missing directory, Git's `.sample` files and an
     * older agent-loop hook directory do not count.
     */
    public function hooksPathRunsOtherHooks(string $hooksDir): bool
    {
        if ($this->hooksPathPointsTo($hooksDir)) {
            return false;
        }
        $directory = $this->liveHooksDirectory();
        if ($directory === null || !is_dir($directory) || is_file($directory . '/' . self::HOOK_MARKER_ENTRY)) {
            return false;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            $path = $directory . '/' . $entry;
            if ($entry[0] === '.' || str_ends_with($entry, '.sample') || !is_file($path)) {
                continue;
            }
            // Windows has no execute bit for extensionless hook scripts.
            if (DIRECTORY_SEPARATOR === '\\' || is_executable($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The owner decision when other hooks run, naming an exit for each answer
     * so an obeyed host-status converges either way.
     *
     * @return non-empty-string
     */
    public function otherHooksDecision(): string
    {
        $hooksDir = $this->gitHooksDirectory();
        $configured = $this->configuredHooksPath();
        $where = $configured === null
            ? 'core.hooksPath is unset, so Git runs the executable hooks in ' . (GitWorkTree::gitPath($this->absolute(''), 'hooks') ?? '.git/hooks')
            : 'core.hooksPath=' . $configured . ' runs other executable hooks';

        return $where . '; pointing core.hooksPath at ' . $hooksDir . ' would disable them. '
            . 'Whose hooks run is the repository owner\'s decision: either review them, then run git config core.hooksPath ' . $hooksDir
            . ' and ' . $this->syncGitHooksCommand()
            . '; or keep them and remove ' . self::HOOK_POLICY_FILE . ' so this repository stops declaring agent-loop Git hooks';
    }

    /** Absolute directory Git takes hooks from now. */
    private function liveHooksDirectory(): ?string
    {
        $configured = $this->configuredHooksPath();
        $path = $configured ?? GitWorkTree::gitPath($this->absolute(''), 'hooks');
        if ($path === null || trim($path) === '') {
            return null;
        }
        $path = rtrim(str_replace('\\', '/', trim($path)), '/');

        return $this->isAbsolutePath($path) ? $path : $this->absolute($path);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:/#', $path) === 1;
    }

    /**
     * Whether Git already runs hooks from $hooksDir, so writing files there
     * changes hooks that execute on the next commit.
     */
    public function hooksPathPointsTo(string $hooksDir): bool
    {
        $configured = $this->configuredHooksPath();
        if ($configured === null) {
            return false;
        }
        $normalize = static function (string $path): string {
            $path = rtrim($path, '/');

            return str_starts_with($path, './') ? substr($path, 2) : $path;
        };
        $configured = str_replace('\\', '/', $configured);
        $configuredAbsolute = $this->isAbsolutePath($configured) ? $configured : $this->absolute($normalize($configured));
        $targetAbsolute = $this->absolute($normalize($hooksDir));
        $configuredReal = realpath($configuredAbsolute);
        $targetReal = realpath($targetAbsolute);
        if ($configuredReal !== false && $targetReal !== false) {
            return $configuredReal === $targetReal;
        }

        return rtrim($configuredAbsolute, '/') === rtrim($targetAbsolute, '/');
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

            $examplesCheck = $this->commitMessageExamplesCheck();
            if ($examplesCheck !== null) {
                $results[] = $examplesCheck;
            }
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

    /**
     * Installed and current hooks can still enforce the wrong rules; the examples declared next to the rules
     * prove the opposite. Nothing is reported for a project that declares none.
     */
    private function commitMessageExamplesCheck(): ?InitCheckResult
    {
        try {
            $examples = new CommitMessageExamples(GitHookConfig::load($this->absolute('')));
        } catch (InvalidArgumentException $exception) {
            return InitCheckResult::warn('Git hook rules: ' . $exception->getMessage());
        }

        if (!$examples->declared()) {
            return null;
        }

        $problems = $examples->problems();
        if ($problems === []) {
            return InitCheckResult::ok(
                'Git hook rules: ' . $examples->passCount() . ' pass and ' . $examples->failCount()
                . ' fail example(s) behave as declared',
            );
        }

        return InitCheckResult::warn(
            'Git hook rules: ' . count($problems) . ' example(s) do not behave as declared (first: ' . $problems[0]
            . '); run ' . $this->cliPath() . ' githooks verify',
        );
    }

    private function hookPathCheck(): InitCheckResult
    {
        $hooksPath = GitWorkTree::configValue($this->absolute(''), 'core.hooksPath');
        if ($hooksPath === null || $hooksPath === '') {
            if ($this->hooksPathRunsOtherHooks($this->gitHooksDirectory())) {
                return InitCheckResult::warn('Git hooks: ' . $this->otherHooksDecision());
            }

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

        if ($this->hooksPathRunsOtherHooks($this->gitHooksDirectory()) && !$this->pointsToExecutableCurrentAgentLoopHooks($configuredDirectory)) {
            return InitCheckResult::warn('Git hooks: ' . $this->otherHooksDecision());
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
