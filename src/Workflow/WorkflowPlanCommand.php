<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;
use voku\AgentLoop\ProjectLayout;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStatus;
use voku\AgentSession\SessionStore;

/**
 * Creates or revises durable candidate Contract state.
 *
 * PLAN deliberately creates neither Session nor Run. Working memory starts only
 * after an exact Contract revision is approved and a governed Run is prepared.
 */
final readonly class WorkflowPlanCommand
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        try {
            $taskId = new WorkflowTaskId($args[0] ?? '');
            $options = $this->parse(array_slice($args, 1));
            $contracts = new TaskContractStore($this->rootPath);
            $existing = $contracts->find($taskId->value);

            $activeSession = $existing !== null ? $this->activeSession($taskId->value) : null;
            if ($activeSession !== null && !$options['supersede']) {
                throw new RuntimeException(
                    'Cannot revise Contract for ' . $taskId->value . ' while governed Session ' . $activeSession->id
                    . ' is active. If durable intent is unchanged, continue the existing Run. If scope or policy changed, '
                    . 'rerun `agent-loop workflow plan ' . $taskId->value
                    . ' ... --supersede`; this retires the current working Session, creates a candidate Contract revision, '
                    . 'and still requires explicit approval before a replacement Run can start.',
                );
            }

            if ($existing === null) {
                if ($options['supersede']) {
                    throw new RuntimeException('--supersede requires an existing Contract revision.');
                }
                $contract = $contracts->create(
                    $taskId->value,
                    $options['goal'],
                    $options['scope'],
                    $options['nonGoals'],
                    $options['validation'],
                    $options['by'],
                    $options['baseCommit'],
                    $options['tags'],
                    $options['behaviorAnchors'],
                    $options['operatingPromptManifest'],
                    $options['operatingPrompts'],
                    $options['acceptanceCriteria'],
                    $options['acceptanceObservations'],
                );
                $action = 'created';
            } else {
                $preserve = $options['supersede'];
                $preserveOperatingPrompts = $preserve && $options['operatingPrompts'] === [];
                $preserveAcceptance = $preserve && $options['acceptanceCriteria'] === [];
                $contract = $contracts->revise(
                    $taskId->value,
                    $options['goal'],
                    $options['scope'],
                    $preserve && $options['nonGoals'] === [] ? $existing->nonGoals : $options['nonGoals'],
                    $options['validation'],
                    $options['by'],
                    $preserve && $options['baseCommit'] === null ? $existing->baseCommit : $options['baseCommit'],
                    $preserve && $options['tags'] === [] ? $existing->tags : $options['tags'],
                    $preserve && $options['behaviorAnchors'] === [] ? $existing->behaviorAnchors : $options['behaviorAnchors'],
                    $preserveOperatingPrompts ? $existing->operatingPromptManifest : $options['operatingPromptManifest'],
                    $preserveOperatingPrompts ? $existing->operatingPrompts : $options['operatingPrompts'],
                    $preserveAcceptance ? $existing->acceptanceCriteria : $options['acceptanceCriteria'],
                    $preserveAcceptance && $options['acceptanceObservations'] === [] ? $existing->acceptanceObservations : $options['acceptanceObservations'],
                );
                if ($activeSession !== null) {
                    // Persist the unapproved replacement intent first. If
                    // retiring working memory then fails, lifecycle policy sees
                    // a candidate Contract revision and remains fail-closed.
                    (new SessionStore())->setStatus($activeSession, SessionStatus::DROPPED);
                }
                $action = $options['supersede'] ? 'superseded' : 'revised';
            }
        } catch (Throwable $exception) {
            fwrite(STDERR, '[FAIL] workflow plan: ' . $exception->getMessage() . "\n");

            return 1;
        }

        echo "[OK] workflow plan: candidate Contract {$action} for {$taskId->value} revision {$contract->revision}\n";
        echo "[OK] workflow plan: durable source {$contract->path}\n";
        echo "Next:\n";
        echo '  agent-loop workflow approve ' . $taskId->value . ' --by ' . self::shellArgument($options['by']) . "\n";

        return 0;
    }

    private function activeSession(string $taskId): ?Session
    {
        $root = (new ProjectLayout($this->rootPath))->sessionsRoot();
        if (!is_dir($root)) {
            return null;
        }
        $matches = array_values(array_filter(
            (new SessionStore())->all($root),
            static fn (Session $session): bool => $session->taskId === $taskId && !$session->status->isClosed(),
        ));
        if (count($matches) > 1) {
            throw new RuntimeException('Multiple active Sessions exist for task ' . $taskId . '.');
        }

        return $matches[0] ?? null;
    }

    /**
     * Render one POSIX-shell argument without obscuring already-safe values.
     */
    private static function shellArgument(string $value): string
    {
        if (preg_match('~\A[A-Za-z0-9_@%+=:,./-]+\z~', $value) === 1) {
            return $value;
        }

        return "'" . str_replace("'", "'\"'\"'", $value) . "'";
    }

    /**
     * @param list<string> $tokens
     * @return array{by: string, files: list<string>, goal: string, scope: list<string>, nonGoals: list<string>, validation: list<string>, acceptanceCriteria: list<string>, acceptanceObservations: list<array{acceptance: string, validations: list<string>}>, tags: list<string>, behaviorAnchors: list<string>, operatingPromptManifest: string|null, operatingPrompts: list<array{id: string, arguments: array<string, bool|int|string>}>, baseCommit: string|null, supersede: bool}
     */
    private function parse(array $tokens): array
    {
        $by = null;
        $files = [];
        $goal = null;
        $scope = [];
        $nonGoals = [];
        $validation = [];
        $acceptanceCriteria = [];
        $acceptanceObservations = [];
        $tags = [];
        $behaviorAnchors = [];
        $operatingPromptManifest = null;
        $operatingPrompts = [];
        $operatingPromptIds = [];
        $baseCommit = null;
        $supersede = false;

        for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i];
            if ($token === '--supersede') {
                if ($supersede) {
                    throw new InvalidArgumentException('--supersede may be provided only once.');
                }
                $supersede = true;
                continue;
            }
            if (!in_array($token, ['--by', '--file', '--goal', '--scope', '--non-goal', '--validation', '--acceptance', '--acceptance-observation', '--tag', '--behavior-anchor', '--operating-prompt-manifest', '--operating-prompt', '--base-commit'], true)) {
                throw new InvalidArgumentException('Unknown option: ' . $token);
            }
            if (!isset($tokens[$i + 1]) || str_starts_with($tokens[$i + 1], '--')) {
                throw new InvalidArgumentException($token . ' requires a value.');
            }

            $value = trim($tokens[++$i]);
            if ($value === '') {
                throw new InvalidArgumentException($token . ' requires a non-empty value.');
            }

            switch ($token) {
                case '--by':
                    $by = $value;
                    break;
                case '--file':
                    $files[] = $value;
                    break;
                case '--goal':
                    $goal = $value;
                    break;
                case '--scope':
                    $scope[] = $value;
                    break;
                case '--non-goal':
                    $nonGoals[] = $value;
                    break;
                case '--validation':
                    $validation[] = $value;
                    break;
                case '--acceptance':
                    $acceptanceCriteria[] = $value;
                    break;
                case '--acceptance-observation':
                    $acceptanceObservations[] = $this->acceptanceObservation($value);
                    break;
                case '--tag':
                    $tags[] = $value;
                    break;
                case '--behavior-anchor':
                    $behaviorAnchors[] = $value;
                    break;
                case '--operating-prompt-manifest':
                    if ($operatingPromptManifest !== null) {
                        throw new InvalidArgumentException('--operating-prompt-manifest may be provided only once.');
                    }
                    $operatingPromptManifest = $value;
                    break;
                case '--operating-prompt':
                    $selection = $this->operatingPrompt($value);
                    if (isset($operatingPromptIds[$selection['id']])) {
                        throw new InvalidArgumentException('Operating prompt selected more than once: ' . $selection['id']);
                    }
                    $operatingPromptIds[$selection['id']] = true;
                    $operatingPrompts[] = $selection;
                    break;
                case '--base-commit':
                    $baseCommit = $value;
                    break;
            }
        }

        if ($by === null) {
            throw new InvalidArgumentException('--by is required.');
        }
        if ($files === []) {
            throw new InvalidArgumentException('--file is required.');
        }
        if ($goal === null) {
            throw new InvalidArgumentException('--goal is required.');
        }
        if ($validation === []) {
            throw new InvalidArgumentException('--validation is required.');
        }
        if ($operatingPrompts !== [] && $operatingPromptManifest === null) {
            throw new InvalidArgumentException('--operating-prompt requires --operating-prompt-manifest.');
        }
        if ($operatingPromptManifest !== null && $operatingPrompts === []) {
            throw new InvalidArgumentException('--operating-prompt-manifest requires at least one --operating-prompt.');
        }

        return [
            'by' => $by,
            'files' => $files,
            'goal' => $goal,
            'scope' => $this->normalizeScope($scope === [] ? $files : $scope),
            'nonGoals' => $nonGoals,
            'validation' => $validation,
            'acceptanceCriteria' => $acceptanceCriteria,
            'acceptanceObservations' => $acceptanceObservations,
            'tags' => $tags,
            'behaviorAnchors' => $behaviorAnchors,
            'operatingPromptManifest' => $operatingPromptManifest,
            'operatingPrompts' => $operatingPrompts,
            'baseCommit' => $baseCommit,
            'supersede' => $supersede,
        ];
    }

    /**
     * @param list<string> $scope
     * @return list<string>
     */
    private function normalizeScope(array $scope): array
    {
        $normalized = [];
        foreach ($scope as $path) {
            $normalized[] = self::normalizeScopePath($path);
        }

        return $normalized;
    }

    private static function normalizeScopePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }
        if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1) {
            throw new InvalidArgumentException('Workflow scope must be repository-relative: ' . $path);
        }
        if (str_ends_with($path, '/')) {
            $path = substr($path, 0, -1);
        }
        if ($path === '') {
            throw new InvalidArgumentException('Workflow scope must be repository-relative.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Workflow scope escapes or ambiguously addresses the repository: ' . $path);
            }
        }

        return $path;
    }

    /** @return array{acceptance: string, validations: list<string>} */
    private function acceptanceObservation(string $json): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Acceptance observation must be valid JSON: ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($data) || !is_string($data['acceptance'] ?? null) || !is_array($data['validations'] ?? null)) {
            throw new InvalidArgumentException('Acceptance observation requires string acceptance and array validations.');
        }
        $acceptance = trim($data['acceptance']);
        if ($acceptance === '') {
            throw new InvalidArgumentException('Acceptance observation requires a non-empty acceptance criterion.');
        }
        $validations = [];
        foreach ($data['validations'] as $validation) {
            if (!is_string($validation) || trim($validation) === '') {
                throw new InvalidArgumentException('Acceptance observation validations must contain non-empty strings.');
            }
            $validations[] = trim($validation);
        }
        $validations = array_values(array_unique($validations));
        if ($validations === []) {
            throw new InvalidArgumentException('Acceptance observation requires at least one validation command.');
        }

        return ['acceptance' => $acceptance, 'validations' => $validations];
    }

    /** @return array{id: string, arguments: array<string, bool|int|string>} */
    private function operatingPrompt(string $json): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Operating prompt selection must be valid JSON: ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($data) || !is_string($data['id'] ?? null) || !is_array($data['arguments'] ?? [])) {
            throw new InvalidArgumentException('Operating prompt selection requires string id and object arguments.');
        }
        $id = trim($data['id']);
        if ($id === '' || preg_match('/^[a-z][a-z0-9._-]*$/', $id) !== 1) {
            throw new InvalidArgumentException('Operating prompt id must match [a-z][a-z0-9._-]*.');
        }
        $arguments = [];
        foreach ($data['arguments'] ?? [] as $name => $argument) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1 || (!is_bool($argument) && !is_int($argument) && !is_string($argument))) {
                throw new InvalidArgumentException('Operating prompt arguments must use valid names and bool, int, or string values.');
            }
            $arguments[$name] = $argument;
        }
        ksort($arguments);

        return ['id' => $id, 'arguments' => $arguments];
    }
}
