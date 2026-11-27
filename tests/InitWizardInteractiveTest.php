<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Init\InitWizardCommand;

/**
 * The step protocol and the terminal flow walk the same question contract one question
 * at a time. Loop decides every next question; input only answers it.
 *
 * @internal
 */
final class InitWizardInteractiveTest extends TestCase
{
    private const array ENVIRONMENT = ['AGENT_LOOP_HOST_SESSION_ID', 'CLAUDE_CODE_SESSION_ID', 'AGENT_LOOP_INTERACTION_DIR'];

    /** Line answers for the polyglot fixture, in contract order. */
    private const string SCRIPT = "php,typescript\n\ns\ntest\nde\n\n";

    /** The same answers as an answers document. */
    private const array EQUIVALENT = [
        'map.languages' => ['php', 'typescript'],
        'map.paths' => ['action' => 'recommended'],
        'checks.composer_scripts' => ['action' => 'skip'],
        'checks.package_scripts' => ['test'],
        'prompting.language' => 'de',
        'personal.language' => ['action' => 'keep'],
    ];

    private string $root;

    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-wizard-steps-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/lib', 0o775, true);
        mkdir($this->root . '/.agent-loop', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo {}\n");
        file_put_contents($this->root . '/lib/index.js', "export const x = 1;\n");
        file_put_contents($this->root . '/composer.json', '{"autoload": {"psr-4": {"App\\\\": "src/"}}, "scripts": {"test": "vendor/bin/phpunit"}}');
        file_put_contents($this->root . '/package.json', '{"scripts": {"test": "node --test", "lint": "eslint ."}}');
        foreach (self::ENVIRONMENT as $name) {
            $this->environment[$name] = getenv($name);
            putenv($name);
        }
        putenv('AGENT_LOOP_INTERACTION_DIR=' . $this->root . '-preferences');
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        foreach ([$this->root, $this->root . '-preferences'] as $path) {
            if (!is_dir($path)) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($path);
        }
    }

    public function testInteractiveRunAsksInContractOrderAndMatchesTheEquivalentAnswersRun(): void
    {
        [$exit, $stdout, $stderr] = $this->wizard(['--interactive'], self::SCRIPT);

        self::assertSame(0, $exit, $stderr);
        preg_match_all('/^\[\d\/\d\] (\S+)$/m', $stderr, $asked);
        self::assertSame(InitWizardCommand::QUESTION_IDS, $asked[1], 'One question at a time, in the fixed contract order.');
        self::assertStringContainsString('current:     (none) (not_configured)', $stderr);
        self::assertStringContainsString('recommended: php, typescript', $stderr);
        self::assertStringContainsString('effect:      Changes prompting.language in .agent-loop/init.json through setup apply', $stderr);

        $interactive = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        $answers = (new InitWizardCommand($this->root))->inspect(self::EQUIVALENT);
        foreach (['answers', 'changes', 'validation_candidates', 'next_actions', 'evidence_sha256', 'questions', 'skipped'] as $key) {
            self::assertSame(InitWizardCommand::encode([$key => $answers[$key]]), InitWizardCommand::encode([$key => $interactive[$key]]), $key);
        }
        self::assertNull($interactive['step']);
        self::assertSame(['map.languages', 'prompting.language'], array_column($interactive['changes'], 'id'));

        [$replayExit, $replayStdout] = $this->wizard(['--interactive'], self::SCRIPT);
        self::assertSame([0, $stdout], [$replayExit, $replayStdout], 'The same input gives byte-identical output.');

        $document = $this->root . '-answers.json';
        file_put_contents($document, json_encode($interactive['answers_document'], JSON_THROW_ON_ERROR));
        $reused = (new InitWizardCommand($this->root))->inspect(json_decode((string) file_get_contents($document), true, 512, JSON_THROW_ON_ERROR)['answers']);
        unlink($document);
        self::assertSame($interactive['changes'], $reused['changes'], 'The answers document reproduces the run.');
        self::assertFileDoesNotExist($this->root . '/.agent-loop/init.json', 'The wizard itself writes nothing.');
    }

