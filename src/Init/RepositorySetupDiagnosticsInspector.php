<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use voku\AgentLoop\GitWorkTree;
use voku\AgentLoop\PackageResources;

/**
 * Mutation-free producer of the typed repository-setup diagnostics.
 *
 * This owns the decisions `init doctor` used to make inline. The command is
 * now an adapter that renders what this inspector already decided, so the CLI
 * and every typed host consumer answer from one place instead of two.
 *
 * Nothing here repairs, installs, or rewrites: inspecting a repository must
 * never change it.
 */
final readonly class RepositorySetupDiagnosticsInspector
{
    /** @var list<string> */
    private const array MAKEFILE_CANDIDATES = ['Makefile', 'makefile', 'GNUmakefile', 'Makefile.agent-loop.mk'];

    /** @var list<string> */
    private const array MIGRATION_TARGETS = [
        'validate_agent_skills',
        'validate_agent_subagents',
        'validate_codex_hooks',
        'install_codex_skills',
        'install_copilot_skills',
        'install_claude_skills',
        'install_gemini_skills',
        'install_antigravity_skills',
        'install_agent_skills',
        'install_copilot_agents',
        'install_gemini_agents',
        'install_antigravity_agents',
        'install_agent_subagents',
        'install_codex_hooks',
    ];

    public function __construct(
        private string $rootPath,
        private ?HostRuntimeProbe $runtimeProbe = null,
    ) {
    }

    public function inspect(AgentAssetSourcePaths $paths): RepositorySetupDiagnostics
    {
        return new RepositorySetupDiagnostics([
            $this->phpRuntime(),
            $this->composer(),
            $this->git(),
            ...$this->gitIntegration(),
            ...$this->make(),
            ...$this->sourceRoots($paths),
            ...$this->hosts(),
            $this->sourceSkills($paths),
            $this->sourceSubagents($paths),
            $this->optionalHooks($paths),
            $this->sourceTools($paths),
            ...$this->managedAssetDrift($paths),
            $this->workflowBoundary(),
        ]);
    }

    private function phpRuntime(): RepositorySetupDiagnostic
    {
        $current = \PHP_VERSION;
        if (version_compare($current, '8.3.0', '>=')) {
            return new RepositorySetupDiagnostic(
                RepositorySetupDiagnosticKind::PHP_RUNTIME,
                RepositorySetupDiagnosticLevel::OK,
                'PHP: ' . $current,
                facts: ['version' => $current, 'minimum' => '8.3.0'],
            );
        }

        return new RepositorySetupDiagnostic(
            RepositorySetupDiagnosticKind::PHP_RUNTIME,
            RepositorySetupDiagnosticLevel::WARN,
            'PHP: ' . $current . ' detected, expected >= 8.3',
            facts: ['version' => $current, 'minimum' => '8.3.0'],
        );
    }

    private function composer(): RepositorySetupDiagnostic
    {
        $composerFile = rtrim($this->rootPath, '/') . '/composer.json';
        if (!is_file($composerFile)) {
            return new RepositorySetupDiagnostic(
                RepositorySetupDiagnosticKind::COMPOSER,
                RepositorySetupDiagnosticLevel::WARN,
                'Composer: composer.json not found',
                facts: ['path' => $composerFile, 'state' => 'missing'],
            );
        }

        $content = file_get_contents($composerFile);
        $decoded = is_string($content) ? json_decode($content, true) : null;
        if (!is_array($decoded)) {
            return new RepositorySetupDiagnostic(
                RepositorySetupDiagnosticKind::COMPOSER,
                RepositorySetupDiagnosticLevel::WARN,
                'Composer: invalid composer.json',
                facts: ['path' => $composerFile, 'state' => 'invalid'],
            );
        }

        return new RepositorySetupDiagnostic(
            RepositorySetupDiagnosticKind::COMPOSER,
            RepositorySetupDiagnosticLevel::OK,
            'Composer: composer.json found',
            facts: ['path' => $composerFile, 'state' => 'present'],
        );
    }

    private function git(): RepositorySetupDiagnostic
    {
        $detected = GitWorkTree::detected(rtrim($this->rootPath, '/'));

        return new RepositorySetupDiagnostic(
            RepositorySetupDiagnosticKind::GIT,
            $detected ? RepositorySetupDiagnosticLevel::OK : RepositorySetupDiagnosticLevel::WARN,
            $detected ? 'Git: working tree detected' : 'Git: no working tree detected',
            facts: ['work_tree' => $detected ? 'detected' : 'missing'],
        );
    }

    /** @return list<RepositorySetupDiagnostic> */
    private function gitIntegration(): array
    {
        $diagnostics = [];
        foreach ((new RepositoryActivation($this->rootPath))->localGitIntegrationChecks() as $check) {
            $diagnostics[] = new RepositorySetupDiagnostic(
                RepositorySetupDiagnosticKind::GIT_INTEGRATION,
                $check->level(),
                $check->message(),
            );
        }

        return $diagnostics;
    }

    /** @return list<RepositorySetupDiagnostic> */
    private function make(): array
    {
        $foundMakefiles = [];
        foreach (self::MAKEFILE_CANDIDATES as $candidate) {
            $absolutePath = rtrim($this->rootPath, '/') . '/' . $candidate;
            if (is_file($absolutePath)) {
                $foundMakefiles[$candidate] = $absolutePath;
            }
        }

        if ($foundMakefiles === []) {
            return [
                new RepositorySetupDiagnostic(
                    RepositorySetupDiagnosticKind::MAKE,
                    RepositorySetupDiagnosticLevel::WARN,
                    'Make: no Makefile found',
                    facts: ['state' => 'missing'],
                ),
                new RepositorySetupDiagnostic(
                    RepositorySetupDiagnosticKind::MAKE,
                    RepositorySetupDiagnosticLevel::WARN,
                    'Make agent assets: no migration-compatible agent asset targets found',
                    facts: ['targets' => ''],
                ),
            ];
        }

        $makefileContents = $this->readMakefileContents($foundMakefiles);
        $makefile = array_key_first($foundMakefiles);
        $diagnostics = [
            new RepositorySetupDiagnostic(
                RepositorySetupDiagnosticKind::MAKE,
                RepositorySetupDiagnosticLevel::OK,
                'Make: ' . $makefile . ' found',
                facts: ['makefile' => (string) $makefile],
            ),
        ];

        $packageIncludeFiles = $this->packageMakeIncludeFiles($makefileContents);
        $foundTargets = [];
        foreach (self::MIGRATION_TARGETS as $target) {
            foreach ($makefileContents as $content) {
                if (preg_match('/^' . preg_quote($target, '/') . '\\s*:/m', $content) === 1) {
                    $foundTargets[] = $target;

                    break;
                }
            }
        }

        if ($packageIncludeFiles !== []) {
            $diagnostics[] = new RepositorySetupDiagnostic(
                RepositorySetupDiagnosticKind::MAKE,
                RepositorySetupDiagnosticLevel::OK,
                'Make agent assets: package include found in ' . implode(', ', $packageIncludeFiles)
                . '; canonical targets are provided by agent-loop',
                facts: [
                    'makefiles' => implode(',', $packageIncludeFiles),
                    'source' => PackageResources::MAKE_INCLUDE,
                ],
            );
        } elseif ($foundTargets === []) {
            $diagnostics[] = new RepositorySetupDiagnostic(
                RepositorySetupDiagnosticKind::MAKE,
                RepositorySetupDiagnosticLevel::WARN,
                'Make agent assets: no migration-compatible agent asset targets found',
                facts: ['targets' => ''],
            );
        } else {
            $diagnostics[] = new RepositorySetupDiagnostic(
                RepositorySetupDiagnosticKind::MAKE,
                RepositorySetupDiagnosticLevel::OK,
                'Make agent assets: found ' . implode(', ', $foundTargets),
                facts: ['targets' => implode(',', $foundTargets)],
            );
        }

        if ($packageIncludeFiles !== []) {
            $packageTargets = $this->packageMakeTargetNames();
            $redeclaredTargets = [];
            foreach ($makefileContents as $content) {
                foreach ($this->makeTargetNames($content) as $target) {
                    if (isset($packageTargets[$target])) {
                        $redeclaredTargets[$target] = $target;
                    }
                }
            }

            if ($redeclaredTargets !== []) {
                ksort($redeclaredTargets, SORT_STRING);
                $targets = array_values($redeclaredTargets);
                $diagnostics[] = new RepositorySetupDiagnostic(
                    RepositorySetupDiagnosticKind::INTEGRATION_CONFLICT,
                    RepositorySetupDiagnosticLevel::WARN,
                    'Make integration conflict: package include is active, but the host also defines package-owned target(s): '
                    . implode(', ', $targets)
                    . '. Remove duplicate recipes; use AGENT_LOOP_RUN or supported variables for host runtime customization.',
                    facts: [
                        'makefiles' => implode(',', $packageIncludeFiles),
                        'targets' => implode(',', $targets),
                    ],
                );
            }
        }

        return $diagnostics;
    }

    /**
     * @param array<string, string> $foundMakefiles
     *
     * @return array<string, string>
     */
    private function readMakefileContents(array $foundMakefiles): array
    {
        $contents = [];
        foreach ($foundMakefiles as $name => $path) {
            $content = file_get_contents($path);
            if (is_string($content)) {
                $contents[$name] = $content;
            }
        }

        return $contents;
    }

    /**
     * @param array<string, string> $makefileContents
     *
     * @return list<string>
     */
    private function packageMakeIncludeFiles(array $makefileContents): array
    {
        $files = [];
        $pattern = '~^[ \\t]*-?include[ \\t]+[^\\r\\n#]*'
            . preg_quote(PackageResources::MAKE_INCLUDE, '~')
            . '(?:[ \\t]*(?:#.*)?)?$~m';

        foreach ($makefileContents as $name => $content) {
            if (preg_match($pattern, $content) === 1) {
                $files[] = $name;
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }

    /** @return array<string, true> */
    private function packageMakeTargetNames(): array
    {
        $content = file_get_contents(PackageResources::makeInclude());
        if (!is_string($content)) {
            return [];
        }

        $targets = [];
        foreach ($this->makeTargetNames($content) as $target) {
            $targets[$target] = true;
        }

        return $targets;
    }

    /** @return list<string> */
    private function makeTargetNames(string $content): array
    {
        preg_match_all('~^([A-Za-z0-9_.-]+)[ \\t]*:(?!=)~m', $content, $matches);

        /** @var list<string> $targets */
        $targets = array_values(array_unique($matches[1] ?? []));
        sort($targets, SORT_STRING);

        return $targets;
    }

    /** @return list<RepositorySetupDiagnostic> */
    private function sourceRoots(AgentAssetSourcePaths $paths): array
    {
        $roots = [
            'skills-root' => $paths->skillsRoot(),
            'subagents-root' => $paths->subagentsRoot(),
            'hooks-root' => $paths->hooksRoot(),
            'tools-root' => $paths->toolsRoot(),
        ];

        $diagnostics = [];
        foreach ($roots as $label => $root) {
            $diagnostics[] = new RepositorySetupDiagnostic(
                RepositorySetupDiagnosticKind::SOURCE_ASSETS,
                RepositorySetupDiagnosticLevel::INFO,
                $label . ': ' . $root,
                facts: ['root' => $label, 'path' => $root],
            );
        }

        return $diagnostics;
    }

    /** @return list<RepositorySetupDiagnostic> */
    private function hosts(): array
    {
        $diagnostics = [];
        $runtimeProbe = $this->runtimeProbe ?? new HostRuntimeProbe();
        foreach (InitAgent::canonicalNames() as $agent) {
            $runtime = $runtimeProbe->probe($agent);
            $runtimeMessage = 'Host runtime [' . $agent . ']: ' . $runtime['status'];
            $facts = ['status' => $runtime['status']];
            if ($runtime['command'] !== null) {
                $runtimeMessage .= '; command=' . $runtime['command'];
                $facts['command'] = $runtime['command'];
            }
            if ($runtime['path'] !== null) {
                $runtimeMessage .= '; path=' . $runtime['path'];
                $facts['path'] = $runtime['path'];
            }
            if ($runtime['status'] === 'unprobed') {
                $runtimeMessage .= '; evidence=no stable CLI probe configured';
                $facts['evidence'] = 'no stable CLI probe configured';
            }
            $diagnostics[] = new RepositorySetupDiagnostic(
                RepositorySetupDiagnosticKind::HOST_RUNTIME,
                RepositorySetupDiagnosticLevel::INFO,
                $runtimeMessage,
                $agent,
                $facts,
            );

            $rows = HostCapabilityMatrix::forAgent($agent);
            $capabilities = [];
            $capabilityFacts = [];
            foreach ($rows as $row) {
                $capabilities[] = $row['capability']->value . '=' . $row['status']->value;
                $capabilityFacts[$row['capability']->value] = $row['status']->value;
            }
            $diagnostics[] = new RepositorySetupDiagnostic(
                RepositorySetupDiagnosticKind::HOST_CAPABILITY,
                RepositorySetupDiagnosticLevel::INFO,
                'Host capabilities [' . $agent . ']: ' . implode(', ', $capabilities),
                $agent,
                $capabilityFacts,
            );

            foreach ($rows as $row) {
                $diagnostics[] = new RepositorySetupDiagnostic(
                    RepositorySetupDiagnosticKind::HOST_CAPABILITY,
                    RepositorySetupDiagnosticLevel::INFO,
                    'Host capability evidence [' . $agent . '/' . $row['capability']->value . ']:'
                    . ' mechanism=' . $row['mechanism']
                    . '; evidence=' . $row['evidence'],
                    $agent,
                    [
                        'capability' => $row['capability']->value,
                        'status' => $row['status']->value,
                        'mechanism' => $row['mechanism'],
                        'evidence' => $row['evidence'],
                    ],
                );
            }
        }

        return $diagnostics;
    }

    private function sourceSkills(AgentAssetSourcePaths $paths): RepositorySetupDiagnostic
    {
        $count = count($this->findSkillFiles($paths->absoluteSkillsRoot()));
        if ($count === 0) {
            return new RepositorySetupDiagnostic(
                RepositorySetupDiagnosticKind::SOURCE_ASSETS,
                RepositorySetupDiagnosticLevel::WARN,
                'Skills: no repo-managed skills found under ' . $paths->skillsRoot() . '/*/SKILL.md',
                facts: ['kind' => 'skills', 'count' => '0', 'path' => $paths->skillsRoot()],
            );
        }

        return new RepositorySetupDiagnostic(
            RepositorySetupDiagnosticKind::SOURCE_ASSETS,
            RepositorySetupDiagnosticLevel::OK,
            'Skills: ' . $count . ' repo-managed skill file(s) found under ' . $paths->skillsRoot(),
            facts: ['kind' => 'skills', 'count' => (string) $count, 'path' => $paths->skillsRoot()],
        );
    }

    private function sourceSubagents(AgentAssetSourcePaths $paths): RepositorySetupDiagnostic
    {
        $files = $this->markdownFilesIn($paths->absoluteSubagentsRoot());
        if ($files === []) {
            return new RepositorySetupDiagnostic(
                RepositorySetupDiagnosticKind::SOURCE_ASSETS,
                RepositorySetupDiagnosticLevel::INFO,
                'Subagents: no source files found',
                facts: ['kind' => 'subagents', 'count' => '0'],
            );
        }

        return new RepositorySetupDiagnostic(
            RepositorySetupDiagnosticKind::SOURCE_ASSETS,
            RepositorySetupDiagnosticLevel::INFO,
            'Subagents: detected ' . count($files) . ' candidate file(s)',
            facts: ['kind' => 'subagents', 'count' => (string) count($files)],
        );
    }

    private function optionalHooks(AgentAssetSourcePaths $paths): RepositorySetupDiagnostic
    {
        $hooksRoot = $paths->absoluteHooksRoot();
        $hooksJson = is_file($hooksRoot . '/hooks.json');
        $hookFiles = [];
        if (is_dir($hooksRoot . '/hooks')) {
            foreach (scandir($hooksRoot . '/hooks') ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (is_file($hooksRoot . '/hooks/' . $entry) && str_ends_with($entry, '.php')) {
                    $hookFiles[] = $entry;
                }
            }
        }

        if (!$hooksJson && $hookFiles === []) {
            return new RepositorySetupDiagnostic(
                RepositorySetupDiagnosticKind::OPTIONAL_HOOKS,
                RepositorySetupDiagnosticLevel::INFO,
                'Codex hooks: no source files found',
                facts: ['manifest' => 'missing', 'count' => '0', 'opt_in' => 'explicit'],
            );
        }

        return new RepositorySetupDiagnostic(
            RepositorySetupDiagnosticKind::OPTIONAL_HOOKS,
            RepositorySetupDiagnosticLevel::INFO,
            'Codex hooks: detected ' . ($hooksJson ? 'hooks.json and ' : 'no hooks.json and ') . count($hookFiles) . ' hook file(s)',
            facts: [
                'manifest' => $hooksJson ? 'present' : 'missing',
                'count' => (string) count($hookFiles),
                'opt_in' => 'explicit',
            ],
        );
    }

    private function sourceTools(AgentAssetSourcePaths $paths): RepositorySetupDiagnostic
    {
        $present = is_dir($paths->absoluteToolsRoot());

        return new RepositorySetupDiagnostic(
            RepositorySetupDiagnosticKind::SOURCE_ASSETS,
            RepositorySetupDiagnosticLevel::INFO,
            $present ? 'Tools: tools directory found' : 'Tools: tools directory not found',
            facts: ['kind' => 'tools', 'state' => $present ? 'present' : 'missing'],
        );
    }

    /**
     * Managed-asset drift, per (host, kind) target.
     *
     * `init doctor` never reported this, so a repository could carry silently
     * modified or stale projected assets and still read as healthy. The buckets
     * are the owner's own classification; nothing here decides what a host
     * should do about them.
     *
     * @return list<RepositorySetupDiagnostic>
     */
    private function managedAssetDrift(AgentAssetSourcePaths $paths): array
    {
        $catalog = new ManagedAssetTargetCatalog($this->rootPath);
        $projector = new ManagedAssetDriftProjector();

        $diagnostics = [];
        foreach ($catalog->targets($paths) as $target) {
            $projection = $projector->projectTarget($target);
            $diagnostics[] = $this->driftDiagnostic($projection);
        }

        return $diagnostics;
    }

    private function driftDiagnostic(ManagedAssetDriftProjection $projection): RepositorySetupDiagnostic
    {
        $target = $projection->target;
        $facts = [
            'host' => $target->host,
            'kind' => $target->kind->value,
            'target_root' => $target->targetRoot,
            'manifest_state' => $projection->manifestState,
        ];

        if ($projection->manifestState === ManagedAssetDriftProjection::MANIFEST_MISSING) {
            return new RepositorySetupDiagnostic(
                RepositorySetupDiagnosticKind::MANAGED_ASSET_DRIFT,
                RepositorySetupDiagnosticLevel::INFO,
                'Managed assets [' . $target->label . ']: no manifest at ' . $target->manifestPath(),
                $target->host,
                $facts,
            );
        }

        if ($projection->manifestState === ManagedAssetDriftProjection::MANIFEST_UNREADABLE) {
            $failure = $projection->failure ?? 'manifest could not be read';
            $facts['failure'] = $failure;

            return new RepositorySetupDiagnostic(
                RepositorySetupDiagnosticKind::MANAGED_ASSET_DRIFT,
                RepositorySetupDiagnosticLevel::WARN,
                'Managed assets [' . $target->label . ']: ' . $failure,
                $target->host,
                $facts,
            );
        }

        $summary = [];
        foreach ($projection->buckets() as $bucket => $entries) {
            $facts[$bucket] = implode(',', $entries);
            if ($entries !== []) {
                $summary[] = $bucket . '=' . count($entries);
            }
        }

        return new RepositorySetupDiagnostic(
            RepositorySetupDiagnosticKind::MANAGED_ASSET_DRIFT,
            $projection->hasDrift() ? RepositorySetupDiagnosticLevel::WARN : RepositorySetupDiagnosticLevel::OK,
            'Managed assets [' . $target->label . ']: ' . ($summary === [] ? 'no managed entries' : implode(', ', $summary)),
            $target->host,
            $facts,
        );
    }

    private function workflowBoundary(): RepositorySetupDiagnostic
    {
        return new RepositorySetupDiagnostic(
            RepositorySetupDiagnosticKind::WORKFLOW_BOUNDARY,
            RepositorySetupDiagnosticLevel::OK,
            'Workflow: init diagnostics do not affect workflow close',
            facts: ['affects_close' => 'no'],
        );
    }

    /** @return array<string, string> */
    private function findSkillFiles(string $skillsRoot): array
    {
        if (!is_dir($skillsRoot)) {
            return [];
        }

        $files = [];
        foreach (scandir($skillsRoot) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $skillFile = $skillsRoot . '/' . $entry . '/SKILL.md';
            if (is_file($skillFile)) {
                $files[$entry] = $skillFile;
            }
        }
        ksort($files);

        return $files;
    }

    /** @return list<string> */
    private function markdownFilesIn(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_file($root . '/' . $entry) && str_ends_with($entry, '.md')) {
                $files[] = $entry;
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }
}
