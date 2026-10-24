<?php

declare(strict_types=1);

namespace voku\AgentLoop;

use RuntimeException;
use voku\AgentLoop\Init\InitConfigLoader;
use voku\AgentMap\MapArtifactPaths;

/**
 * The single owner of every repository-local workflow path.
 *
 * Nothing else may spell a state location. Each private literal is a second
 * answer to "where does this live", and the two answers agree only in the
 * default configuration: that is how a read-only projection kept reading a
 * pre-consolidation session root long after the migration, and how a Run's
 * Learning close-out became invisible to the projection that had to explain it.
 *
 * Package-owned trees may own their filenames below a Loop-owned mount point.
 * `describe()` exists so a coding agent can ask instead of hardcoding.
 */
final readonly class ProjectLayout
{
    private const string CONFIG_RELATIVE = '.agent-loop/init.json';

    private const string DEFAULT_STATE_ROOT = '.agent-loop';

    private const string TRUSTED_EXTERNAL_STATE_PATHS_ENV = 'AGENT_LOOP_TRUSTED_EXTERNAL_STATE_PATHS';

    public function __construct(private string $projectRoot)
    {
    }

    public function stateRoot(): string
    {
        return $this->configuredWorkflowPath('state_root') ?? $this->assertWorkflowPathBoundary(
            $this->projectRoot() . '/' . self::DEFAULT_STATE_ROOT,
            'state_root',
        );
    }

    /**
     * Resolve an untrusted persisted project-relative path without allowing it to leave the checkout.
     *
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    public function projectOwnedPath(string $relativePath, string $key): string
    {
        if (trim($relativePath) === '' || PathResolver::isAbsolute($relativePath) || preg_match('/[\x00-\x1f\x7f]/', $relativePath) === 1) {
            throw new RuntimeException('Persisted project path is invalid: ' . $key);
        }

        $path = PathResolver::join($this->projectRoot(), $relativePath);
        $projectRoot = self::canonicalPath($this->projectRoot());
        $resolvedPath = self::canonicalPath($path);
        if ($projectRoot === null || $resolvedPath === null || !self::isWithin($projectRoot, $resolvedPath)) {
            throw new RuntimeException('Persisted project path escapes the project: ' . $key);
        }

        return $path;
    }

    public function boardRoot(): string
    {
        $root = $this->configuredWorkflowPath('board_root')
            ?? (is_dir($this->projectRoot() . '/todo') ? $this->projectRoot() : $this->stateRoot());

        $todoRoot = $this->assertChildWithinRoot($root, 'todo', 'board_root/todo', true);
        $this->assertNoSymlinksBelow($todoRoot, 'Board tree');

        return $root;
    }

    public function tasksRoot(): string
    {
        return $this->stateDirectory('tasks');
    }

    public function sessionsRoot(): string
    {
        $root = $this->configuredWorkflowPath('sessions_root') ?? $this->stateDirectory('sessions');
        $this->assertSessionTreeHasNoSymlinks($root);

        return $root;
    }

    public function learningRoot(): string
    {
        $root = $this->configuredWorkflowPath('learning_root') ?? $this->stateDirectory('learning');
        $this->assertNoSymlinksBelow($root, 'Learning tree');

        return $root;
    }

    public function recallRoot(): string
    {
        return $this->configuredWorkflowPath('recall_root') ?? $this->stateDirectory('recall');
    }

    public function recallTaskDirectory(string $taskId): string
    {
        self::assertTaskId($taskId);
        $directory = $this->ownedChildDirectory($this->recallRoot(), $taskId, 'recall');
        $this->assertNoSymlinksBelow($directory, 'Recall task output');

        return $directory;
    }

    public function recallCurrentDirectory(): string
    {
        $directory = $this->ownedChildDirectory($this->recallRoot(), 'current', 'recall');
        $this->assertNoSymlinksBelow($directory, 'Recall current output');

        return $directory;
    }

    public function learningSubdirectory(string $relativePath): string
    {
        return $this->ownedChildDirectory($this->learningRoot(), $relativePath, 'learning');
    }

    /** Task-scoped destination used by Recall handoff compilation. */
    public function recallHandoffDirectory(string $taskId): string
    {
        self::assertTaskId($taskId);
        $this->recallTaskDirectory($taskId);

        return $this->ownedChildDirectory($this->recallRoot(), $taskId . '/handoff', 'recall');
    }

    public function runsRoot(): string
    {
        return $this->stateDirectory('runs');
    }

    public function diagnosticsRoot(): string
    {
        return $this->stateDirectory('diagnostics');
    }

    public function diagnosticPath(string $taskId, bool $repairs = false): string
    {
        self::assertTaskId($taskId);

        return $this->stateFile('diagnostics/' . $taskId . ($repairs ? '.repairs.json' : '.json'));
    }

    public function acceptedRiskPath(string $taskId, string $extension): string
    {
        self::assertTaskId($taskId);
        if (!in_array($extension, ['md', 'json'], true)) {
            throw new RuntimeException('Invalid accepted-risk file extension.');
        }

        return $this->stateFile('risks/' . $taskId . '.accepted-risk.' . $extension);
    }

    /** Everything durable about one current governed Run lives under this directory. */
    public function runRoot(string $taskId): string
    {
        self::assertTaskId($taskId);
        $runsRoot = $this->runsRoot();
        if (is_link($runsRoot)) {
            throw new RuntimeException('Workflow runs root must not be a symbolic link.');
        }

        $path = $this->stateDirectory('runs/' . $taskId);
        if (is_link($path)) {
            throw new RuntimeException('Workflow task run directory must not be a symbolic link: ' . $taskId);
        }

        return $path;
    }

    public function runFile(string $taskId, string $filename): string
    {
        return $this->runChildFile($taskId, $filename);
    }

    public function runChildFile(string $taskId, string $relativePath): string
    {
        return $this->runChildPath($taskId, $relativePath, false);
    }

    public function runDirectory(string $taskId, string $relativePath): string
    {
        return $this->runChildPath($taskId, $relativePath, true);
    }

    public function isRunPathWithinRunsRoot(string $path): bool
    {
        $rawRunsRoot = $this->runsRoot();
        if (is_link($rawRunsRoot)) {
            return false;
        }
        $runsRoot = self::canonicalPath($rawRunsRoot);
        $resolvedPath = self::canonicalPath($path);
        if ($runsRoot === null || $resolvedPath === null || !self::isWithin($runsRoot, $resolvedPath)) {
            return false;
        }

        $normalizedRoot = rtrim(str_replace('\\', '/', $rawRunsRoot), '/');
        $normalizedPath = str_replace('\\', '/', $path);
        $prefix = $normalizedRoot . '/';
        if (!str_starts_with($normalizedPath, $prefix)) {
            return false;
        }
        $relative = substr($normalizedPath, strlen($prefix));

        return $relative !== '' && !$this->hasSymlinkComponent($rawRunsRoot, $relative);
    }

    /** Superseded governed Runs remain durable and addressable by their original task. */
    public function runHistoryRoot(string $taskId): string
    {
        self::assertTaskId($taskId);
        $runsRoot = $this->runsRoot();
        $historyRoot = $this->stateDirectory('runs/.history');
        if (is_link($runsRoot) || is_link($historyRoot)) {
            throw new RuntimeException('Workflow run history path must not contain a symbolic link.');
        }
        $path = $this->stateDirectory('runs/.history/' . $taskId);
        if (is_link($path)) {
            throw new RuntimeException('Workflow task history directory must not be a symbolic link: ' . $taskId);
        }

        return $path;
    }

    public function contractsRoot(): string
    {
        return $this->stateDirectory('contracts');
    }

    public function contractPath(string $taskId): string
    {
        return $this->stateFile('contracts/' . $taskId . '/contract.json');
    }

    public function contractHistoryRoot(string $taskId): string
    {
        self::assertTaskId($taskId);

        return $this->stateDirectory('contracts/' . $taskId . '/history');
    }

    public function contractHistoryFile(string $taskId, string $filename): string
    {
        self::assertTaskId($taskId);
        if (preg_match('/\Acontract\.\d{3}\.json\z/', $filename) !== 1) {
            throw new RuntimeException('Invalid archived Contract filename.');
        }

        return $this->stateFile('contracts/' . $taskId . '/history/' . $filename);
    }

    public function executionProfileSelectionPath(string $taskId): string
    {
        return $this->stateFile('contracts/' . $taskId . '/execution-profile.json');
    }

    public function executionPlanPath(string $taskId): string
    {
        return $this->runFile($taskId, 'execution-plan.json');
    }

    public function executionStatePath(string $taskId): string
    {
        return $this->runFile($taskId, 'execution-state.json');
    }

    public function executionStateLockPath(string $taskId): string
    {
        return $this->runFile($taskId, 'execution-state.lock');
    }

    public function executionEvidenceRoot(string $taskId): string
    {
        return $this->runDirectory($taskId, 'execution-evidence');
    }

    public function executionAttentionResolutionsRoot(string $taskId): string
    {
        return $this->runDirectory($taskId, 'attention-resolutions');
    }

    public function editRoot(): string
    {
        return $this->stateDirectory('edit');
    }

    public function editBundle(string $taskId): string
    {
        return $this->stateDirectory('edit/' . $taskId);
    }

    /**
     * Resolves all non-empty edit bundles for a task id.
     *
     * A task may have a single default bundle (e.g. `.agent-loop/edit/<task-id>`),
     * or multiple plan-specific bundles (e.g. `.agent-loop/edit/<task-id>-<suffix>`).
     * Empty directories without edit artifacts are ignored.
     *
     * @return list<string>
     */
    public function editBundles(string $taskId): array
    {
        $editRoot = $this->editRoot();
        if (!is_dir($editRoot)) {
            return [];
        }

        $entries = scandir($editRoot);
        if ($entries === false) {
            return [];
        }

        $bundles = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if ($entry !== $taskId && !str_starts_with($entry, $taskId . '-') && !str_starts_with($entry, $taskId . '_')) {
                continue;
            }

            $path = $this->stateDirectory('edit/' . $entry);
            if (!is_dir($path)) {
                continue;
            }

            if (!$this->hasEditBundleArtifacts($path)) {
                continue;
            }

            $bundles[] = $path;
        }

        sort($bundles);

        return $bundles;
    }

    private function hasEditBundleArtifacts(string $directory): bool
    {
        foreach (['verification-result.json', 'execution.json', 'request.json', 'agent-result.json', 'prompt.md'] as $file) {
            if (is_file($directory . '/' . $file)) {
                return true;
            }
        }

        return false;
    }

    public function risksRoot(): string
    {
        return $this->stateDirectory('risks');
    }

    /**
     * A project that already maintains an agent-map index elsewhere should point
     * at it rather than keep a second, weaker copy. Two indexes always diverge,
     * and the one the workflow reads is then not the one the project builds.
     */
    public function mapRoot(): string
    {
        $root = $this->configuredWorkflowPath('map_root') ?? $this->stateDirectory('map');
        $this->assertNoSymlinksBelow($root, 'Map artifact tree');

        return $root;
    }

    public function mapIndex(): string
    {
        return $this->mapArtifacts()->indexJson();
    }

    public function mapSearchIndex(): string
    {
        return $this->mapArtifacts()->searchDatabase();
    }

    public function mapHistoryDatabase(): string
    {
        return $this->mapArtifacts()->historyDatabase();
    }

    public function toolInventory(): string
    {
        return $this->stateFile('tool-inventory.json');
    }

    public function gitHooksConfig(): string
    {
        return $this->stateFile('githooks.json');
    }

    public function dreamAutoRunPath(): string
    {
        return $this->stateFile('dream/auto.json');
    }

    /** @return list<string> */
    public function recallDocumentManifests(): array
    {
        $config = $this->config();
        if ($config['warnings'] !== []) {
            throw new RuntimeException(implode("\n", $config['warnings']));
        }

        $projectRoot = realpath($this->projectRoot());
        if ($projectRoot === false) {
            throw new RuntimeException('Project root cannot be resolved: ' . $this->projectRoot());
        }
        $projectPrefix = rtrim(str_replace('\\', '/', $projectRoot), '/') . '/';
        $manifests = [];
        foreach ($config['recall']['document_manifests'] as $path) {
            $configured = PathResolver::join($this->projectRoot(), $path);
            $resolved = realpath($configured);
            if ($resolved === false || !is_file($resolved)) {
                throw new RuntimeException('Configured recall document manifest not found: ' . $this->display($configured));
            }
            $resolved = str_replace('\\', '/', $resolved);
            if (!str_starts_with($resolved, $projectPrefix)) {
                throw new RuntimeException('Configured recall document manifest escapes the project: ' . $this->display($configured));
            }
            $manifests[] = $resolved;
        }

        return array_values(array_unique($manifests));
    }

    public function configPath(): string
    {
        return $this->projectRoot() . '/' . self::CONFIG_RELATIVE;
    }

    /** Renders an owned absolute path back to how a report or message should show it. */
    public function display(string $absolutePath): string
    {
        return PathResolver::relativeTo($this->projectRoot(), $absolutePath);
    }

    /**
     * Machine-readable layout, so an agent can discover paths instead of
     * assuming them. Values are project-root-relative where they live inside
     * the repository, and absolute when a configured override points elsewhere.
     *
     * @return array<string, string>
     */
    public function describe(): array
    {
        $described = [
            'project_root' => $this->projectRoot(),
            'state_root' => $this->stateRoot(),
            'config' => $this->configPath(),
            'board_root' => $this->boardRoot(),
            'tasks_root' => $this->tasksRoot(),
            'sessions_root' => $this->sessionsRoot(),
            'learning_root' => $this->learningRoot(),
            'recall_root' => $this->recallRoot(),
            'runs_root' => $this->runsRoot(),
            'contracts_root' => $this->contractsRoot(),
            'edit_root' => $this->editRoot(),
            'risks_root' => $this->risksRoot(),
            'map_root' => $this->mapRoot(),
            'map_index' => $this->mapIndex(),
            'map_search_index' => $this->mapSearchIndex(),
            'map_history_database' => $this->mapHistoryDatabase(),
            'tool_inventory' => $this->toolInventory(),
            'githooks_config' => $this->gitHooksConfig(),
        ];

        foreach ($described as $key => $path) {
            if ($key !== 'project_root') {
                $described[$key] = $this->display($path);
            }
        }

        return $described;
    }

    private function mapArtifacts(): MapArtifactPaths
    {
        return MapArtifactPaths::forProject($this->projectRoot(), $this->mapRoot());
    }

    private function projectRoot(): string
    {
        return rtrim(str_replace('\\', '/', $this->projectRoot), '/');
    }

    /**
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    private function configuredWorkflowPath(string $key): ?string
    {
        $value = $this->config()['paths'][$key] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new RuntimeException('Configured workflow state path contains control characters: ' . $key);
        }

        return $this->assertWorkflowPathBoundary(
            PathResolver::join($this->projectRoot(), $value),
            $key,
        );
    }

    private function assertWorkflowPathBoundary(string $path, string $key): string
    {
        $projectRoot = self::canonicalPath($this->projectRoot());
        $resolvedPath = self::canonicalPath($path);
        if ($projectRoot === null || $resolvedPath === null) {
            throw new RuntimeException('Unable to resolve configured workflow state path: ' . $key);
        }
        if (self::isWithin($projectRoot, $resolvedPath)) {
            if (file_exists($path) && !is_dir($path)) {
                throw new RuntimeException('Configured workflow state path is not a directory: ' . $key);
            }

            return $path;
        }

        if (!$this->externalPathIsTrusted($resolvedPath)) {
            throw new RuntimeException(sprintf(
                'Configured workflow state path %s resolves outside the project. After reviewing .agent-loop/init.json, set %s to a JSON list containing its canonical absolute path.',
                $key,
                self::TRUSTED_EXTERNAL_STATE_PATHS_ENV,
            ));
        }

        if (file_exists($path) && !is_dir($path)) {
            throw new RuntimeException('Configured workflow state path is not a directory: ' . $key);
        }

        return $path;
    }

    private function stateDirectory(string $relativePath): string
    {
        return $this->statePath($relativePath, true);
    }

    private function stateFile(string $relativePath): string
    {
        return $this->statePath($relativePath, false);
    }

    private function statePath(string $relativePath, bool $directory): string
    {
        $root = $this->stateRoot();
        if ($this->hasSymlinkComponent($root, $relativePath)) {
            throw new RuntimeException('Workflow state path must not contain a symbolic link: ' . $relativePath);
        }

        return $this->assertChildWithinRoot($root, $relativePath, $relativePath, $directory);
    }

    private function runChildPath(string $taskId, string $relativePath, bool $directory): string
    {
        self::assertTaskId($taskId);
        $normalizedPath = self::normalizeRunRelativePath($relativePath);

        $taskRoot = $this->runRoot($taskId);
        $path = rtrim($taskRoot, '/\\') . '/' . $normalizedPath;
        $this->assertSafeRunChildPath($taskRoot, $path, $normalizedPath, $directory);

        return $path;
    }

    private static function normalizeRunRelativePath(string $relativePath): string
    {
        $segments = explode('/', str_replace('\\', '/', $relativePath));
        foreach ($segments as $segment) {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $segment) !== 1 || str_contains($segment, '..')) {
                throw new RuntimeException('Invalid relative path for workflow run: ' . $relativePath);
            }
        }

        return implode('/', $segments);
    }

    private function assertSafeRunChildPath(string $taskRoot, string $path, string $relativePath, bool $directory): void
    {
        if ($this->hasSymlinkComponent($taskRoot, $relativePath)) {
            throw new RuntimeException('Workflow run path must not contain a symbolic link: ' . $relativePath);
        }

        $resolvedTaskRoot = self::canonicalPath($taskRoot);
        $resolvedPath = self::canonicalPath($path);
        if ($resolvedTaskRoot === null || $resolvedPath === null || !self::isWithin($resolvedTaskRoot, $resolvedPath)) {
            throw new RuntimeException('Workflow run file escapes its task directory: ' . $relativePath);
        }
        if (file_exists($path) && ($directory ? !is_dir($path) : !is_file($path))) {
            throw new RuntimeException('Workflow run path has an unexpected type: ' . $relativePath);
        }
    }

    private function ownedChildDirectory(string $root, string $relativePath, string $owner): string
    {
        $normalizedPath = str_replace('\\', '/', $relativePath);
        $segments = explode('/', $normalizedPath);
        foreach ($segments as $segment) {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $segment) !== 1 || str_contains($segment, '..')) {
                throw new RuntimeException('Invalid relative ' . $owner . ' path: ' . $relativePath);
            }
        }

        if ($this->hasSymlinkComponent($root, $normalizedPath)) {
            throw new RuntimeException('Configured ' . $owner . ' path must not contain a symbolic link: ' . $relativePath);
        }

        return $this->assertChildWithinRoot($root, $normalizedPath, $owner . '/' . $normalizedPath, true);
    }

    private function hasSymlinkComponent(string $root, string $relativePath): bool
    {
        $cursor = rtrim($root, '/\\');
        foreach (explode('/', str_replace('\\', '/', $relativePath)) as $segment) {
            if ($segment === '') {
                return true;
            }
            $cursor .= '/' . $segment;
            if (is_link($cursor)) {
                return true;
            }
        }

        return false;
    }

    private function assertNoSymlinksBelow(string $directory, string $description): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new RuntimeException($description . ' must not contain symbolic links.');
            }
        }
    }

    private function assertSessionTreeHasNoSymlinks(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        // Session APIs append metadata, validation, plan and checkpoint filenames to these paths.
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new RuntimeException('Session tree must not contain symbolic links: ' . $entry->getPathname());
            }
        }
    }

    private static function assertTaskId(string $taskId): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $taskId) !== 1 || str_contains($taskId, '..')) {
            throw new RuntimeException('Invalid task id for workflow run path.');
        }
    }

    private function assertChildWithinRoot(string $root, string $relativePath, string $displayPath, bool $directory): string
    {
        $path = rtrim($root, '/\\') . '/' . $relativePath;
        $resolvedRoot = self::canonicalPath($root);
        $resolvedPath = self::canonicalPath($path);
        if ($resolvedRoot === null || $resolvedPath === null || !self::isWithin($resolvedRoot, $resolvedPath)) {
            $message = str_starts_with($displayPath, 'board_root/')
                ? 'Board path escapes its configured root through traversal or a symbolic link: '
                : 'Workflow state path escapes its configured state root through traversal or a symbolic link: ';
            throw new RuntimeException($message . $displayPath);
        }
        if (file_exists($path) && ($directory ? !is_dir($path) : !is_file($path))) {
            throw new RuntimeException('Workflow state path has an unexpected type: ' . $displayPath);
        }

        return $path;
    }

    private function externalPathIsTrusted(string $resolvedPath): bool
    {
        $trustedPaths = $this->trustedExternalStatePaths();
        if ($trustedPaths === null) {
            return false;
        }

        foreach ($trustedPaths as $trustedPath) {
            if (self::pathsEqual(self::canonicalTrustedExternalPath($trustedPath), $resolvedPath)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<mixed>|null */
    private function trustedExternalStatePaths(): ?array
    {
        $configured = getenv(self::TRUSTED_EXTERNAL_STATE_PATHS_ENV);
        if (!is_string($configured) || trim($configured) === '') {
            return null;
        }

        $trustedPaths = json_decode($configured, true);
        if (!is_array($trustedPaths) || !array_is_list($trustedPaths)) {
            throw new RuntimeException(self::TRUSTED_EXTERNAL_STATE_PATHS_ENV . ' must be a JSON list of canonical absolute paths.');
        }

        return $trustedPaths;
    }

    /** @SuppressWarnings("PHPMD.StaticAccess") */
    private static function canonicalTrustedExternalPath(mixed $trustedPath): string
    {
        if (
            !is_string($trustedPath)
            || trim($trustedPath) === ''
            || preg_match('/[\x00-\x1f\x7f]/', $trustedPath) === 1
            || !PathResolver::isAbsolute($trustedPath)
        ) {
            throw new RuntimeException(self::TRUSTED_EXTERNAL_STATE_PATHS_ENV . ' must contain only canonical absolute paths.');
        }

        $normalizedPath = self::normalizeAbsolutePath($trustedPath);
        $canonicalPath = self::canonicalPath($trustedPath);
        if ($normalizedPath === null || $canonicalPath === null || !self::pathsEqual($normalizedPath, $canonicalPath)) {
            throw new RuntimeException(self::TRUSTED_EXTERNAL_STATE_PATHS_ENV . ' entries must use canonical paths without symlink aliases.');
        }

        return $canonicalPath;
    }

    /**
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    private static function canonicalPath(string $path): ?string
    {
        if (!PathResolver::isAbsolute($path)) {
            $currentDirectory = getcwd();
            if ($currentDirectory === false) {
                return null;
            }
            $path = rtrim($currentDirectory, '/\\') . DIRECTORY_SEPARATOR . $path;
        }

        // Resolve the existing prefix before collapsing dot segments: a symlink
        // followed by `..` is interpreted relative to the symlink target.
        $resolvedPrefix = self::resolveExistingPrefix(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path));
        if ($resolvedPrefix === null) {
            return null;
        }

        $canonical = $resolvedPrefix['canonical'];
        if ($resolvedPrefix['missingSegments'] !== []) {
            $canonical = rtrim($canonical, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . implode(DIRECTORY_SEPARATOR, $resolvedPrefix['missingSegments']);
        }

        return self::normalizeAbsolutePath($canonical);
    }

    /**
     * @return array{canonical: string, missingSegments: list<string>}|null
     */
    private static function resolveExistingPrefix(string $path): ?array
    {
        $probe = $path;
        $missingSegments = [];
        while (!file_exists($probe) && !is_link($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                return null;
            }
            array_unshift($missingSegments, basename($probe));
            $probe = $parent;
        }

        $canonical = realpath($probe);
        if ($canonical === false || ($missingSegments !== [] && !is_dir($canonical))) {
            return null;
        }

        return ['canonical' => $canonical, 'missingSegments' => $missingSegments];
    }

    private static function normalizeAbsolutePath(string $path): ?string
    {
        $pathParts = self::absolutePathParts($path);
        if ($pathParts === null) {
            return null;
        }
        [$prefix, $path] = $pathParts;

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return $prefix . implode('/', $segments);
    }

    /** @return array{string, string}|null */
    private static function absolutePathParts(string $path): ?array
    {
        $path = str_replace('\\', '/', $path);
        if (PHP_OS_FAMILY === 'Windows' && preg_match('/\A([a-zA-Z]:)\//', $path, $matches) === 1) {
            return [strtoupper($matches[1]) . '/', substr($path, 3)];
        }
        if (PHP_OS_FAMILY === 'Windows' && str_starts_with($path, '//')) {
            return ['//', substr($path, 2)];
        }
        if (str_starts_with($path, '/')) {
            return ['/', $path];
        }

        return null;
    }

    private static function isWithin(string $directory, string $path): bool
    {
        $directory = rtrim($directory, '/');
        if ($directory === '') {
            $directory = '/';
        }
        if (self::pathsEqual($directory, $path)) {
            return true;
        }

        $prefix = rtrim($directory, '/') . '/';
        return PHP_OS_FAMILY === 'Windows'
            ? str_starts_with(strtolower($path), strtolower($prefix))
            : str_starts_with($path, $prefix);
    }

    private static function pathsEqual(string $left, string $right): bool
    {
        return PHP_OS_FAMILY === 'Windows'
            ? strtolower(rtrim($left, '/')) === strtolower(rtrim($right, '/'))
            : rtrim($left, '/') === rtrim($right, '/');
    }

    /**
     * @return array{
     *     warnings: list<string>,
     *     paths: array<string, string>,
     *     agents: array<string, array<string, string>>,
     *     recall: array{document_manifests: list<string>}
     * }
     */
    private function config(): array
    {
        return (new InitConfigLoader($this->projectRoot()))->load(self::CONFIG_RELATIVE);
    }
}