    public function testInvalidInputIsAskedAgainAndEndOfInputFailsWithoutBlocking(): void
    {
        [$exit, , $stderr] = $this->wizard(['--interactive'], "cobol\nk\nphp\n" . substr(self::SCRIPT, strlen("php,typescript\n")));
        self::assertSame(0, $exit, $stderr);
        self::assertStringContainsString('map.languages accepts only: php, typescript.', $stderr);
        self::assertStringContainsString('map.languages has no current value to keep (not_configured).', $stderr);

        [$required, , $requiredErr] = $this->wizard(['--interactive'], "php\ns\nsrc\n");
        self::assertSame(1, $required);
        self::assertStringContainsString('map.paths is required and cannot be skipped.', $requiredErr);

        [$ended, $endedOut, $endedErr] = $this->wizard(['--interactive'], "php\n");
        self::assertSame(1, $ended);
        self::assertSame('', $endedOut);
        self::assertStringContainsString('input ended before the wizard finished; nothing was changed.', $endedErr);
    }

    public function testEachPromptAndTheResultAppearBeforeMoreInputIsTyped(): void
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bin/agent-loop', 'setup', 'wizard', '--interactive'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the wizard.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        try {
            self::assertTrue($this->waitFor($pipes[2], $stderr, '[1/6] map.languages'));
            foreach (explode("\n", substr(self::SCRIPT, 0, -1)) as $index => $line) {
                // stdin stays open: the wizard must not need the next line to show the next question.
                fwrite($pipes[0], $line . "\n");
                fflush($pipes[0]);
                if ($index < 5) {
                    self::assertTrue($this->waitFor($pipes[2], $stderr, sprintf('[%d/6] %s', $index + 2, InitWizardCommand::QUESTION_IDS[$index + 1])), 'Prompt ' . ($index + 2) . ' did not appear: ' . $stderr);
                }
            }
            self::assertTrue($this->waitFor($pipes[1], $stdout, '"answers_document"'), 'The result must not wait for the end of input.');
        } finally {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    public function testNextReturnsTheFirstOpenQuestionThenTheFullResult(): void
    {
        $wizard = new InitWizardCommand($this->root);

        $first = $wizard->next([]);
        self::assertSame('map.languages', $first['step']['id']);
        self::assertSame(['position' => 1, 'total' => 6, 'answered' => []], $first['progress']);
        self::assertSame(['recommended', 'change'], array_column($first['step']['actions'], 'action'), 'Nothing to keep yet, and a required question cannot be skipped.');

        $partial = ['map.languages' => ['php'], 'map.paths' => ['src']];
        $third = $wizard->next($partial);
        self::assertSame('checks.composer_scripts', $third['step']['id']);
        self::assertSame(['recommended', 'change', 'skip'], array_column($third['step']['actions'], 'action'));
        self::assertSame(InitWizardCommand::encode($third), InitWizardCommand::encode($wizard->next($partial)), 'A replay gives byte-identical output.');

        $done = $wizard->next(self::EQUIVALENT);
        self::assertNull($done['step']);
        $full = $wizard->inspect(self::EQUIVALENT);
        foreach ($full as $key => $value) {
            self::assertSame(InitWizardCommand::encode([$key => $value]), InitWizardCommand::encode([$key => $done[$key]]), $key);
        }

        [$exit, $stdout, $stderr] = $this->wizard(['--next', '--answers=' . $this->answersFile(['map.languages' => ['cobol']])], '');
        self::assertSame([1, ''], [$exit, $stdout]);
        self::assertStringContainsString('map.languages accepts only', $stderr);
    }

    public function testTheRecommendedEffectSaysNoChangeWhenTheRecommendationProposesNothing(): void
    {
        // A PHP-only project: the recommended php equals what an unconfigured map build already indexes.
        unlink($this->root . '/package.json');
        unlink($this->root . '/lib/index.js');
        $wizard = new InitWizardCommand($this->root);

        $languages = array_column($wizard->next([])['step']['actions'], null, 'action')['recommended'];
        self::assertSame(['php'], $languages['value']);
        self::assertSame('No change.', $languages['effect'], 'Unconfigured map.languages already resolves to php, so php proposes nothing.');

        $paths = array_column($wizard->next(['map.languages' => ['php']])['step']['actions'], null, 'action')['recommended'];
        self::assertNotSame('No change.', $paths['effect'], 'Recommended paths propose a map build command.');
    }

    public function testARecommendationTheValidatorRefusesStillShowsTheStep(): void
    {
        // PHPStan evidence can propose `.`, which map.paths refuses as a project-relative directory.
        file_put_contents($this->root . '/phpstan.neon', "parameters:\n    paths:\n        - .\n");
        $wizard = new InitWizardCommand($this->root);

        $step = $wizard->next(['map.languages' => ['php']])['step'];

        self::assertSame('map.paths', $step['id']);
        self::assertContains('.', $step['default']);
        self::assertNotSame('No change.', array_column($step['actions'], null, 'action')['recommended']['effect']);
        self::assertSame('checks.composer_scripts', $wizard->next(['map.languages' => ['php'], 'map.paths' => ['src']])['step']['id'], 'A valid answer moves past the question.');
    }

    public function testAnswerActionsResolveAsSpecified(): void
    {
        $wizard = new InitWizardCommand($this->root);
        $result = $wizard->inspect([
            'map.languages' => ['action' => 'recommended'],
            'map.paths' => ['action' => 'change', 'value' => ['src']],
            'prompting.language' => ['action' => 'keep'],
            'personal.language' => ['action' => 'skip'],
        ]);

        self::assertSame([
            'map.languages' => ['php', 'typescript'],
            'map.paths' => ['src'],
            'prompting.language' => 'en',
            'personal.language' => ['action' => 'skip'],
        ], $result['answers']);
        self::assertSame(['map.languages'], array_column($result['changes'], 'id'), 'keep and skip propose nothing.');

        foreach ([
            ['map.languages' => ['action' => 'keep']],
            ['prompting.language' => ['action' => 'skip']],
            ['map.paths' => ['action' => 'change']],
            ['map.paths' => ['action' => 'later']],
            ['map.paths' => ['action' => 'keep', 'value' => ['src']]],
        ] as $answers) {
            [$exit, , $stderr] = $this->wizard(['--answers=' . $this->answersFile($answers)], '');
            self::assertSame(1, $exit, json_encode($answers, JSON_THROW_ON_ERROR));
            self::assertStringStartsWith('[FAIL] setup wizard: ', $stderr);
        }

        [$versionOne] = $this->wizard(['--answers=' . $this->answersFile(['prompting.language' => 'en'], 1)], '');
        self::assertSame(0, $versionOne, 'A version 1 answers document is still read.');
        [$combined] = $this->wizard(['--interactive', '--next'], '');
        self::assertSame(1, $combined);
    }

    /** @param resource $stream */
    private function waitFor($stream, string &$buffer, string $needle): bool
    {
        $deadline = microtime(true) + 20.0;
        while (!str_contains($buffer, $needle) && microtime(true) < $deadline) {
            $read = [$stream];
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, 0, 200000) > 0) {
                $chunk = fread($stream, 65536);
                if ($chunk === false || ($chunk === '' && feof($stream))) {
                    break;
                }
                $buffer .= $chunk;
            }
        }

        return str_contains($buffer, $needle);
    }

    /** @param array<string, mixed> $answers */
    private function answersFile(array $answers, int $version = InitWizardCommand::VERSION): string
    {
        $path = $this->root . '/.answers-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, json_encode(['version' => $version, 'answers' => $answers], JSON_THROW_ON_ERROR));

        return $path;
    }

    /**
     * Runs the real CLI in the fixture with scripted standard input.
     *
     * @param list<string> $arguments
     * @return array{0: int, 1: string, 2: string}
     */
    private function wizard(array $arguments, string $input): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bin/agent-loop', 'setup', 'wizard', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the wizard.');
        }
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
