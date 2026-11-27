<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\AgentGuidance\InteractionPreferenceStore;
use voku\AgentLoop\Dispatcher;
use voku\AgentLoop\Init\InitPlanCommand;
use voku\AgentLoop\Init\InitWizardCommand;

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
        self::assertSame(1, $payload['version']);
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

    public function testTwoUsersKeepDifferentPersonalLanguagesWithoutTouchingProjectConfig(): void
    {
        $this->writePhpProject();
        mkdir($this->root . '/.agent-loop', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/init.json', "{\n  \"prompting\": {\"language\": \"en\"}\n}\n");
        $configBefore = file_get_contents($this->root . '/.agent-loop/init.json');
        $store = new InteractionPreferenceStore($this->preferences);
        $store->update('session-anna', 'collaborative', null);
        $store->setLanguage('session-anna', 'de');
        $store->update('session-ben', 'autonomous', null);

        putenv('AGENT_LOOP_HOST_SESSION_ID=session-anna');
        $anna = array_column((new InitWizardCommand($this->root))->inspect()['questions'], null, 'id')['personal.language'];
        putenv('AGENT_LOOP_HOST_SESSION_ID=session-ben');
        $ben = (new InitWizardCommand($this->root))->inspect(['personal.language' => 'fr']);

        self::assertSame(['de', 'host_session'], [$anna['current'], $anna['current_source']]);
        $benChange = $ben['changes'][0];
        self::assertSame('host_session_preference', $benChange['target']);
        self::assertSame(['inherit', 'fr'], [$benChange['before'], $benChange['after']]);
        self::assertSame("vendor/bin/agent-loop workflow interaction 'session-ben' --language=fr", $benChange['apply']['command']);
        ob_start();
        $followed = (new Dispatcher($this->root))->run(['agent-loop', 'workflow', 'interaction', 'session-ben', '--language=fr']);
        ob_end_clean();
        self::assertSame(0, $followed, 'The reported command is accepted by the existing preference owner.');
        self::assertSame([], (new InitWizardCommand($this->root))->inspect(['personal.language' => 'fr'])['changes']);
        self::assertSame($configBefore, file_get_contents($this->root . '/.agent-loop/init.json'));
        putenv('AGENT_LOOP_HOST_SESSION_ID=session-anna');
        self::assertSame('de', array_column((new InitWizardCommand($this->root))->inspect()['questions'], null, 'id')['personal.language']['current'], "Another user's language is unaffected.");

        putenv('AGENT_LOOP_HOST_SESSION_ID=session-carl');
        $withoutMode = (new InitWizardCommand($this->root))->inspect(['personal.language' => 'fr'])['changes'][0]['apply'];
        self::assertSame('prerequisite_required', $withoutMode['status'], 'The language command fails until a session mode exists.');
        self::assertSame("vendor/bin/agent-loop workflow interaction 'session-carl' --language=fr", $withoutMode['then']);
        self::assertArrayNotHasKey('command', $withoutMode);

        putenv('AGENT_LOOP_HOST_SESSION_ID');
        $unbound = (new InitWizardCommand($this->root))->inspect(['personal.language' => 'fr'])['changes'][0]['apply'];
        self::assertSame('host_session_required', $unbound['status']);
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
        self::assertSame([], $configured['changes']);

        $answered = (new InitWizardCommand($this->root))->inspect(['map.paths' => ['src', 'lib'], 'map.languages' => ['typescript', 'php']]);
        self::assertSame("vendor/bin/agent-loop map build --paths='src,lib' --languages='php,typescript'", $answered['next_actions'][0]['command']);

        file_put_contents($this->root . '/.agent-loop/init.json', '{"map": {"languages": ["php", "typescript"]}}');
        $narrowed = (new InitWizardCommand($this->root))->inspect(['map.paths' => ['src'], 'map.languages' => ['php']]);
        self::assertSame("vendor/bin/agent-loop map build --paths='src' --languages='php'", $narrowed['next_actions'][0]['command'], 'Narrowing must be explicit; map build would otherwise add the configured languages.');
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

        $withLanguage = (new InitWizardCommand($this->root))->inspect(['map.languages' => ['php'], 'prompting.language' => 'de']);
        self::assertSame(['map.languages', 'prompting.language'], array_column($withLanguage['changes'], 'id'), 'The repair is still offered next to the blocked language change.');
        self::assertSame('prerequisite_required', $withLanguage['changes'][1]['apply']['status']);
        self::assertStringContainsString('init.json warnings', $withLanguage['changes'][1]['apply']['prerequisite']);
    }

    public function testMapPathsMustBePlainDirectoriesInsideTheProject(): void
    {
        $this->writePhpProject();
        mkdir($this->root . '/my src', 0o775, true);
        symlink(sys_get_temp_dir(), $this->root . '/outside-link');
        foreach (['my src', 'outside-link', 'src;id', '-src'] as $path) {
            [$exit, , $stderr] = $this->runWizard(['--answers=' . $this->answersFile(['map.paths' => [$path]])]);
            self::assertSame(1, $exit, $path);
            self::assertStringContainsString('is not an existing project-relative directory', $stderr);
        }
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
    private function answersFile(array $answers): string
    {
        $path = $this->root . '-answers-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, json_encode(['version' => 1, 'answers' => $answers], JSON_THROW_ON_ERROR));
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
