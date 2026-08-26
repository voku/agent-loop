from pathlib import Path

path = Path('src/Init/RepositoryInstructionSynchronizer.php')
text = path.read_text()
text = text.replace(
    '    /** @return list<ManagedAssetOperation> */\n    public function plan(string $agent): array',
    '    /**\n     * @param non-empty-string $agent\n     * @return list<ManagedAssetOperation>\n     */\n    public function plan(string $agent): array',
    1,
)
text = text.replace(
    '    /** @return list<ManagedAssetOperation> */\n    public function planUninstall(string $agent): array',
    '    /**\n     * @param non-empty-string $agent\n     * @return list<ManagedAssetOperation>\n     */\n    public function planUninstall(string $agent): array',
    1,
)
text = text.replace(
    '    private function blocked(string $agent, string $entry, string $targetPath, string $reason): ManagedAssetOperation',
    '    /** @param non-empty-string $agent */\n    private function blocked(string $agent, string $entry, string $targetPath, string $reason): ManagedAssetOperation',
    1,
)
path.write_text(text)

path = Path('src/Init/RepositoryManagedAssetInstaller.php')
text = path.read_text()
old = """            $applied = [
                ...$applied,
                ...match ($kind) {
                    ManagedAssetKind::SKILLS => $this->applySkills($operations, $skillSources, $plan->agent, $paths),
                    ManagedAssetKind::SUBAGENTS => $this->applySubagents($operations, $subagentSources, $plan->agent, $paths),
                    ManagedAssetKind::HOOKS => $this->applyHooks($operations, $paths, $plan->agent),
                    ManagedAssetKind::INSTRUCTIONS => [],
                },
            ];
"""
new = """            if ($kind === ManagedAssetKind::SKILLS) {
                $batch = $this->applySkills($operations, $skillSources, $plan->agent, $paths);
            } elseif ($kind === ManagedAssetKind::SUBAGENTS) {
                $batch = $this->applySubagents($operations, $subagentSources, $plan->agent, $paths);
            } else {
                $batch = $this->applyHooks($operations, $paths, $plan->agent);
            }
            array_push($applied, ...$batch);
"""
if old not in text:
    raise SystemExit('installer dispatch anchor not found')
text = text.replace(old, new, 1)
old = """        $applied = [
            ...$applied,
            ...(new RepositoryInstructionSynchronizer($this->rootPath))->apply($plan),
        ];
"""
new = """        array_push(
            $applied,
            ...(new RepositoryInstructionSynchronizer($this->rootPath))->apply($plan),
        );
"""
if old not in text:
    raise SystemExit('installer instruction list anchor not found')
text = text.replace(old, new, 1)
text = text.replace(
    '    /** @param list<ManagedAssetOperation> $operations @param array<string,string> $sources @return list<ManagedAssetOperation> */\n    private function applySkills',
    '    /**\n     * @param list<ManagedAssetOperation> $operations\n     * @param array<string, string> $sources\n     * @return list<ManagedAssetOperation>\n     */\n    private function applySkills',
    1,
)
text = text.replace(
    '    /** @param list<ManagedAssetOperation> $operations @param array<string,array{path:string,definition:SubagentDefinition}> $sources @return list<ManagedAssetOperation> */\n    private function applySubagents',
    '    /**\n     * @param list<ManagedAssetOperation> $operations\n     * @param array<string, array{path:string, definition:SubagentDefinition}> $sources\n     * @return list<ManagedAssetOperation>\n     */\n    private function applySubagents',
    1,
)
text = text.replace(
    '    /** @param list<ManagedAssetOperation> $operations @return list<ManagedAssetOperation> */\n    private function applyHooks',
    '    /**\n     * @param list<ManagedAssetOperation> $operations\n     * @return list<ManagedAssetOperation>\n     */\n    private function applyHooks',
    1,
)
path.write_text(text)

path = Path('src/Init/RepositorySetupService.php')
text = path.read_text()
text = text.replace(
    '        if ((new ManagedAssetPlanner())->planUninstall($host, false, $drift)->mutates()) {',
    '        if ($this->planUninstall($host, false, $resolved)->mutates()) {',
    1,
)
old = """        foreach (array_values(array_unique($sourceRoots)) as $root) {
            $this->appendSourceFiles($files, $root);
        }

        $files = array_values(array_unique($files));
        sort($files, SORT_STRING);

        return $files;
    }

    /** @param list<string> $files */
    private function appendSourceFiles(array &$files, string $root): void
    {
        if (!is_dir($root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $files[] = $item->getPathname();
            }
        }
    }
"""
new = """        foreach (array_values(array_unique($sourceRoots)) as $root) {
            array_push($files, ...$this->sourceFiles($root));
        }

        $files = array_values(array_unique($files));
        sort($files, SORT_STRING);

        return $files;
    }

    /** @return list<string> */
    private function sourceFiles(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $files[] = $item->getPathname();
            }
        }

        return $files;
    }
"""
if old not in text:
    raise SystemExit('source file helper anchor not found')
text = text.replace(old, new, 1)
path.write_text(text)

path = Path('tests/RepositorySetupMutationTest.php')
text = path.read_text()
text = text.replace(
    '    /** @param list<ManagedAssetOperation> $operations @return list<string> */\n    private function entries',
    '    /**\n     * @param list<ManagedAssetOperation> $operations\n     * @return list<string>\n     */\n    private function entries',
    1,
)
path.write_text(text)

Path('.github/workflows/fix-setup-static.yml').unlink()
Path('.github/scripts/fix_setup_static.py').unlink()
