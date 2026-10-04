<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Edit\EditCommand;

/**
 * A project that lives in a subdirectory of a larger Git work tree. Git reports status paths relative to the
 * work-tree top level, so a snapshotter that joins them to the project root records `app/src/...` paths that do not
 * exist, and the `php_lint_changed_files` gate then silently has "nothing to lint".
 */
#[Group('slow')]
final class EditSubdirectoryProjectEvidenceTest extends TestCase
{
    private string $top;
    private string $root;
    private string $bundle;

    protected function setUp(): void
    {
        $this->top = (string) realpath(sys_get_temp_dir()) . '/agent-loop-subdir-evidence-' . bin2hex(random_bytes(6));
        $this->root = $this->top . '/app';
        $this->bundle = $this->root . '/.agent-loop/edit/SUBDIR';
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->top . '/outside.txt', "outside\n");
        file_put_contents($this->top . '/.gitignore', "app/.agent-loop/\n");
        file_put_contents($this->root . '/src/UserService.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Demo;

        final class UserService
        {
            public function save(bool $active): void
            {
                if (!$active) {
                    return;
                }
            }
        }
        PHP);
        $this->git(['init', '-q']);
        $this->git(['add', '-A']);
        $this->git(['-c', 'user.email=t@example.invalid', '-c', 'user.name=t', '-c', 'commit.gpgsign=false', 'commit', '-q', '-m', 'baseline']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->top));
    }

    public function testObservedChangedFilesAreRelativeToTheProjectRootNotTheGitTopLevel(): void
    {
        $this->runMechanicalEdit();

        $result = $this->json($this->bundle . '/agent-result.json');
        self::assertSame('git_status_diff', $result['changed_files_source']);
        self::assertSame(['src/UserService.php'], $result['changed_files']);
    }

    public function testLintGateStillSeesAFileBrokenAfterTheEditInASubdirectoryProject(): void
    {
        $this->runMechanicalEdit();
        file_put_contents($this->root . '/src/UserService.php', "<?php\nthis is { not php\n");

        ob_start();
        (new EditCommand($this->root))->run(['verify', '--bundle=' . $this->bundle]);
        ob_end_clean();

        $verification = $this->json($this->bundle . '/verification-result.json');
        $lint = null;
        foreach ($verification['details']['gates'] as $gate) {
            if (($gate['kind'] ?? null) === 'php_lint_changed_files') {
                $lint = $gate;
            }
        }
        self::assertNotNull($lint, 'the compiled verification plan must carry the lint gate');
        self::assertSame('failed', $lint['status'], 'a broken changed file must fail the lint gate, not be skipped as missing');
    }

    private function runMechanicalEdit(): void
    {
        file_put_contents($this->top . '/outside.txt', "outside changed during setup is not this edit\n");
        ob_start();
        $exit = (new EditCommand($this->root))->run([
            'Demo\\UserService::save',
            '--task=SUBDIR',
            '--map-paths=src',
            '--runner=mechanical',
            '--replace-old=if (!$active)',
            '--replace-new=if ($active === false)',
            '--',
            'Replace the explicit boolean check without changing other code.',
        ]);
        ob_end_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('if ($active === false)', (string) file_get_contents($this->root . '/src/UserService.php'));
    }

    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @param non-empty-list<string> $arguments */
    private function git(array $arguments): void
    {
        exec('cd ' . escapeshellarg($this->top) . ' && git ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));
    }
}
