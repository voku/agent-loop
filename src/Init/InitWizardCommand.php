<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use stdClass;
use Throwable;
use voku\AgentLoop\AgentGuidance\HostSessionBinding;
use voku\AgentLoop\AgentGuidance\InteractionPreference;
use voku\AgentLoop\AgentGuidance\InteractionPreferenceStore;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Workflow\WorkflowPresentationLanguage;
use voku\AgentMap\IO\SourceLanguage;

/**
 * The setup wizard as a versioned question contract over existing setup evidence.
 *
 * Every run asks the same core questions in the same order; evidence only decides
 * whether a conditional question is asked and what its default is. Answers are
 * validated and turned into changes with provenance, each routed to the writer that
 * already owns it (the evidence-guarded `init apply` for the project language, the
 * user's default-language preference for a personal language). The step protocol
 * (`--next`) and the terminal flow (`--interactive`) walk the same questions one at a
 * time; Loop owns their order and progression. The wizard itself never writes a file
 * and never runs a project script, so rerunning it is always safe.
 */
final readonly class InitWizardCommand
{
    public const string SCHEMA = 'agent-loop.setup-wizard';
    /** Version 3 adds the reviewed, persistent map.paths proposal to the question result. */
    public const int VERSION = 3;

    /** Answers files of both versions share one shape. */
    private const array ANSWERS_VERSIONS = [1, 2, 3];

    /** What answering a question touches; nothing runs without confirmation. */
    private const array CONSEQUENCES = [
        'map.languages' => 'Proposes the map.languages fragment for .agent-loop/init.json (manual review) and the languages map build indexes.',
        'map.paths' => 'Proposes the map.paths fragment for .agent-loop/init.json (manual review) and a map build command; it runs only after confirmation.',
        'checks.composer_scripts' => 'Lists the scripts as Contract validation candidates; nothing is executed.',
        'checks.package_scripts' => 'Lists the scripts as Contract validation candidates; nothing is executed.',
        'prompting.language' => 'Changes prompting.language in .agent-loop/init.json through setup apply after confirmation; it applies to everyone working on this project.',
        'personal.language' => 'Changes only your own default language in your user state directory through workflow interaction --default-language after confirmation; never tracked project config.',
    ];

    /** Stable IDs in their fixed order. A new question gets a new ID and a new schema version. */
    public const array QUESTION_IDS = [
        'map.languages',
        'map.paths',
        'checks.composer_scripts',
        'checks.package_scripts',
        'prompting.language',
        'personal.language',
    ];

    /** How a skipped optional question is recorded in the normalized answers. */
    public const array SKIP = ['action' => 'skip'];

    private const array MAP_LANGUAGES = [SourceLanguage::PHP, SourceLanguage::TYPESCRIPT];
    private const int MAX_ANSWERS_BYTES = 65536;

    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        try {
            $usage = 'Usage: agent-loop setup wizard [--answers=<file.json>] [--next] [--format=json], or agent-loop setup wizard --interactive';
            $answers = null;
            $answersVersion = null;
            $next = false;
            $interactive = false;
            foreach ($tokens as $token) {
                if (str_starts_with($token, '--answers=') && $answers === null) {
                    [$answersVersion, $answers] = $this->readAnswers(substr($token, strlen('--answers=')));
                    continue;
                }
                $next = $next || $token === '--next';
                $interactive = $interactive || $token === '--interactive';
                if (!in_array($token, ['--format=json', '--next', '--interactive'], true)) {
                    throw new InvalidArgumentException($usage);
                }
            }
            if ($interactive) {
                if ($answers !== null || $next) {
                    throw new InvalidArgumentException($usage);
                }

                return (new InitWizardInteractive($this, InitWizardInteractive::lines(\STDIN), static function (string $text): void {
                    fwrite(\STDERR, $text);
                }))->run();
            }
            $result = $next ? $this->next($answers ?? []) : $this->inspect($answers);
            if ($answersVersion === 1 && array_key_exists('personal.language', $answers ?? [])) {
                $result['warnings'][] = '[WARN] setup wizard: this version 1 answers file sets personal.language, which now means your default language for every host session (version 1: this host session only). Write version 2 to confirm.';
            }
            echo self::encode($result) . "\n";

            return 0;
        } catch (InvalidArgumentException | JsonException | RuntimeException $exception) {
            fwrite(\STDERR, '[FAIL] setup wizard: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    /** @param array<string, mixed> $result */
    public static function encode(array $result): string
    {
        return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * The step protocol: the first question in contract order without an answer, with the
     * actions it allows and what each touches; once every question is answered or skipped,
     * the same result as a full `--answers` run. The caller relays questions; it never picks
     * the next one, so the same partial answers always give the same step.
     *
     * @param array<string, mixed> $answers question ID => answer, possibly partial
     * @return array<string, mixed>
     */
    public function next(array $answers): array
    {
        $result = $this->inspect($answers);
        $asked = $result['questions'];
        foreach ($asked as $position => $question) {
            if (!array_key_exists($question['id'], $answers)) {
                return [
                    'schema' => self::SCHEMA,
                    'version' => self::VERSION,
                    'mode' => 'read_only',
                    'evidence_sha256' => $result['evidence_sha256'],
                    'progress' => ['position' => $position + 1, 'total' => count($asked), 'answered' => array_keys($result['answers'] instanceof stdClass ? [] : $result['answers'])],
                    'step' => [...$question, 'actions' => $this->actions($question, $this->recommendedProposes($answers, $question)), 'consequence' => self::CONSEQUENCES[$question['id']]],
                    'execution' => 'not_authorized',
                    'warnings' => $result['warnings'],
                ];
            }
        }

        return ['progress' => ['position' => count($asked), 'total' => count($asked), 'answered' => array_column($asked, 'id')], 'step' => null, ...$result];
    }

    /**
     * @param array<string, mixed>|null $answers question ID => answer
     * @return array<string, mixed>
     */
    public function inspect(?array $answers = null): array
    {
        $evidence = (new InitRecommendCommand($this->rootPath))->inspect();
        $plan = (new InitPlanCommand($this->rootPath))->inspect();
        $config = (new InitConfigLoader($this->rootPath))->load((new ProjectLayout($this->rootPath))->configPath());
        $session = HostSessionBinding::fromEnvironment();
        $store = $session->directory === null ? null : new InteractionPreferenceStore($session->directory);
        try {
            $preference = $session->preference();
            $defaultLanguage = $store?->defaultLanguage();
            $hasSharedDefault = $store?->hasSharedDefault() ?? false;
        } catch (Throwable) {
            // Like WorkflowPresentationLanguage: an unreadable host preference counts as none.
            $preference = null;
            $defaultLanguage = null;
            $hasSharedDefault = false;
        }

        $questions = [];
        $skipped = [];
        // An init.json that is not valid JSON is ignored by every reader; its values are defaults, not configuration.
        $configUnreadable = in_array('[WARN] init config: invalid JSON', $config['warnings'], true);
        foreach ($this->questions($evidence, $config, $configUnreadable, $preference, $defaultLanguage) as $order => $question) {
            $question = ['order' => $order + 1, ...$question];
            if (isset($question['skipped_because'])) {
                $skipped[] = ['id' => $question['id'], 'order' => $question['order'], 'reason' => $question['skipped_because']];
                continue;
            }
            $questions[] = $question;
        }

        $result = [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'mode' => 'read_only',
            'evidence_sha256' => $plan['evidence_sha256'],
            'questions' => $questions,
            'skipped' => $skipped,
        ];
        if ($answers !== null) {
            $configWarnings = array_values(array_filter($evidence['warnings'], static fn (string $warning): bool => str_contains($warning, 'init config:')));
            $result += $this->evaluate($this->validate($answers, $questions, $skipped), $questions, $session, $store !== null && $hasSharedDefault, $configWarnings, $this->storedLanguages($config));
        }

        return $result + [
            'file_changes' => [],
            'execution' => 'not_authorized',
            'warnings' => $evidence['warnings'],
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    private function questions(array $evidence, array $config, bool $configUnreadable, ?InteractionPreference $preference, ?string $defaultLanguage): array
    {
        $languageWarning = array_intersect(['[WARN] init config: prompting.language must be a BCP 47 language tag', '[WARN] init config: prompting must be an object'], $config['warnings']) !== [];
        $map = $evidence['map'];
        $configuredLanguages = $config['map']['languages'] ?? null;
        $configuredPaths = $config['map']['paths'] ?? null;
        $mapPaths = array_values(array_unique([...$map['paths'], ...($map['typescript_paths'] ?? [])]));
        $composerScripts = $evidence['quality']['composer_scripts'];
        $packageScripts = $evidence['quality']['package_scripts'] ?? [];

        return [
            $this->question('map.languages', 'project', 'multi_choice', true, 'Which languages should agent-map index?', [
                'options' => self::MAP_LANGUAGES,
                'default' => $map['languages'] ?? [SourceLanguage::PHP],
                'default_source' => $map['languages_source'] ?? 'agent-loop default',
                'current' => $configuredLanguages,
                'current_source' => $configuredLanguages !== null ? '.agent-loop/init.json' : ($config['map']['languages_error'] === null && !$configUnreadable ? 'not_configured' : 'invalid_config'),
            ]),
            $this->question('map.paths', 'project', 'path_list', true, 'Which directories should agent-map index?', [
                'default' => $mapPaths,
                'default_source' => $map['source'] ?? ($mapPaths === [] ? 'no_evidence' : 'directory-presence'),
                'current' => $configuredPaths,
                'current_source' => $configuredPaths !== null
                    ? '.agent-loop/init.json'
                    : ($config['map']['paths_error'] === null ? 'not_configured' : 'invalid_config'),
            ]),
            $this->scriptQuestion('checks.composer_scripts', $composerScripts, $evidence['quality']['source'], 'composer.json'),
            $this->scriptQuestion('checks.package_scripts', $packageScripts, $evidence['quality']['package_source'] ?? null, 'package.json'),
            $this->question('prompting.language', 'project', 'bcp47', true, 'Which language should this project use by default for agent explanations?', [
                'default' => $evidence['prompting']['language'],
                'default_source' => $evidence['prompting']['source'],
                'current' => $evidence['prompting']['language'],
                // The value then is the built-in default, not what init.json says.
                'current_source' => $configUnreadable || $languageWarning ? 'invalid_config' : $evidence['prompting']['source'],
            ]),
            $this->question('personal.language', 'personal', 'bcp47_or_inherit', false, 'Which language do you personally want decision cards in, in every host session? (inherit follows the project)', [
                'default' => 'inherit',
                'default_source' => 'project_language',
                'current' => $defaultLanguage ?? 'inherit',
                'current_source' => $defaultLanguage === null ? 'project_language' : 'user_default',
                // A language set for this session only still wins over the default while it lasts.
                'session_override' => $preference?->language,
                'storage' => 'your default language in the user state directory; never tracked project config',
            ]),
        ];
    }

    /**
     * Whether taking the recommendation would propose anything: a change, a command or a
     * validation candidate for this question. Comparing the recommendation with `current` is
     * not enough; an unconfigured map.languages recommending php proposes nothing.
     *
     * @param array<string, mixed> $answers
     * @param array<string, mixed> $question
     */
    private function recommendedProposes(array $answers, array $question): bool
    {
        if ($question['default'] === [] && $question['required']) {
            return false;
        }
        try {
            $result = $this->inspect([...$answers, $question['id'] => ['action' => 'recommended']]);
        } catch (InvalidArgumentException) {
            // The recommendation itself is refused (an evidence path such as `.`); choosing it
            // fails with that reason, so the step must still be shown and must not claim "No change."
            return true;
        }
        foreach (['changes', 'next_actions', 'validation_candidates'] as $key) {
            if (in_array($question['id'], array_column($result[$key], 'id'), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The actions a question allows, with the value each resolves to and its effect.
     *
     * @param array<string, mixed> $question
     * @return list<array<string, mixed>>
     */
    private function actions(array $question, bool $recommendedProposes): array
    {
        $consequence = self::CONSEQUENCES[$question['id']];
        $actions = [];
        if ($question['current'] !== null) {
            $actions[] = ['action' => 'keep', 'value' => $question['current'], 'effect' => 'No change.'];
        }
        if ($question['default'] !== [] || !$question['required']) {
            $actions[] = ['action' => 'recommended', 'value' => $question['default'], 'effect' => $recommendedProposes ? $consequence : 'No change.'];
        }
        $actions[] = ['action' => 'change', 'effect' => $consequence];
        if (!$question['required']) {
            $actions[] = ['action' => 'skip', 'effect' => 'Not answered; nothing is proposed for this question.'];
        }

        return $actions;
    }

    /**
     * @param list<string> $scripts
     * @return array<string, mixed>
     */
    private function scriptQuestion(string $id, array $scripts, ?string $source, string $manifest): array
    {
        if ($scripts === []) {
            return ['id' => $id, 'skipped_because' => $source === null ? 'no ' . $manifest : 'no validation-like scripts declared in ' . $manifest];
        }

        return $this->question($id, 'project', 'multi_choice', false, 'Which declared ' . $manifest . ' scripts should be offered as Contract validation candidates?', [
            'options' => $scripts,
            // Opt-in: a declared script name is not evidence that running it is safe.
            'default' => [],
            'default_source' => 'opt_in',
            'current' => null,
            'current_source' => 'not_recorded',
            'evidence_source' => $source,
            'note' => 'Script names only; bodies are unverified and nothing is executed.',
        ]);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function question(string $id, string $appliesTo, string $kind, bool $required, string $prompt, array $fields): array
    {
        return ['id' => $id, 'applies_to' => $appliesTo, 'kind' => $kind, 'required' => $required, 'prompt' => $prompt, ...$fields];
    }

    /**
     * @param array<string, mixed> $answers
     * @param list<array<string, mixed>> $questions
     * @param list<array{id: string, order: int, reason: string}> $skipped
     * @return array<string, mixed> normalized answers keyed by question ID, in question order
     */
    private function validate(array $answers, array $questions, array $skipped): array
    {
        $byId = array_column($questions, null, 'id');
        $skippedById = array_column($skipped, 'reason', 'id');
        foreach (array_keys($answers) as $id) {
            if (isset($skippedById[$id])) {
                throw new InvalidArgumentException(sprintf('Question %s was not asked (%s).', $id, $skippedById[$id]));
            }
            if (!isset($byId[$id])) {
                throw new InvalidArgumentException(sprintf('Unknown question ID %s; expected one of %s.', $id, implode(', ', self::QUESTION_IDS)));
            }
        }

        $normalized = [];
        foreach (self::QUESTION_IDS as $id) {
            if (array_key_exists($id, $answers)) {
                $normalized[$id] = $this->normalizeAnswer($byId[$id], $answers[$id]);
            }
        }

        return $normalized;
    }

    /**
     * Resolves an answer action: keep the current value, take the recommendation, skip an
     * optional question, or change to a value (a bare value means change).
     *
     * @param array<string, mixed> $question
     */
    private function normalizeAnswer(array $question, mixed $value): mixed
    {
        $id = $question['id'];
        if (is_array($value) && !array_is_list($value)) {
            $action = $value['action'] ?? null;
            $extra = array_diff(array_keys($value), $action === 'change' ? ['action', 'value'] : ['action']);
            if (!is_string($action) || $extra !== [] || !in_array($action, ['keep', 'recommended', 'skip', 'change'], true)) {
                throw new InvalidArgumentException($id . ' accepts a value or {"action": "keep|recommended|skip"} or {"action": "change", "value": ...}.');
            }
            if ($action === 'skip') {
                if ($question['required']) {
                    throw new InvalidArgumentException($id . ' is required and cannot be skipped.');
                }

                return self::SKIP;
            }
            if ($action === 'keep' && $question['current'] === null) {
                throw new InvalidArgumentException($id . ' has no current value to keep (' . $question['current_source'] . ').');
            }
            $value = match ($action) {
                'keep' => $question['current'],
                'recommended' => $question['default'],
                default => $value['value'] ?? null,
            };
        }

        $isLanguage = in_array($question['kind'], ['bcp47', 'bcp47_or_inherit'], true);
        if ($isLanguage ? !is_string($value) : !is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException($id . ($isLanguage ? ' must be a BCP 47 language tag such as de-DE or en.' : ' must be a list.'));
        }

        return match ($question['kind']) {
            'multi_choice' => $this->choices($id, $value, $question['options'], $question['required']),
            'path_list' => $this->paths($id, $value),
            default => $value === 'inherit' && $question['kind'] === 'bcp47_or_inherit' ? 'inherit' : WorkflowPresentationLanguage::normalize($value),
        };
    }

    /**
     * @param list<mixed> $value
     * @param list<string> $options
     * @return list<string> in option order
     */
    private function choices(string $id, array $value, array $options, bool $required): array
    {
        if ($required && $value === []) {
            throw new InvalidArgumentException(sprintf('%s must be a non-empty list of: %s.', $id, implode(', ', $options)));
        }
        foreach ($value as $choice) {
            if (!is_string($choice) || !in_array($choice, $options, true)) {
                throw new InvalidArgumentException(sprintf('%s accepts only: %s.', $id, implode(', ', $options)));
            }
        }

        return array_values(array_intersect($options, $value));
    }

    /**
     * @param list<mixed> $value
     * @return non-empty-list<string>
     */
    private function paths(string $id, array $value): array
    {
        if ($value === []) {
            throw new InvalidArgumentException($id . ' must be a non-empty list of project-relative directories.');
        }
        $paths = [];
        foreach ($value as $path) {
            if (!is_string($path) || ($path !== '.' && preg_match('#\A[A-Za-z0-9_.][A-Za-z0-9._/-]*\z#', $path) !== 1)
                || ($path !== '.' && in_array('.', explode('/', rtrim($path, '/')), true))
                || in_array('..', explode('/', $path), true) || !$this->isProjectDirectory($path)) {
                throw new InvalidArgumentException(sprintf('%s entry %s is not an existing project-relative directory.', $id, json_encode($path)));
            }
            $paths[] = rtrim($path, '/');
        }

        return array_values(array_unique($paths));
    }

    /** An existing directory that resolves inside the project, so a symlink cannot point the Map elsewhere. */
    private function isProjectDirectory(string $path): bool
    {
        $root = realpath($this->rootPath);
        $resolved = realpath($this->rootPath . '/' . $path);

        return $root !== false && $resolved !== false && is_dir($resolved)
            && ($resolved === $root || str_starts_with($resolved, rtrim($root, '/') . '/'));
    }

    /**
     * @param array<string, mixed> $answers normalized
     * @param list<array<string, mixed>> $questions
     * @param list<string> $configWarnings
     * @param array{value: mixed}|null $storedLanguages an invalid map.languages declaration, as stored
     * @return array{answers: array<string, mixed>|stdClass, changes: list<array<string, mixed>>, validation_candidates: list<array<string, string>>, next_actions: list<array<string, mixed>>}
     */
    private function evaluate(array $answers, array $questions, HostSessionBinding $session, bool $hasSharedDefault, array $configWarnings, ?array $storedLanguages): array
    {
        $byId = array_column($questions, null, 'id');
        $changes = [];
        $candidates = [];
        $nextActions = [];
        $record = $answers;
        // A skipped question stays recorded as skipped but proposes nothing.
        $answers = array_filter($answers, static fn (mixed $answer): bool => $answer !== self::SKIP);

        // What map build resolves without --languages: the declaration, php when none, nothing when it is invalid.
        $resolvedLanguages = $storedLanguages === null ? $byId['map.languages']['current'] ?? [SourceLanguage::PHP] : null;
        if (isset($answers['map.languages']) && $answers['map.languages'] !== $resolvedLanguages) {
            $changes[] = [
                'id' => 'map.languages',
                'target' => '.agent-loop/init.json',
                'pointer' => '/map/languages',
                // An invalid declaration has no current value, but it is still what the file says.
                'before' => $byId['map.languages']['current'] ?? $storedLanguages['value'] ?? null,
                'before_source' => $byId['map.languages']['current_source'],
                'after' => $answers['map.languages'],
                // There is deliberately no generic init.json writer; the host edits this after review.
                'apply' => ['status' => 'manual_review', 'fragment' => ['map' => ['languages' => $answers['map.languages']]]],
            ];
        }
        if (isset($answers['map.paths'])) {
            if ($answers['map.paths'] !== $byId['map.paths']['current']) {
                $changes[] = [
                    'id' => 'map.paths',
                    'target' => '.agent-loop/init.json',
                    'pointer' => '/map/paths',
                    'before' => $byId['map.paths']['current'],
                    'before_source' => $byId['map.paths']['current_source'],
                    'after' => $answers['map.paths'],
                    // Project map scope remains an explicit proposal; setup never writes init.json.
                    'apply' => ['status' => 'manual_review', 'fragment' => ['map' => ['paths' => $answers['map.paths']]]],
                ];
            }
            // An unanswered language question proposes nothing: the build indexes what map build resolves
            // today, so a later plain map build agrees with it. Only an invalid declaration needs explicit
            // languages, because map build refuses it.
            $languages = $answers['map.languages'] ?? $resolvedLanguages ?? $byId['map.languages']['default'];
            // An invalid declaration makes map build refuse unless --languages is explicit.
            $nextActions[] = [
                'id' => 'map.paths',
                // Without --languages, map build adds the configured languages (or php), so omit it only when that is the choice.
                'command' => 'vendor/bin/agent-loop map build --paths=' . escapeshellarg(implode(',', $answers['map.paths']))
                    . ($languages === $resolvedLanguages ? '' : ' --languages=' . escapeshellarg(implode(',', $languages))),
                'requires_confirmation' => true,
            ];
        }
        foreach (['checks.composer_scripts' => 'composer', 'checks.package_scripts' => 'package.json'] as $id => $owner) {
            foreach ($answers[$id] ?? [] as $script) {
                $candidates[] = ['id' => $id, 'owner' => $owner, 'script' => $script, 'execution' => 'not_authorized', 'prerequisite' => 'Inspect the script body before naming it as Contract validation.'];
            }
        }
        if (isset($answers['prompting.language']) && $answers['prompting.language'] !== $byId['prompting.language']['current']) {
            $changes[] = [
                'id' => 'prompting.language',
                'target' => '.agent-loop/init.json',
                'pointer' => '/prompting/language',
                'before' => $byId['prompting.language']['current'],
                'before_source' => $byId['prompting.language']['current_source'],
                'after' => $answers['prompting.language'],
                'apply' => $this->projectLanguageApply($answers['prompting.language'], $configWarnings),
            ];
        }
        if (isset($answers['personal.language']) && $answers['personal.language'] !== $byId['personal.language']['current']) {
            $changes[] = [
                'id' => 'personal.language',
                'target' => 'user_default_language',
                'pointer' => null,
                'before' => $byId['personal.language']['current'],
                'before_source' => $byId['personal.language']['current_source'],
                'after' => $answers['personal.language'],
                'apply' => $this->personalLanguageApply($session, $hasSharedDefault, $answers['personal.language']),
            ];
        }

        foreach ($changes as $index => $change) {
            // A reported change is a proposal; whoever runs its command must confirm it first.
            $changes[$index]['requires_confirmation'] = true;
        }

        // Always a JSON object, so the versioned contract keeps one type for an empty answer set.
        return ['answers' => $record === [] ? new stdClass() : $record, 'changes' => $changes, 'validation_candidates' => $candidates, 'next_actions' => $nextActions];
    }

    /**
     * @param list<string> $configWarnings
     * @return array<string, mixed>
     */
    private function projectLanguageApply(string $language, array $configWarnings): array
    {
        if ($configWarnings !== []) {
            // setup apply refuses a language change while init.json has validation warnings.
            return [
                'status' => 'prerequisite_required',
                'prerequisite' => 'Fix the .agent-loop/init.json warnings first (' . implode('; ', $configWarnings) . '), then rerun the wizard for a current apply command.',
            ];
        }
        if (!is_dir(dirname((new ProjectLayout($this->rootPath))->configPath()))) {
            // Scaffolding changes the setup evidence, so a digest computed now would already be stale.
            return [
                'status' => 'prerequisite_required',
                'prerequisite' => 'The writer needs existing workflow state: run vendor/bin/agent-loop setup scaffold, then rerun the wizard for a current apply command.',
            ];
        }
        $digest = (new InitPlanCommand($this->rootPath))->inspect($language)['evidence_sha256'];

        return [
            'status' => 'supported',
            'preview' => sprintf('vendor/bin/agent-loop setup apply --language=%s --evidence-sha256=%s --accept=prompting.language --dry-run', $language, $digest),
            'command' => sprintf('vendor/bin/agent-loop setup apply --language=%s --evidence-sha256=%s --accept=prompting.language --yes', $language, $digest),
        ];
    }

    /** @return array<string, mixed> */
    private function personalLanguageApply(HostSessionBinding $session, bool $hasSharedDefault, string $language): array
    {
        if ($session->directory === null) {
            return [
                'status' => 'prerequisite_required',
                'prerequisite' => 'No user state directory is known: set ' . HostSessionBinding::DIRECTORY_VARIABLE . ' (or HOME), then rerun the wizard.',
            ];
        }
        $command = 'vendor/bin/agent-loop workflow interaction --default-language=' . $language;
        if (!$hasSharedDefault) {
            // The default language is stored with the shared default mode, which must exist first.
            return [
                'status' => 'prerequisite_required',
                'prerequisite' => sprintf(
                    'A default language needs a shared default mode: run vendor/bin/agent-loop workflow interaction %s <collaborative|autonomous> first.',
                    $session->sessionId === null ? '<host-session-id>' : escapeshellarg($session->sessionId),
                ),
                'then' => $command,
            ];
        }

        return ['status' => 'supported', 'command' => $command];
    }

    /**
     * The raw map.languages value of an invalid declaration, or null when the declaration is
     * valid or absent. map build refuses an invalid one, so the wizard must not rely on it.
     *
     * @param array<string, mixed> $config
     * @return array{value: mixed}|null
     */
    private function storedLanguages(array $config): ?array
    {
        if ($config['map']['languages_error'] === null) {
            return null;
        }
        $path = (new ProjectLayout($this->rootPath))->configPath();
        $contents = is_file($path) ? file_get_contents($path) : false;
        $decoded = is_string($contents) ? json_decode($contents, true) : null;

        return ['value' => is_array($decoded) && is_array($decoded['map'] ?? null) ? $decoded['map']['languages'] ?? null : null];
    }

    /** @return array{0: int, 1: array<string, mixed>} the file's version and its answers */
    private function readAnswers(string $path): array
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('Answers file is not a readable file: ' . $path);
        }
        if (filesize($path) > self::MAX_ANSWERS_BYTES) {
            throw new InvalidArgumentException('Answers file is larger than ' . self::MAX_ANSWERS_BYTES . ' bytes.');
        }
        $contents = file_get_contents($path);
        $decoded = is_string($contents) ? json_decode($contents, true, 16, JSON_THROW_ON_ERROR) : null;
        $shape = 'Answers file must be {"version": ' . self::VERSION . ', "answers": {"<question-id>": <value>}} (versions 1 and 2 are also read).';
        if (!is_array($decoded) || !in_array($decoded['version'] ?? null, self::ANSWERS_VERSIONS, true) || !is_array($decoded['answers'] ?? null)) {
            throw new InvalidArgumentException($shape);
        }
        $answers = [];
        foreach ($decoded['answers'] as $id => $value) {
            if (!is_string($id)) {
                throw new InvalidArgumentException($shape);
            }
            $answers[$id] = $value;
        }

        return [$decoded['version'], $answers];
    }
}
