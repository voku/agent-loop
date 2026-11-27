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
 * host-session preference for a personal language). The wizard itself never writes
 * a file and never runs a project script, so rerunning it is always safe.
 */
final readonly class InitWizardCommand
{
    public const string SCHEMA = 'agent-loop.setup-wizard';
    public const int VERSION = 1;

    /** Stable IDs in their fixed order. A new question gets a new ID and a new schema version. */
    public const array QUESTION_IDS = [
        'map.languages',
        'map.paths',
        'checks.composer_scripts',
        'checks.package_scripts',
        'prompting.language',
        'personal.language',
    ];

    private const array MAP_LANGUAGES = [SourceLanguage::PHP, SourceLanguage::TYPESCRIPT];
    private const int MAX_ANSWERS_BYTES = 65536;

    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        try {
            $answers = null;
            foreach ($tokens as $token) {
                if (str_starts_with($token, '--answers=')) {
                    $answers = $this->readAnswers(substr($token, strlen('--answers=')));
                    continue;
                }
                if ($token !== '--format=json') {
                    throw new InvalidArgumentException('Usage: agent-loop setup wizard [--answers=<file.json>] [--format=json]');
                }
            }
            echo json_encode($this->inspect($answers), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

            return 0;
        } catch (InvalidArgumentException | JsonException | RuntimeException $exception) {
            fwrite(\STDERR, '[FAIL] setup wizard: ' . $exception->getMessage() . "\n");

            return 1;
        }
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
        try {
            $preference = $session->preference();
        } catch (Throwable) {
            // Like WorkflowPresentationLanguage: an unreadable host preference counts as none.
            $preference = null;
        }

        $questions = [];
        $skipped = [];
        foreach ($this->questions($evidence, $config, $preference) as $order => $question) {
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
            $result += $this->evaluate($this->validate($answers, $questions, $skipped), $questions, $session, $preference !== null, $configWarnings);
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
    private function questions(array $evidence, array $config, ?InteractionPreference $preference): array
    {
        $map = $evidence['map'];
        $configuredLanguages = $config['map']['languages'] ?? null;
        $mapPaths = array_values(array_unique([...$map['paths'], ...($map['typescript_paths'] ?? [])]));
        $composerScripts = $evidence['quality']['composer_scripts'];
        $packageScripts = $evidence['quality']['package_scripts'] ?? [];
        $personal = $preference?->language;

        return [
            $this->question('map.languages', 'project', 'multi_choice', true, 'Which languages should agent-map index?', [
                'options' => self::MAP_LANGUAGES,
                'default' => $map['languages'] ?? [SourceLanguage::PHP],
                'default_source' => $map['languages_source'] ?? 'agent-loop default',
                'current' => $configuredLanguages,
                'current_source' => $configuredLanguages !== null ? '.agent-loop/init.json' : ($config['map']['languages_error'] === null ? 'not_configured' : 'invalid_config'),
            ]),
            $this->question('map.paths', 'project', 'path_list', true, 'Which directories should agent-map index?', [
                'default' => $mapPaths,
                'default_source' => $map['source'] ?? ($mapPaths === [] ? 'no_evidence' : 'directory-presence'),
                'current' => null,
                'current_source' => 'not_recorded',
            ]),
            $this->scriptQuestion('checks.composer_scripts', $composerScripts, $evidence['quality']['source'], 'composer.json'),
            $this->scriptQuestion('checks.package_scripts', $packageScripts, $evidence['quality']['package_source'] ?? null, 'package.json'),
            $this->question('prompting.language', 'project', 'bcp47', true, 'Which language should this project use by default for agent explanations?', [
                'default' => $evidence['prompting']['language'],
                'default_source' => $evidence['prompting']['source'],
                'current' => $evidence['prompting']['language'],
                'current_source' => $evidence['prompting']['source'],
            ]),
            $this->question('personal.language', 'personal', 'bcp47_or_inherit', false, 'Which language do you personally want decision cards in? (inherit follows the project)', [
                'default' => 'inherit',
                'default_source' => 'project_language',
                'current' => $personal ?? 'inherit',
                'current_source' => $personal === null ? 'project_language' : 'host_session',
                'storage' => 'host-session preference in the user state directory; never tracked project config',
            ]),
        ];
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

    /** @param array<string, mixed> $question */
    private function normalizeAnswer(array $question, mixed $value): mixed
    {
        $id = $question['id'];
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
            if (!is_string($path) || preg_match('#\A[A-Za-z0-9_][A-Za-z0-9._/-]*\z#', $path) !== 1
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
     * @return array{answers: array<string, mixed>|stdClass, changes: list<array<string, mixed>>, validation_candidates: list<array<string, string>>, next_actions: list<array<string, mixed>>}
     */
    private function evaluate(array $answers, array $questions, HostSessionBinding $session, bool $hasPreference, array $configWarnings): array
    {
        $byId = array_column($questions, null, 'id');
        $changes = [];
        $candidates = [];
        $nextActions = [];

        // What map build resolves without --languages: the declaration, php when none, nothing when it is invalid.
        $invalidLanguages = $byId['map.languages']['current_source'] === 'invalid_config';
        $resolvedLanguages = $invalidLanguages ? null : $byId['map.languages']['current'] ?? [SourceLanguage::PHP];
        if (isset($answers['map.languages']) && $answers['map.languages'] !== $resolvedLanguages) {
            $changes[] = [
                'id' => 'map.languages',
                'target' => '.agent-loop/init.json',
                'pointer' => '/map/languages',
                'before' => $byId['map.languages']['current'],
                'before_source' => $byId['map.languages']['current_source'],
                'after' => $answers['map.languages'],
                // There is deliberately no generic init.json writer; the host edits this after review.
                'apply' => ['status' => 'manual_review', 'fragment' => ['map' => ['languages' => $answers['map.languages']]]],
            ];
        }
        if (isset($answers['map.paths'])) {
            // An unanswered language question keeps what is configured; only an unconfigured project gets the proposal.
            $languages = $answers['map.languages'] ?? $byId['map.languages']['current'] ?? $byId['map.languages']['default'];
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
                'target' => 'host_session_preference',
                'pointer' => null,
                'before' => $byId['personal.language']['current'],
                'before_source' => $byId['personal.language']['current_source'],
                'after' => $answers['personal.language'],
                'apply' => $this->personalLanguageApply($session, $hasPreference, $answers['personal.language']),
            ];
        }

        foreach ($changes as $index => $change) {
            // A reported change is a proposal; whoever runs its command must confirm it first.
            $changes[$index]['requires_confirmation'] = true;
        }

        // Always a JSON object, so the versioned contract keeps one type for an empty answer set.
        return ['answers' => $answers === [] ? new stdClass() : $answers, 'changes' => $changes, 'validation_candidates' => $candidates, 'next_actions' => $nextActions];
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
    private function personalLanguageApply(HostSessionBinding $session, bool $hasPreference, string $language): array
    {
        if ($session->state !== HostSessionBinding::BOUND || $session->sessionId === null) {
            return [
                'status' => 'host_session_required',
                'prerequisite' => 'Export ' . HostSessionBinding::EXPLICIT_VARIABLE . ' (or run inside a host that sets its session variable), then rerun the wizard.',
            ];
        }
        $command = sprintf('vendor/bin/agent-loop workflow interaction %s --language=%s', escapeshellarg($session->sessionId), $language);
        if (!$hasPreference) {
            // The command is valid only after the mode exists, so the route is not ready yet.
            return [
                'status' => 'prerequisite_required',
                'prerequisite' => sprintf('A session language needs a saved mode: run vendor/bin/agent-loop workflow interaction %s <collaborative|autonomous> first.', escapeshellarg($session->sessionId)),
                'then' => $command,
            ];
        }

        return ['status' => 'supported', 'command' => $command];
    }

    /** @return array<string, mixed> */
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
        $shape = 'Answers file must be {"version": 1, "answers": {"<question-id>": <value>}}.';
        if (!is_array($decoded) || ($decoded['version'] ?? null) !== self::VERSION || !is_array($decoded['answers'] ?? null)) {
            throw new InvalidArgumentException($shape);
        }
        $answers = [];
        foreach ($decoded['answers'] as $id => $value) {
            if (!is_string($id)) {
                throw new InvalidArgumentException($shape);
            }
            $answers[$id] = $value;
        }

        return $answers;
    }
}
