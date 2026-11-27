<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Init\InitConfigLoader;

final class InitConfigLoaderTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    public function testPromptingDefaultsAndConfiguredOptions(): void
    {
        $root = $this->tempDir();
        $loader = new InitConfigLoader($root);
        self::assertSame([
            'language' => 'en',
            'blindspots' => ['tone' => 'measured'],
        ], $loader->load('.agent-loop/init.json')['prompting']);

        mkdir($root . '/.agent-loop', 0o775, true);
        file_put_contents($root . '/.agent-loop/init.json', json_encode([
            'prompting' => [
                'language' => 'DE-de',
                'blindspots' => ['tone' => 'unflinching'],
            ],
        ], JSON_THROW_ON_ERROR));

        $config = $loader->load('.agent-loop/init.json');
        self::assertSame('de-de', $config['prompting']['language']);
        self::assertSame(['tone' => 'unflinching'], $config['prompting']['blindspots']);
        self::assertSame([], $config['warnings']);
    }

    public function testMapPathsAreReadAsValidatedProjectRelativeDirectories(): void
    {
        $root = $this->tempDir();
        mkdir($root . '/.agent-loop', 0o775, true);
        mkdir($root . '/src', 0o775, true);
        mkdir($root . '/tests', 0o775, true);
        mkdir($root . '/.source', 0o775, true);
        $path = '.agent-loop/init.json';
        file_put_contents($root . '/' . $path, json_encode(['map' => ['paths' => ['src', 'tests', '.source']]], JSON_THROW_ON_ERROR));

        $config = (new InitConfigLoader($root))->load($path);

        self::assertSame([
            'languages' => null,
            'languages_error' => null,
            'paths' => ['src', 'tests', '.source'],
            'paths_error' => null,
        ], $config['map']);
        self::assertSame([], $config['warnings']);

        if (DIRECTORY_SEPARATOR !== '\\') {
            $outside = $root . '-outside';
            mkdir($outside, 0o775, true);
            symlink($outside, $root . '/outside-link');
            $this->tempDirs[] = $outside;
        }

        foreach ([[], 'src', [1], ['/tmp'], ['../outside'], ['missing'], ['outside-link']] as $invalid) {
            file_put_contents($root . '/' . $path, json_encode(['map' => ['paths' => $invalid]], JSON_THROW_ON_ERROR));
            $invalidConfig = (new InitConfigLoader($root))->load($path);
            self::assertNull($invalidConfig['map']['paths'], json_encode($invalid, JSON_THROW_ON_ERROR));
            self::assertNotNull($invalidConfig['map']['paths_error'], json_encode($invalid, JSON_THROW_ON_ERROR));
            self::assertCount(1, $invalidConfig['warnings'], json_encode($invalid, JSON_THROW_ON_ERROR));
        }
    }

    public function testAbsoluteConfigPathsWorkForWindowsAndUnixProjectRoots(): void
    {
        // Verify the path passed to is_file(), without depending on a Windows
        // filesystem being available on the Linux CI runners.
        $resolve = new \ReflectionMethod(InitConfigLoader::class, 'resolvePath');
        self::assertSame(
            DIRECTORY_SEPARATOR === '\\'
                ? 'C:/work/project/.agent-loop/init.json'
                : 'C:/work/project/C:/work/project/.agent-loop/init.json',
            $resolve->invoke(new InitConfigLoader('C:/work/project'), 'C:/work/project/.agent-loop/init.json'),
        );
        self::assertSame(
            '//server/share/project/.agent-loop/init.json',
            $resolve->invoke(new InitConfigLoader('//server/share/project'), '//server/share/project/.agent-loop/init.json'),
        );
        self::assertSame(
            '/srv/project/.agent-loop/init.json',
            $resolve->invoke(new InitConfigLoader('/srv/project'), '/srv/project/.agent-loop/init.json'),
        );
        self::assertSame(
            '/srv/project/.agent-loop/init.json',
            $resolve->invoke(new InitConfigLoader('/srv/project'), '.agent-loop/init.json'),
        );
    }

    public function testUnixLiteralBackslashInConfigFilenameIsNotRewritten(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Literal backslash filename is a Unix filesystem behavior.');
        }

        $root = $this->tempDir();
        $name = 'config\\local.json';
        file_put_contents($root . '/' . $name, '{"prompting":{"language":"de"}}');

        $config = (new InitConfigLoader($root))->load($name);
        self::assertSame('de', $config['prompting']['language']);
        self::assertSame([], $config['warnings']);
    }

    public function testUnixLeadingBackslashesAreLiteralRelativeConfigFilename(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('UNC-style leading backslashes are absolute on Windows.');
        }

        $root = $this->tempDir();
        // Two leading backslashes are a valid Unix filename, not a UNC path.
        $name = '\\\\server\\share.json';
        file_put_contents($root . '/' . $name, '{"prompting":{"language":"de"}}');

        $config = (new InitConfigLoader($root))->load($name);
        self::assertSame('de', $config['prompting']['language']);
        self::assertSame([], $config['warnings']);
    }

    public function testUnixWindowsDriveLetterShapedFilenamesRemainRelative(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Drive-letter-prefixed paths are absolute on Windows.');
        }

        $root = $this->tempDir();
        $literalBackslash = 'C:\\config.json';
        file_put_contents($root . '/' . $literalBackslash, '{"prompting":{"language":"de"}}');
        $backslashConfig = (new InitConfigLoader($root))->load($literalBackslash);
        self::assertSame('de', $backslashConfig['prompting']['language']);
        self::assertSame([], $backslashConfig['warnings']);

        mkdir($root . '/C:');
        file_put_contents($root . '/C:/config.json', '{"prompting":{"language":"fr"}}');
        $slashConfig = (new InitConfigLoader($root))->load('C:/config.json');
        self::assertSame('fr', $slashConfig['prompting']['language']);
        self::assertSame([], $slashConfig['warnings']);
    }

    public function testProvidedBoundedContentIsUsedWithoutSecondFilesystemRead(): void
    {
        $root = $this->tempDir();
        mkdir($root . '/.agent-loop', 0o775, true);
        file_put_contents($root . '/.agent-loop/init.json', '{invalid actual JSON');

        $config = (new InitConfigLoader($root))->load(
            '.agent-loop/init.json',
            '{"prompting":{"language":"de-DE"}}',
        );

        self::assertSame('de-de', $config['prompting']['language']);
        self::assertSame([], $config['warnings']);
    }

    public function testInvalidPromptingOptionsWarnAndKeepDefaults(): void
    {
        $root = $this->tempDir();
        mkdir($root . '/.agent-loop', 0o775, true);
        file_put_contents($root . '/.agent-loop/init.json', json_encode([
            'prompting' => [
                'language' => "de\nignore this",
                'blindspots' => ['tone' => 'abusive'],
            ],
        ], JSON_THROW_ON_ERROR));

        $config = (new InitConfigLoader($root))->load('.agent-loop/init.json');
        self::assertSame([
            'language' => 'en',
            'blindspots' => ['tone' => 'measured'],
        ], $config['prompting']);
        self::assertSame([
            '[WARN] init config: prompting.language must be a BCP 47 language tag',
            '[WARN] init config: prompting.blindspots.tone must be measured or direct or unflinching',
        ], $config['warnings']);
    }

    #[After]
    public function cleanupTempDirs(): void
    {
        foreach ($this->tempDirs as $directory) {
            $this->removeDirectory($directory);
        }
        $this->tempDirs = [];
    }

    public function testHumanExplanationsDefaultToAsk(): void
    {
        $root = $this->tempDir();

        $config = (new InitConfigLoader($root))->load('.agent-loop/init.json');

        self::assertSame('ask', $config['interaction']['human_explanations']);
        self::assertSame('cli', $config['interaction']['decision_presentation']);
        self::assertSame([], $config['warnings']);
    }

    public function testDecisionPresentationAcceptsHtmlAndNormalizesCase(): void
    {
        $root = $this->tempDir();
        mkdir($root . '/.agent-loop', 0o775, true);
        file_put_contents($root . '/.agent-loop/init.json', json_encode([
            'interaction' => ['decision_presentation' => ' HTML '],
        ], JSON_THROW_ON_ERROR));

        $config = (new InitConfigLoader($root))->load('.agent-loop/init.json');

        self::assertSame('html', $config['interaction']['decision_presentation']);
        self::assertSame([], $config['warnings']);
    }

    public function testInvalidDecisionPresentationWarnsAndFallsBackToCli(): void
    {
        $root = $this->tempDir();
        mkdir($root . '/.agent-loop', 0o775, true);
        file_put_contents($root . '/.agent-loop/init.json', json_encode([
            'interaction' => ['decision_presentation' => 'browser'],
        ], JSON_THROW_ON_ERROR));

        $config = (new InitConfigLoader($root))->load('.agent-loop/init.json');

        self::assertSame('cli', $config['interaction']['decision_presentation']);
        self::assertContains(
            '[WARN] init config: interaction.decision_presentation must be cli or html',
            $config['warnings'],
        );
    }

    public function testControlPlaneDefaultsToDisabledLoopback(): void
    {
        $root = $this->tempDir();

        $config = (new InitConfigLoader($root))->load('.agent-loop/init.json');

        self::assertSame([
            'enabled' => false,
            'host' => '127.0.0.1',
            'port' => 8088,
        ], $config['interaction']['control_plane']);
        self::assertSame([], $config['warnings']);
    }

    public function testControlPlaneAcceptsExplicitLocalEndpoint(): void
    {
        $root = $this->tempDir();
        mkdir($root . '/.agent-loop', 0o775, true);
        file_put_contents($root . '/.agent-loop/init.json', json_encode([
            'interaction' => [
                'control_plane' => [
                    'enabled' => true,
                    'host' => 'LOCALHOST',
                    'port' => 9123,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $config = (new InitConfigLoader($root))->load('.agent-loop/init.json');

        self::assertSame([
            'enabled' => true,
            'host' => 'localhost',
            'port' => 9123,
        ], $config['interaction']['control_plane']);
        self::assertSame([], $config['warnings']);
    }

    public function testInvalidControlPlaneConfigurationWarnsAndStaysDisabled(): void
    {
        foreach ([
            [
                'value' => false,
                'warning' => '[WARN] init config: interaction.control_plane must be an object',
            ],
            [
                'value' => ['enabled' => 'yes'],
                'warning' => '[WARN] init config: interaction.control_plane.enabled must be a boolean',
            ],
            [
                'value' => ['enabled' => true, 'host' => 'example.com'],
                'warning' => '[WARN] init config: interaction.control_plane.host must be a loopback host',
            ],
            [
                'value' => ['enabled' => true, 'port' => 70000],
                'warning' => '[WARN] init config: interaction.control_plane.port must be an integer from 1 to 65535',
            ],
        ] as $case) {
            $root = $this->tempDir();
            mkdir($root . '/.agent-loop', 0o775, true);
            file_put_contents($root . '/.agent-loop/init.json', json_encode([
                'interaction' => ['control_plane' => $case['value']],
            ], JSON_THROW_ON_ERROR));

            $config = (new InitConfigLoader($root))->load('.agent-loop/init.json');

            self::assertFalse($config['interaction']['control_plane']['enabled']);
            self::assertContains($case['warning'], $config['warnings']);
        }
    }

    public function testFutureWorkDefaultsToFocus(): void
    {
        $root = $this->tempDir();

        $config = (new InitConfigLoader($root))->load('.agent-loop/init.json');

        self::assertSame([
            'mode' => 'focus',
            'max_follow_up_slices' => 1,
        ], $config['workflow']['future_work']);
        self::assertSame([], $config['warnings']);
    }

    public function testFutureWorkAcceptsDiscoverAndInvestPolicies(): void
    {
        foreach ([['discover', 1], ['invest', 3]] as [$mode, $maximum]) {
            $root = $this->tempDir();
            mkdir($root . '/.agent-loop', 0o775, true);
            file_put_contents($root . '/.agent-loop/init.json', json_encode([
                'workflow' => [
                    'future_work' => [
                        'mode' => $mode,
                        'max_follow_up_slices' => $maximum,
                    ],
                ],
            ], JSON_THROW_ON_ERROR));

            $config = (new InitConfigLoader($root))->load('.agent-loop/init.json');

            self::assertSame($mode, $config['workflow']['future_work']['mode']);
            self::assertSame($maximum, $config['workflow']['future_work']['max_follow_up_slices']);
            self::assertSame([], $config['warnings']);
        }
    }

    public function testInvalidFutureWorkPolicyWarnsAndKeepsConservativeDefaults(): void
    {
        $root = $this->tempDir();
        mkdir($root . '/.agent-loop', 0o775, true);
        file_put_contents($root . '/.agent-loop/init.json', json_encode([
            'workflow' => [
                'future_work' => [
                    'mode' => 'wander',
                    'max_follow_up_slices' => 0,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $config = (new InitConfigLoader($root))->load('.agent-loop/init.json');

        self::assertSame([
            'mode' => 'focus',
            'max_follow_up_slices' => 1,
        ], $config['workflow']['future_work']);
        self::assertContains(
            '[WARN] init config: workflow.future_work.mode must be focus, discover, or invest',
            $config['warnings'],
        );
        self::assertContains(
            '[WARN] init config: workflow.future_work.max_follow_up_slices must be an integer from 1 to 10',
            $config['warnings'],
        );
    }

    public function testInvalidFutureWorkObjectsWarnAndKeepFocus(): void
    {
        foreach ([
            ['workflow' => false],
            ['workflow' => ['future_work' => false]],
        ] as $value) {
            $root = $this->tempDir();
            mkdir($root . '/.agent-loop', 0o775, true);
            file_put_contents($root . '/.agent-loop/init.json', json_encode($value, JSON_THROW_ON_ERROR));

            $config = (new InitConfigLoader($root))->load('.agent-loop/init.json');

            self::assertSame('focus', $config['workflow']['future_work']['mode']);
            self::assertNotSame([], $config['warnings']);
        }
    }

    public function testEmptyInteractionObjectKeepsDefaultWithoutWarning(): void
    {
        $root = $this->tempDir();
        mkdir($root . '/.agent-loop', 0o775, true);
        file_put_contents($root . '/.agent-loop/init.json', json_encode([
            'interaction' => (object) [],
        ], JSON_THROW_ON_ERROR));

        $config = (new InitConfigLoader($root))->load('.agent-loop/init.json');

        self::assertSame('ask', $config['interaction']['human_explanations']);
        self::assertSame('cli', $config['interaction']['decision_presentation']);
        self::assertFalse($config['interaction']['control_plane']['enabled']);
        self::assertSame([], $config['warnings']);
    }

    public function testHumanExplanationPolicyAcceptsAlwaysAndNever(): void
    {
        foreach (['always', 'never'] as $value) {
            $root = $this->tempDir();
            mkdir($root . '/.agent-loop', 0o775, true);
            file_put_contents($root . '/.agent-loop/init.json', json_encode([
                'interaction' => ['human_explanations' => $value],
            ], JSON_THROW_ON_ERROR));

            $config = (new InitConfigLoader($root))->load('.agent-loop/init.json');

            self::assertSame($value, $config['interaction']['human_explanations']);
            self::assertSame([], $config['warnings']);
        }
    }

    public function testInvalidHumanExplanationPolicyWarnsAndFallsBackToAsk(): void
    {
        $root = $this->tempDir();
        mkdir($root . '/.agent-loop', 0o775, true);
        file_put_contents($root . '/.agent-loop/init.json', json_encode([
            'interaction' => ['human_explanations' => 'surprise-me'],
        ], JSON_THROW_ON_ERROR));

        $config = (new InitConfigLoader($root))->load('.agent-loop/init.json');

        self::assertSame('ask', $config['interaction']['human_explanations']);
        self::assertContains(
            '[WARN] init config: interaction.human_explanations must be ask, always, or never',
            $config['warnings'],
        );
    }

    public function testInvalidInteractionObjectWarnsAndFallsBackToAsk(): void
    {
        foreach ([false, null, []] as $value) {
            $root = $this->tempDir();
            mkdir($root . '/.agent-loop', 0o775, true);
            file_put_contents($root . '/.agent-loop/init.json', json_encode([
                'interaction' => $value,
            ], JSON_THROW_ON_ERROR));

            $config = (new InitConfigLoader($root))->load('.agent-loop/init.json');

            self::assertSame('ask', $config['interaction']['human_explanations']);
            self::assertFalse($config['interaction']['control_plane']['enabled']);
            self::assertContains('[WARN] init config: interaction must be an object', $config['warnings']);
        }
    }

    public function testLoadsClaudeHooksRootPath(): void
    {
        $root = $this->tempDir();
        mkdir($root . '/.agent-loop', 0o775, true);
        file_put_contents($root . '/.agent-loop/init.json', json_encode([
            'paths' => [
                'claude_hooks_root' => 'infra/doc/agents/claude-hooks',
            ],
        ], JSON_THROW_ON_ERROR));

        $config = (new InitConfigLoader($root))->load('.agent-loop/init.json');

        self::assertSame('infra/doc/agents/claude-hooks', $config['paths']['claude_hooks_root']);
        self::assertSame([], $config['warnings']);
    }

    public function testExtraSkillRootsDefaultToEmptyAndAcceptMultipleLocalRoots(): void
    {
        $root = $this->tempDir();
        $loader = new InitConfigLoader($root);

        self::assertSame([], $loader->load('.agent-loop/init.json')['extra_skills_roots']);

        mkdir($root . '/.agent-loop', 0o775, true);
        file_put_contents($root . '/.agent-loop/init.json', json_encode([
            'extra_skills_roots' => [
                'vendor/team-skills',
                '/opt/local-agent-skills',
                'vendor/team-skills',
            ],
        ], JSON_THROW_ON_ERROR));

        $config = $loader->load('.agent-loop/init.json');

        self::assertSame(
            ['vendor/team-skills', '/opt/local-agent-skills'],
            $config['extra_skills_roots'],
        );
        self::assertSame([], $config['warnings']);
    }

    public function testInvalidExtraSkillRootsWarnAndKeepOnlyValidLocalPaths(): void
    {
        $root = $this->tempDir();
        mkdir($root . '/.agent-loop', 0o775, true);
        file_put_contents($root . '/.agent-loop/init.json', json_encode([
            'extra_skills_roots' => ['valid-skills', '', 42, "bad\npath"],
        ], JSON_THROW_ON_ERROR));

        $config = (new InitConfigLoader($root))->load('.agent-loop/init.json');

        self::assertSame(['valid-skills'], $config['extra_skills_roots']);
        self::assertContains(
            '[WARN] init config: extra_skills_roots must contain only non-empty strings',
            $config['warnings'],
        );
        self::assertContains(
            '[WARN] init config: extra_skills_roots must not contain control characters',
            $config['warnings'],
        );
    }

    public function testRuntimeContainerDefaultsToNone(): void
    {
        $config = (new InitConfigLoader($this->tempDir()))->load('.agent-loop/init.json');

        self::assertSame(['container' => []], $config['runtime']);
    }

    public function testRuntimeContainerIsDeclaredOnce(): void
    {
        $root = $this->tempDir();
        mkdir($root . '/.agent-loop', 0o775, true);
        file_put_contents($root . '/.agent-loop/init.json', json_encode([
            'runtime' => ['container' => [
                'service' => 'php',
                'image' => ' it-portal-php ',
                'workdir' => '/var/www/html',
                'user' => 'www-data',
            ]],
        ], JSON_THROW_ON_ERROR));

        $config = (new InitConfigLoader($root))->load('.agent-loop/init.json');

        self::assertSame(
            ['service' => 'php', 'image' => 'it-portal-php', 'workdir' => '/var/www/html', 'user' => 'www-data'],
            $config['runtime']['container'],
        );
        self::assertSame([], $config['warnings']);
    }

    public function testInvalidRuntimeContainerValuesWarnAndAreDropped(): void
    {
        $root = $this->tempDir();
        mkdir($root . '/.agent-loop', 0o775, true);
        file_put_contents($root . '/.agent-loop/init.json', json_encode([
            'runtime' => ['container' => [
                'service' => 'php',
                'workdir' => 'var/www/html',
                'user' => '  ',
                'network' => 'host',
            ]],
        ], JSON_THROW_ON_ERROR));

        $config = (new InitConfigLoader($root))->load('.agent-loop/init.json');

        self::assertSame(['service' => 'php'], $config['runtime']['container']);
        self::assertSame([
            '[WARN] init config: runtime.container.workdir must be an absolute path inside the container',
            '[WARN] init config: runtime.container.user must be a non-empty string',
            '[WARN] init config: runtime.container.network is not supported (service, image, workdir, user)',
        ], $config['warnings']);
    }

    public function testRuntimeContainerValuesWithControlCharactersAreIgnored(): void
    {
        $root = $this->tempDir();
        mkdir($root . '/.agent-loop', 0o775, true);
        file_put_contents($root . '/.agent-loop/init.json', json_encode([
            'runtime' => ['container' => ['service' => "php\nexit 0", 'workdir' => "/var/www/it's"]],
        ], JSON_THROW_ON_ERROR));

        $config = (new InitConfigLoader($root))->load('.agent-loop/init.json');

        // A quote is plain data for the hooks; a newline is not a service name.
        self::assertSame(['workdir' => "/var/www/it's"], $config['runtime']['container']);
        self::assertSame(['[WARN] init config: runtime.container.service must not contain control characters'], $config['warnings']);
    }

    public function testRuntimeThatIsNotAnObjectWarns(): void
    {
        $root = $this->tempDir();
        mkdir($root . '/.agent-loop', 0o775, true);
        file_put_contents($root . '/.agent-loop/init.json', '{"runtime": "docker"}');

        $config = (new InitConfigLoader($root))->load('.agent-loop/init.json');

        self::assertSame([], $config['runtime']['container']);
        self::assertSame(['[WARN] init config: runtime must be an object'], $config['warnings']);
    }

    private function tempDir(): string
    {
        $directory = sys_get_temp_dir() . '/agent-loop-init-config-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0o775, true));
        $this->tempDirs[] = $directory;

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            is_link($path) ? unlink($path) : (is_dir($path) ? $this->removeDirectory($path) : unlink($path));
        }
        rmdir($directory);
    }
}
