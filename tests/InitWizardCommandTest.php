<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Dispatcher;
use voku\AgentLoop\Init\InitPlanCommand;
use voku\AgentLoop\Init\InitWizardCommand;
use voku\AgentLoop\Workflow\WorkflowPresentationLanguage;

/** @internal */
final class InitWizardCommandTest extends TestCase
{
    private const array ENVIRONMENT = ['AGENT_LOOP_HOST_SESSION_ID', 'CLAUDE_CODE_SESSION_ID', 'AGENT_LOOP_INTERACTION_DIR'];

    private string $root;

    private string $preferences;

    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-setup-wizard-' . bin2hex(random_bytes(6));
        $this->preferences = $this->root . '-preferences';
        mkdir($this->root, 0o775, true);
        mkdir($this->preferences, 0o775, true);
        foreach (self::ENVIRONMENT as $name) {
            $this->environment[$name] = getenv($name);
            putenv($name);
        }
        putenv('AGENT_LOOP_INTERACTION_DIR=' . $this->preferences);
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        $this->removeTree($this->root);
        $this->removeTree($this->preferences);
    }

    public function testTheSameEvidenceAndAnswersGiveByteIdenticalOutputWithStableQuestionOrder(): void
    {
        $this->writePhpProject();
        $answers = $this->answersFile(['prompting.language' => 'de', 'map.paths' => ['src']]);

        [$firstExit, $first] = $this->runWizard(['--answers=' . $answers]);
        [$secondExit, $second] = $this->runWizard(['--answers=' . $answers]);

        self::assertSame(0, $firstExit);
        self::assertSame(0, $secondExit);
        self::assertSame($first, $second);
        $payload = json_decode($first, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(InitWizardCommand::SCHEMA, $payload['schema']);
        self::assertSame(3, $payload['version']);
        $order = array_merge(array_column($payload['questions'], 'order', 'id'), array_column($payload['skipped'], 'order', 'id'));
        asort($order);
        self::assertSame(InitWizardCommand::QUESTION_IDS, array_keys($order), 'Asked and skipped questions keep their fixed positions.');
        self::assertDirectoryDoesNotExist($this->root . '/.agent-loop', 'The wizard writes nothing.');
    }

    public function testPhpOnlyProjectAsksComposerChecksAndSkipsPackageChecks(): void
    {
        $this->writePhpProject();

        $wizard = (new InitWizardCommand($this->root))->inspect();

        self::assertSame(['map.languages', 'map.paths', 'checks.composer_scripts', 'prompting.language', 'personal.language'], array_column($wizard['questions'], 'id'));
        self::assertSame([['id' => 'checks.package_scripts', 'order' => 4, 'reason' => 'no package.json']], $wizard['skipped']);
        $questions = array_column($wizard['questions'], null, 'id');
        self::assertSame(['php'], $questions['map.languages']['default']);
        self::assertSame(['src'], $questions['map.paths']['default']);
        self::assertSame(['test'], $questions['checks.composer_scripts']['options'], 'Only declared validation-like script names are offered.');
        self::assertSame([], $questions['checks.composer_scripts']['default'], 'Checks are opt-in.');
        self::assertSame('en', $questions['prompting.language']['current']);
        self::assertSame('inherit', $questions['personal.language']['current']);
        self::assertSame('personal', $questions['personal.language']['applies_to']);
        self::assertSame('not_authorized', $wizard['execution']);
    }

    public function testJavaScriptOnlyProjectAsksPackageChecksAndProposesTypescript(): void
    {
        $this->writeJavaScriptProject();

        $wizard = (new InitWizardCommand($this->root))->inspect();

        self::assertSame([['id' => 'checks.composer_scripts', 'order' => 3, 'reason' => 'no composer.json']], $wizard['skipped']);
        $questions = array_column($wizard['questions'], null, 'id');
        self::assertSame(['typescript'], $questions['map.languages']['default']);
        self::assertSame(['lib'], $questions['map.paths']['default']);
        self::assertSame(['lint', 'test'], $questions['checks.package_scripts']['options']);
    }

    public function testPolyglotProjectAsksBothCheckQuestionsAndProposesBothLanguages(): void
    {
        $this->writePhpProject();
        $this->writeJavaScriptProject();

        $wizard = (new InitWizardCommand($this->root))->inspect();

        self::assertSame([], $wizard['skipped']);
        $questions = array_column($wizard['questions'], null, 'id');
        self::assertSame(['php', 'typescript'], $questions['map.languages']['default']);
        self::assertSame(['src', 'lib'], $questions['map.paths']['default']);
        self::assertSame(['test'], $questions['checks.composer_scripts']['options']);
        self::assertSame(['lint', 'test'], $questions['checks.package_scripts']['options']);
    }

    public function testEmptyProjectKeepsCoreQuestionsAndReportsMissingEvidence(): void
    {
        $wizard = (new InitWizardCommand($this->root))->inspect();

        self::assertSame(['map.languages', 'map.paths', 'prompting.language', 'personal.language'], array_column($wizard['questions'], 'id'));
        self::assertSame(['no composer.json', 'no package.json'], array_column($wizard['skipped'], 'reason'));
        $questions = array_column($wizard['questions'], null, 'id');
        self::assertSame([], $questions['map.paths']['default']);
        self::assertSame('no_evidence', $questions['map.paths']['default_source']);
    }

    public function testPersistedMapPathsAreShownAndChangedOnlyAsAReviewedFragment(): void
    {
        $this->writePhpProject();
        mkdir($this->root . '/tests', 0o775, true);
        mkdir($this->root . '/.agent-loop', 0o775, true);
        $configPath = $this->root . '/.agent-loop/init.json';
        file_put_contents($configPath, '{"map":{"paths":["src"]}}');

        $wizard = new InitWizardCommand($this->root);
        $question = array_column($wizard->inspect()['questions'], null, 'id')['map.paths'];
        self::assertSame(['src'], $question['current']);
        self::assertSame('.agent-loop/init.json', $question['current_source']);

        $proposal = $wizard->inspect(['map.paths' => ['src', 'tests']]);

        self::assertSame([[
            'id' => 'map.paths',
            'target' => '.agent-loop/init.json',
            'pointer' => '/map/paths',
            'before' => ['src'],
            'before_source' => '.agent-loop/init.json',
            'after' => ['src', 'tests'],
            'apply' => ['status' => 'manual_review', 'fragment' => ['map' => ['paths' => ['src', 'tests']]]],
            'requires_confirmation' => true,
        ]], $proposal['changes']);
        self::assertSame('{"map":{"paths":["src"]}}', file_get_contents($configPath), 'The wizard proposes but never writes init.json.');
        self::assertSame([], $proposal['file_changes']);
    }

    public function testAnswersEqualToCurrentValuesProduceNoChanges(): void
    {
        $this->writePhpProject();

        $wizard = (new InitWizardCommand($this->root))->inspect([
            'prompting.language' => 'en',
            'personal.language' => 'inherit',
            'map.languages' => ['php'],
        ]);

        self::assertSame([], $wizard['changes']);
        self::assertSame([], $wizard['file_changes']);
    }

    public function testProjectLanguageChangeRoutesToTheEvidenceGuardedApplyCommand(): void
    {
        $this->writePhpProject();
        $withoutState = (new InitWizardCommand($this->root))->inspect(['prompting.language' => 'de-DE'])['changes'][0]['apply'];
        self::assertSame('prerequisite_required', $withoutState['status']);
        self::assertStringContainsString('setup scaffold', $withoutState['prerequisite']);
        self::assertArrayNotHasKey('command', $withoutState, 'A digest computed before scaffolding would be stale once the prerequisite is followed.');
        ob_start();
        $scaffold = (new Dispatcher($this->root))->run(['agent-loop', 'setup', 'scaffold', '--prefix=TST']);
        ob_end_clean();
        self::assertSame(0, $scaffold);

        $wizard = (new InitWizardCommand($this->root))->inspect(['prompting.language' => 'de-DE', 'checks.composer_scripts' => ['test']]);

        $digest = (new InitPlanCommand($this->root))->inspect('de-de')['evidence_sha256'];
        self::assertSame([[
            'id' => 'prompting.language',
            'target' => '.agent-loop/init.json',
            'pointer' => '/prompting/language',
            'before' => 'en',
            'before_source' => array_column($wizard['questions'], null, 'id')['prompting.language']['current_source'],
            'after' => 'de-de',
            'apply' => [
                'status' => 'supported',
                'preview' => 'vendor/bin/agent-loop setup apply --language=de-de --evidence-sha256=' . $digest . ' --accept=prompting.language --dry-run',
                'command' => 'vendor/bin/agent-loop setup apply --language=de-de --evidence-sha256=' . $digest . ' --accept=prompting.language --yes',
            ],
            'requires_confirmation' => true,
        ]], $wizard['changes']);
        self::assertSame('not_authorized', $wizard['validation_candidates'][0]['execution']);
        self::assertSame('test', $wizard['validation_candidates'][0]['script']);

        $dispatcher = new Dispatcher($this->root);
        ob_start();
        $exit = $dispatcher->run(['agent-loop', 'setup', 'apply', '--language=de-de', '--evidence-sha256=' . $digest, '--accept=prompting.language', '--yes']);
        ob_end_clean();
        self::assertSame(0, $exit, 'The reported command is accepted by the existing writer.');
        self::assertSame([], (new InitWizardCommand($this->root))->inspect(['prompting.language' => 'de-de'])['changes'], 'A rerun after applying reports nothing left to change.');
    }

    public function testTwoUsersKeepDifferentPersonalLanguagesAcrossSessionsWithoutTouchingProjectConfig(): void
    {
        $this->writePhpProject();
        mkdir($this->root . '/.agent-loop', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/init.json', "{\n  \"prompting\": {\"language\": \"en\"}\n}\n");
        $configBefore = file_get_contents($this->root . '/.agent-loop/init.json');
        // Each user has their own state directory; the default language lives there.
        $anna = $this->preferences . '/anna';
        $ben = $this->preferences . '/ben';
        foreach (['anna' => $anna, 'ben' => $ben] as $user => $directory) {
            putenv('AGENT_LOOP_INTERACTION_DIR=' . $directory);
            self::assertSame(0, $this->dispatch(['workflow', 'interaction', 'session-' . $user . '-1', 'collaborative']));
        }

        putenv('AGENT_LOOP_INTERACTION_DIR=' . $anna);
        putenv('AGENT_LOOP_HOST_SESSION_ID=session-anna-1');
        $change = (new InitWizardCommand($this->root))->inspect(['personal.language' => 'de'])['changes'][0];
        self::assertSame('user_default_language', $change['target']);
        self::assertSame(['inherit', 'de'], [$change['before'], $change['after']]);
        self::assertSame('vendor/bin/agent-loop workflow interaction --default-language=de', $change['apply']['command']);
        self::assertSame(0, $this->dispatch(['workflow', 'interaction', '--default-language=de']), 'The reported command is accepted by the preference owner.');

        putenv('AGENT_LOOP_HOST_SESSION_ID=session-anna-2');
        $fresh = array_column((new InitWizardCommand($this->root))->inspect()['questions'], null, 'id')['personal.language'];
        self::assertSame(['de', 'user_default'], [$fresh['current'], $fresh['current_source']], 'A fresh session of the same user keeps the default language.');
        self::assertSame('de', WorkflowPresentationLanguage::resolve($this->root)->language);
        self::assertSame([], (new InitWizardCommand($this->root))->inspect(['personal.language' => 'de'])['changes'], 'A rerun after applying reports nothing left to change.');

        putenv('AGENT_LOOP_INTERACTION_DIR=' . $ben);
        putenv('AGENT_LOOP_HOST_SESSION_ID=session-ben-2');
        $other = array_column((new InitWizardCommand($this->root))->inspect()['questions'], null, 'id')['personal.language'];
        self::assertSame(['inherit', 'project_language'], [$other['current'], $other['current_source']], "Another user's preference is unaffected.");
        self::assertSame($configBefore, file_get_contents($this->root . '/.agent-loop/init.json'));

        putenv('AGENT_LOOP_INTERACTION_DIR=' . $this->preferences . '/carl');
        $withoutDefault = (new InitWizardCommand($this->root))->inspect(['personal.language' => 'fr'])['changes'][0]['apply'];
        self::assertSame('prerequisite_required', $withoutDefault['status'], 'The default language needs a shared default mode first.');
        self::assertStringContainsString("vendor/bin/agent-loop workflow interaction 'session-ben-2' <collaborative|autonomous> first", $withoutDefault['prerequisite']);
        self::assertSame('vendor/bin/agent-loop workflow interaction --default-language=fr', $withoutDefault['then']);
        self::assertArrayNotHasKey('command', $withoutDefault);
    }

    public function testUnknownSkippedOrInvalidAnswersFailExplicitly(): void
    {
        $this->writePhpProject();
        $cases = [
            ['nope' => 'x'],
            ['checks.package_scripts' => ['test']],
            ['map.languages' => ['cobol']],
            ['map.languages' => []],
            ['map.paths' => ['../outside']],
            ['map.paths' => ['missing']],
            ['prompting.language' => 'DE_de'],
            ['checks.composer_scripts' => ['ci']],
        ];
        foreach ($cases as $answers) {
            [$exit, $stdout, $stderr] = $this->runWizard(['--answers=' . $this->answersFile($answers)]);
            self::assertSame(1, $exit, json_encode($answers, JSON_THROW_ON_ERROR));
            self::assertSame('', $stdout);
            self::assertStringStartsWith('[FAIL] setup wizard: ', $stderr);
        }

        [$emptyExit, $emptyAnswers] = $this->runWizard(['--answers=' . $this->answersFile([])]);
        self::assertSame(0, $emptyExit);
        self::assertStringContainsString('"answers": {}', $emptyAnswers, 'answers is always a JSON object.');

        file_put_contents($this->root . '/bad.json', '{"answers": {}}');
        self::assertSame(1, $this->runWizard(['--answers=' . $this->root . '/bad.json'])[0], 'An unversioned answers file is refused.');
        self::assertSame(1, $this->runWizard(['--answers=' . $this->root . '/missing.json'])[0]);
        self::assertSame(1, $this->runWizard(['--format=text'])[0]);
    }

    public function testMapBuildCommandKeepsConfiguredLanguagesAndQuotesItsValues(): void
    {
        $this->writePhpProject();
        $this->writeJavaScriptProject();
        file_put_contents($this->root . '/tsconfig.json', '{}');
        mkdir($this->root . '/.agent-loop', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/init.json', '{"map": {"languages": ["php"]}}');

        $configured = (new InitWizardCommand($this->root))->inspect(['map.paths' => ['src']]);
        self::assertSame("vendor/bin/agent-loop map build --paths='src'", $configured['next_actions'][0]['command'], 'An unanswered language question must not override init.json.');
        self::assertSame(['map.paths'], array_column($configured['changes'], 'id'));
        self::assertSame(['map' => ['paths' => ['src']]], $configured['changes'][0]['apply']['fragment']);

        $answered = (new InitWizardCommand($this->root))->inspect(['map.paths' => ['src', 'lib'], 'map.languages' => ['typescript', 'php']]);
        self::assertSame("vendor/bin/agent-loop map build --paths='src,lib' --languages='php,typescript'", $answered['next_actions'][0]['command']);

        file_put_contents($this->root . '/.agent-loop/init.json', '{"map": {"languages": ["php", "typescript"]}}');
        $narrowed = (new InitWizardCommand($this->root))->inspect(['map.paths' => ['src'], 'map.languages' => ['php']]);
        self::assertSame("vendor/bin/agent-loop map build --paths='src' --languages='php'", $narrowed['next_actions'][0]['command'], 'Narrowing must be explicit; map build would otherwise add the configured languages.');
    }

    public function testPathsOnlyKeepsEffectiveFallbackUntilLanguagesAreAccepted(): void
    {
        $this->writePhpProject();
        $this->writeJavaScriptProject();

        $wizard = new InitWizardCommand($this->root);
        $paths = $wizard->inspect(['map.paths' => ['src', 'lib']]);
        self::assertSame(['map.paths'], array_column($paths['changes'], 'id'));
        self::assertSame("vendor/bin/agent-loop map build --paths='src,lib'", $paths['next_actions'][0]['command']);
        self::assertSame(['php', 'typescript'], array_column($paths['questions'], null, 'id')['map.languages']['default']);

        $accepted = $wizard->inspect(['map.paths' => ['src', 'lib'], 'map.languages' => ['action' => 'recommended']]);
        self::assertSame(['map.languages', 'map.paths'], array_column($accepted['changes'], 'id'));
        self::assertSame(['map' => ['languages' => ['php', 'typescript']]], $accepted['changes'][0]['apply']['fragment']);
        self::assertSame("vendor/bin/agent-loop map build --paths='src,lib' --languages='php,typescript'", $accepted['next_actions'][0]['command']);
        self::assertDirectoryDoesNotExist($this->root . '/.agent-loop');
    }

    public function testAnInvalidLanguageDeclarationIsRepairedAndNotTreatedAsPhp(): void
    {
        $this->writePhpProject();
        mkdir($this->root . '/.agent-loop', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/init.json', '{"map": {"languages": ["php", "rust"]}}');

        $answered = (new InitWizardCommand($this->root))->inspect(['map.languages' => ['php'], 'map.paths' => ['src']]);
        self::assertSame('invalid_config', $answered['changes'][0]['before_source']);
        self::assertSame(['map' => ['languages' => ['php']]], $answered['changes'][0]['apply']['fragment']);
        self::assertSame("vendor/bin/agent-loop map build --paths='src' --languages='php'", $answered['next_actions'][0]['command'], 'map build refuses an invalid declaration unless --languages is explicit.');

        $unanswered = (new InitWizardCommand($this->root))->inspect(['map.paths' => ['src']]);
        self::assertSame("vendor/bin/agent-loop map build --paths='src' --languages='php'", $unanswered['next_actions'][0]['command']);
        self::assertSame('map.languages', (new InitWizardCommand($this->root))->next(['map.paths' => ['src']])['step']['id']);

        $withLanguage = (new InitWizardCommand($this->root))->inspect(['map.languages' => ['php'], 'prompting.language' => 'de']);
        self::assertSame(['map.languages', 'prompting.language'], array_column($withLanguage['changes'], 'id'), 'The repair is still offered next to the blocked language change.');
        self::assertSame('prerequisite_required', $withLanguage['changes'][1]['apply']['status']);
        self::assertStringContainsString('init.json warnings', $withLanguage['changes'][1]['apply']['prerequisite']);
    }

    public function testAnUnreadableInitJsonIsReportedAsInvalidNotAsUnconfiguredOrAsItsOwnSource(): void
    {
        $this->writePhpProject();
        mkdir($this->root . '/.agent-loop', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/init.json', '{"map": {"languages": ["php"]');

        $questions = array_column((new InitWizardCommand($this->root))->inspect()['questions'], null, 'id');

        self::assertSame([null, 'invalid_config'], [$questions['map.languages']['current'], $questions['map.languages']['current_source']]);
        self::assertSame('invalid_config', $questions['prompting.language']['current_source'], 'The language is the built-in default, not a value read from init.json.');

        file_put_contents($this->root . '/.agent-loop/init.json', '{"prompting": {"language": "not a tag"}}');
        $badTag = array_column((new InitWizardCommand($this->root))->inspect()['questions'], null, 'id');
        self::assertSame('invalid_config', $badTag['prompting.language']['current_source']);
        self::assertSame('not_configured', $badTag['map.languages']['current_source']);
    }

    public function testAnUnansweredLanguageQuestionProposesNoLanguagesThatInitJsonWouldNotKeep(): void
    {
        $this->writePhpProject();
        $this->writeJavaScriptProject();
        file_put_contents($this->root . '/tsconfig.json', '{}');

        $result = (new InitWizardCommand($this->root))->inspect(['map.paths' => ['src', 'lib']]);

        self::assertSame(['php', 'typescript'], array_column($result['questions'], null, 'id')['map.languages']['default'], 'The proposal is still the recommendation.');
        self::assertSame("vendor/bin/agent-loop map build --paths='src,lib'", $result['next_actions'][0]['command'], 'Without a map.languages answer the build indexes what a later plain map build indexes.');
        self::assertSame(['map.paths'], array_column($result['changes'], 'id'));
        self::assertSame(['map' => ['paths' => ['src', 'lib']]], $result['changes'][0]['apply']['fragment']);
    }

    public function testRepairingAnInvalidLanguageDeclarationShowsTheStoredValueAsBefore(): void
    {
        $this->writePhpProject();
        mkdir($this->root . '/.agent-loop', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/init.json', '{"map": {"languages": ["php", "rust"]}}');

        $change = (new InitWizardCommand($this->root))->inspect(['map.languages' => ['php']])['changes'][0];

        self::assertSame(['php', 'rust'], $change['before']);
        self::assertSame('invalid_config', $change['before_source']);
    }

    public function testAVersionOneAnswersFileThatSetsAPersonalLanguageIsWarnedAbout(): void
    {
        $this->writePhpProject();
        $warning = 'this version 1 answers file sets personal.language';

        [$exit, $stdout] = $this->runWizard(['--answers=' . $this->answersFile(['personal.language' => 'de'], 1)]);
        self::assertSame(0, $exit);
        self::assertStringContainsString($warning, implode("\n", json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['warnings']));

        foreach ([[['personal.language' => 'de'], 2], [['prompting.language' => 'de'], 1]] as [$answers, $version]) {
            [, $stdout] = $this->runWizard(['--answers=' . $this->answersFile($answers, $version)]);
            self::assertStringNotContainsString($warning, $stdout);
        }
    }

    public function testMapPathsMustBePlainDirectoriesInsideTheProject(): void
    {
        $this->writePhpProject();
        mkdir($this->root . '/my src', 0o775, true);
        mkdir($this->root . '/.source', 0o775, true);
        symlink(sys_get_temp_dir(), $this->root . '/outside-link');
        foreach (['my src', 'outside-link', 'src;id', '-src'] as $path) {
            [$exit, , $stderr] = $this->runWizard(['--answers=' . $this->answersFile(['map.paths' => [$path]])]);
            self::assertSame(1, $exit, $path);
            self::assertStringContainsString('is not an existing project-relative directory', $stderr);
        }

        [$exit, $stdout, $stderr] = $this->runWizard(['--answers=' . $this->answersFile(['map.paths' => ['.source']])]);
        self::assertSame(0, $exit, $stderr);
        self::assertSame(['.source'], json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['answers']['map.paths']);
    }

    public function testSetupAndInitRouteToTheSameWizard(): void
    {
        $this->writePhpProject();
        $dispatcher = new Dispatcher($this->root);

        ob_start();
        $setupExit = $dispatcher->run(['agent-loop', 'setup', 'wizard']);
        $setup = (string) ob_get_clean();
        ob_start();
        $initExit = $dispatcher->run(['agent-loop', 'init', 'wizard']);
        $init = (string) ob_get_clean();

        self::assertSame([0, 0], [$setupExit, $initExit]);
        self::assertSame($setup, $init);
    }

    /** @param list<string> $arguments */
    private function dispatch(array $arguments): int
    {
        ob_start();
        try {
            return (new Dispatcher($this->root))->run(['agent-loop', ...$arguments]);
        } finally {
            ob_end_clean();
        }
    }

    private function writePhpProject(): void
    {
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo {}\n");
        file_put_contents($this->root . '/composer.json', json_encode([
            'autoload' => ['psr-4' => ['App\\' => 'src/']],
            'scripts' => ['test' => 'vendor/bin/phpunit', 'post-install-cmd' => 'echo ignored'],
        ], JSON_THROW_ON_ERROR));
    }

    private function writeJavaScriptProject(): void
    {
        mkdir($this->root . '/lib', 0o775, true);
        file_put_contents($this->root . '/lib/index.js', "export const x = 1;\n");
        file_put_contents($this->root . '/package.json', json_encode([
            'scripts' => ['test' => 'node --test', 'lint' => 'eslint .', 'start' => 'node lib/index.js'],
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $answers */
    private function answersFile(array $answers, int $version = InitWizardCommand::VERSION): string
    {
        $path = $this->root . '-answers-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, json_encode(['version' => $version, 'answers' => $answers], JSON_THROW_ON_ERROR));
        $this->answerFiles[] = $path;

        return $path;
    }

    /** @var list<string> */
    private array $answerFiles = [];

    /**
     * @param list<string> $tokens
     * @return array{0: int, 1: string, 2: string}
     */
    private function runWizard(array $tokens): array
    {
        $stderrPath = $this->root . '-stderr';
        $command = sprintf(
            '%s %s setup wizard %s 2>%s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/bin/agent-loop'),
            implode(' ', array_map('escapeshellarg', $tokens)),
            escapeshellarg($stderrPath),
        );
        $output = [];
        $exit = 0;
        exec('cd ' . escapeshellarg($this->root) . ' && ' . $command, $output, $exit);
        $stderr = is_file($stderrPath) ? (string) file_get_contents($stderrPath) : '';
        @unlink($stderrPath);

        return [$exit, $output === [] ? '' : implode("\n", $output) . "\n", $stderr];
    }

    private function removeTree(string $path): void
    {
        foreach ($this->answerFiles as $file) {
            @unlink($file);
        }
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }
}
