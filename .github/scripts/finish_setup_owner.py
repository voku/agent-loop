from pathlib import Path

service = Path('src/Init/RepositorySetupService.php')
text = service.read_text()
old = """    public function syncGitIntegration(): void
    {
        $activation = new RepositoryActivation($this->rootPath);
        if (!$activation->declaresGitHookPolicy()) {
            throw new InvalidArgumentException('Repository does not declare a local Git hook policy.');
        }

        $exit = (new InitSyncGitHooksCommand($this->rootPath))->run($activation->syncGitHooksTokens());
        if ($exit !== 0) {
            throw new RuntimeException('Repository-owned local Git integration could not be synchronized.');
        }
    }
"""
new = """    public function syncGitIntegration(): void
    {
        (new RepositoryGitIntegrationSynchronizer($this->rootPath))->syncDeclared();
    }
"""
if old not in text:
    raise SystemExit('syncGitIntegration anchor not found')
service.write_text(text.replace(old, new, 1))

test = Path('tests/RepositorySetupMutationTest.php')
text = test.read_text()
anchor = """    private function paths(): AgentAssetSourcePaths
    {
"""
method = """    public function testTypedGitIntegrationUsesRepositoryDeclaredPolicyWithoutCliDispatch(): void
    {
        if (!mkdir($this->root . '/.agent-loop', 0o775, true) && !is_dir($this->root . '/.agent-loop')) {
            self::fail('Unable to create Git integration policy directory.');
        }
        file_put_contents($this->root . '/.agent-loop/githooks.json', "{}\\n");

        exec('git -C ' . escapeshellarg($this->root) . ' init -q', $output, $exitCode);
        self::assertSame(0, $exitCode, 'Git is required for the typed Git integration regression.');

        $service = new RepositorySetupService($this->root);
        $service->syncGitIntegration();

        self::assertFileExists($this->root . '/.githooks/pre-commit');
        self::assertFileExists($this->root . '/.githooks/commit-msg');
        self::assertFileExists($this->root . '/.githooks/.agent-loop-manifest.json');
        exec(
            'git -C ' . escapeshellarg($this->root) . ' config --get core.hooksPath',
            $configOutput,
            $configExit,
        );
        self::assertSame(0, $configExit);
        self::assertSame(['.githooks'], $configOutput);
    }

"""
if anchor not in text:
    raise SystemExit('mutation test insertion anchor not found')
test.write_text(text.replace(anchor, method + anchor, 1))

Path('.github/workflows/finish-setup-owner.yml').unlink()
Path('.github/scripts/finish_setup_owner.py').unlink()
