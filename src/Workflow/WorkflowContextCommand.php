<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use voku\AgentLoop\FutureWorkMode;
use voku\AgentLoop\HumanExplanationPolicy;
use voku\AgentLoop\Init\InitConfigLoader;
use voku\AgentLoop\PathResolver;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\RecallOutputRoot;
use voku\AgentLoop\Workflow\Transparency\ContextCoverage;
use voku\AgentLoop\Workflow\Transparency\ContextFutureWorkPolicy;
use voku\AgentLoop\Workflow\Transparency\ContextInteractionPolicy;
use voku\AgentLoop\Workflow\Transparency\ContextOmission;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexReader;
use voku\AgentRecallCompiler\Output\CompiledRecallOutputReader;
use voku\AgentSession\Session;
use voku\AgentSession\SessionHandoffProjector;
use voku\AgentSession\SessionStore;

/**
 * Read-only, budgeted task context assembled from existing owner artifacts.
 * It never compiles Recall, refreshes a map, or changes Session state: stale
 * and missing inputs are reported instead of silently repaired.
 */
final readonly class WorkflowContextCommand
{
    public const int DEFAULT_MAX_LINES = 120;
    public const int DEFAULT_MAX_BYTES = 12000;

    public function __construct(private string $rootPath)
    {
    }

    /**
     * The same context construction as `build()`, projected as typed facts.
     *
     * This deliberately wraps `build()` rather than reimplementing it: skipped
     * inputs and budget omissions are produced by assembling the context, so a
     * second assembler would be a second answer that drifts. Only the already
     * shape-typed keys are read — never the rendered `lines`, which is a
     * display format and has never been an API.
     */
    public function coverage(
        string $taskId,
        int $maxLines = self::DEFAULT_MAX_LINES,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
    ): ContextCoverage {
        $context = $this->build($taskId, $maxLines, $maxBytes);

        $omitted = [];
        foreach ($context['omitted'] as $category => $count) {
            $omitted[] = new ContextOmission($category, $count);
        }
        // Deterministic order: the source is a category-keyed map, whose
        // iteration order is insertion order and therefore budget-dependent.
        usort($omitted, static fn (ContextOmission $left, ContextOmission $right): int => $left->category <=> $right->category);

        return new ContextCoverage(
            skipped: $context['skipped'],
            omitted: $omitted,
            interaction: new ContextInteractionPolicy(
                HumanExplanationPolicy::from($context['interaction']['human_explanations']),
            ),
            futureWork: new ContextFutureWorkPolicy(
                FutureWorkMode::from($context['future_work']['mode']),
                $context['future_work']['max_follow_up_slices'],
            ),
        );
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        try {
            $taskId = new WorkflowTaskId($args[0] ?? '');
            $options = $this->parse(array_slice($args, 1));
            $context = $this->build($taskId->value, $options['maxLines'], $options['maxBytes']);
            if ($options['format'] === 'json') {
                echo json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            } else {
                echo implode("\n", $context['lines']) . "\n";
            }

            return 0;
        } catch (InvalidArgumentException $exception) {
            fwrite(STDERR, '[FAIL] workflow context: ' . $exception->getMessage() . "\n");

            return 1;
        } catch (Throwable $exception) {
            fwrite(STDERR, '[FAIL] workflow context: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @param list<string> $tokens
     * @return array{format: 'text'|'json', maxLines: int, maxBytes: int}
     */
    private function parse(array $tokens): array
    {
        $format = 'text';
        $maxLines = self::DEFAULT_MAX_LINES;
        $maxBytes = self::DEFAULT_MAX_BYTES;
        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if (!in_array($token, ['--format', '--max-lines', '--max-bytes'], true)) {
                throw new InvalidArgumentException('Unknown option: ' . $token);
            }
            if (!isset($tokens[$index + 1]) || str_starts_with($tokens[$index + 1], '--')) {
                throw new InvalidArgumentException($token . ' requires a value.');
            }
            $value = trim($tokens[++$index]);
            if ($value === '') {
                throw new InvalidArgumentException($token . ' requires a non-empty value.');
            }
            match ($token) {
                '--format' => $format = $value,
                '--max-lines' => $maxLines = $this->positive($value, '--max-lines'),
                '--max-bytes' => $maxBytes = $this->positive($value, '--max-bytes'),
            };
        }
        if (!in_array($format, ['text', 'json'], true)) {
            throw new InvalidArgumentException('--format must be text or json.');
        }
        if ($maxLines < 12 || $maxBytes < 512) {
            throw new InvalidArgumentException('Context budgets require at least --max-lines=12 and --max-bytes=512 so omissions remain visible.');
        }

        /** @var 'text'|'json' $format */
        return compact('format', 'maxLines', 'maxBytes');
    }

    /**
     * @return array{
     *     schema_version: string,
     *     task_id: string,
     *     interaction: array{
     *         human_explanations: 'ask'|'always'|'never',
     *         interactive_behavior: 'ask'|'generate'|'skip',
     *         unattended_behavior: 'generate'|'skip',
     *         authority_bearing_decisions: 'human_required'
     *     },
     *     future_work: array{
     *         mode: 'focus'|'discover'|'invest',
     *         max_follow_up_slices: int,
     *         current_contract_scope_expansion: 'forbidden',
     *         follow_up_authority: 'separate_contract_required'
     *     },
     *     lines: list<string>,
     *     omitted: array<string, int>,
     *     skipped: list<string>
     * }
     */
    public function build(string $taskId, int $maxLines, int $maxBytes): array
    {
        $report = (new WorkflowReportCommand($this->rootPath))->buildReport($taskId);
        $repositoryPolicy = $this->repositoryPolicy();
        $policy = $repositoryPolicy['interaction'];
        $futureWorkMode = $repositoryPolicy['futureWorkMode'];
        $maxFollowUpSlices = $repositoryPolicy['maxFollowUpSlices'];

        $budget = new WorkflowContextBudget($maxLines, $maxBytes);
        $budget->add('header', 'Task: ' . $taskId);
        $budget->add('header', 'Run: ' . ($report['run_id'] ?? 'missing'));
        $budget->add('header', 'Session: ' . ($report['session']['id'] ?? 'missing'));
        foreach ($repositoryPolicy['warnings'] as $warning) {
            $budget->add('policy', $warning);
        }
        $budget->add(
            'policy',
            'Human explanations: ' . $policy->value
            . ' (interactive: ' . $policy->interactiveBehavior()
            . '; unattended: ' . $policy->unattendedBehavior()
            . '). Optional model-generated explanation work only; deterministic projections stay available; human authority remains required.',
        );
        $budget->add('policy', $this->futureWorkLine($futureWorkMode, $maxFollowUpSlices));

        $contract = $report['contract'];
        if ($contract['status'] === 'missing') {
            $budget->add('contract', 'Contract: missing');
        } else {
            $budget->add(
                'contract',
                'Contract: revision ' . $contract['revision'] . ', '
                . ($contract['approval']['by'] === null ? 'not approved' : 'approved by ' . $contract['approval']['by']),
            );
            $budget->section('Goal');
            $budget->add('contract', '  ' . $contract['goal']);
            $budget->section('Approved scope');
            foreach ($contract['scope'] as $scope) {
                $budget->add('scope', '  ' . $scope);
            }
            if ($contract['non_goals'] !== []) {
                $budget->section('Non-goals');
                foreach ($contract['non_goals'] as $nonGoal) {
                    $budget->add('contract', '  ' . $nonGoal);
                }
            }
            if ($contract['acceptance_criteria'] !== []) {
                $budget->section('Acceptance criteria (required, not proof)');
                foreach ($contract['acceptance_criteria'] as $criterion) {
                    $budget->add('acceptance', '  ' . $criterion);
                }
            }
        }

        $session = $this->session($taskId, $report['session']['id'] ?? null);
        if ($session !== null) {
            $this->addSessionState($budget, $session);
        }
        $hasBundleNavigation = $this->addRecall($budget, $taskId);
        if (!$hasBundleNavigation) {
            $this->addMap($budget, $this->stringList($contract['scope'] ?? null));
        }
        $budget->section('Required validation');
        foreach ($report['validation'] as $validation) {
            $budget->add('validation', sprintf(
                '  [%s] %s (Contract revision %d, source %s)',
                $validation['status'],
                $validation['command'],
                $validation['contract_revision'],
                $validation['source'],
            ));
        }
        if ($report['validation'] === []) {
            $budget->add('validation', '  [SKIP] no Contract validation requirements');
        }
        $budget->finish();

        return [
            'schema_version' => '2.0',
            'task_id' => $taskId,
            'interaction' => [
                'human_explanations' => $policy->value,
                'interactive_behavior' => $policy->interactiveBehavior(),
                'unattended_behavior' => $policy->unattendedBehavior(),
                'authority_bearing_decisions' => 'human_required',
            ],
            'future_work' => [
                'mode' => $futureWorkMode->value,
                'max_follow_up_slices' => $maxFollowUpSlices,
                'current_contract_scope_expansion' => 'forbidden',
                'follow_up_authority' => 'separate_contract_required',
            ],
            'lines' => $budget->lines(),
            'omitted' => $budget->omitted(),
            'skipped' => $budget->skipped(),
        ];
    }

    /**
     * @return array{
     *     interaction: HumanExplanationPolicy,
     *     futureWorkMode: FutureWorkMode,
     *     maxFollowUpSlices: int,
     *     warnings: list<string>
     * }
     */
    private function repositoryPolicy(): array
    {
        $layout = new ProjectLayout($this->rootPath);
        $config = (new InitConfigLoader($this->rootPath))->load($layout->configPath());
        $warnings = array_values(array_filter(
            $config['warnings'],
            static fn (string $warning): bool => $warning === '[WARN] init config: invalid JSON'
                || str_starts_with($warning, '[WARN] init config: interaction')
                || str_starts_with($warning, '[WARN] init config: workflow'),
        ));

        return [
            'interaction' => HumanExplanationPolicy::from($config['interaction']['human_explanations']),
            'futureWorkMode' => FutureWorkMode::from($config['workflow']['future_work']['mode']),
            'maxFollowUpSlices' => $config['workflow']['future_work']['max_follow_up_slices'],
            'warnings' => $warnings,
        ];
    }

    private function futureWorkLine(FutureWorkMode $mode, int $maxFollowUpSlices): string
    {
        $behavior = match ($mode) {
            FutureWorkMode::FOCUS => 'do not proactively inspect adjacent future work after the current task completes',
            FutureWorkMode::DISCOVER => 'after the current task completes, permit one bounded future-work reflection but do not prepare or execute follow-up work',
            FutureWorkMode::INVEST => 'after the current task completes, permit bounded future-work reflection and preparation of up to ' . $maxFollowUpSlices . ' separate follow-up candidate slice(s)',
        };

        return 'Future work: ' . $mode->value . '; ' . $behavior
            . '. Never widen the current Contract; follow-up execution requires separate Contract authority.';
    }

    private function session(string $taskId, mixed $id): ?Session
    {
        if (!is_string($id) || $id === '') {
            return null;
        }
        $root = (new ProjectLayout($this->rootPath))->sessionsRoot();
        $session = null;
        try {
            $session = (new SessionStore())->load($root, $id);
        } catch (Throwable) {
            $session = null;
        }
        if ($session === null) {
            return null;
        }

        return $session->taskId === $taskId ? $session : null;
    }

    private function addSessionState(WorkflowContextBudget $budget, Session $session): void
    {
        $handoff = (new SessionHandoffProjector())->project($session);

        $budget->section('Session decisions and assumptions');
        foreach ($handoff->decisions as $decision) {
            $budget->add('decision', '  ' . $decision . ' (Session handoff)');
        }
        foreach ($handoff->assumptions as $assumption) {
            $budget->add('assumption', '  ' . $assumption . ' (Session handoff)');
        }
        $budget->section('Recent checkpoints');
        foreach (array_slice(array_reverse($handoff->checkpoints), 0, 5) as $checkpoint) {
            $budget->add('checkpoint', '  ' . $checkpoint['id'] . ' ' . $checkpoint['title']);
        }
    }

    /**
     * @return bool true when the compiled bundle supplied navigation facts or
     *              an explicit navigation status. Otherwise the current map is
     *              read directly without mutating or rebuilding it.
     */
    private function addRecall(WorkflowContextBudget $budget, string $taskId): bool
    {
        $directory = RecallOutputRoot::resolve($this->rootPath) . '/' . $taskId;
        $reader = new CompiledRecallOutputReader();
        $relative = RecallOutputRoot::relativeTo($this->rootPath, $reader->identityPath($directory));
        $output = null;
        $invalid = false;
        try {
            $output = $reader->read($directory);
        } catch (RuntimeException) {
            $invalid = true;
        }
        if ($invalid) {
            $budget->skip('recall: invalid ' . $relative);

            return false;
        }
        if ($output === null) {
            $budget->skip('recall: missing ' . $relative);

            return false;
        }

        $budget->section('Selected guidance');
        foreach ([...$output->selectedGuidance(), ...$output->selectedConstraints()] as $id) {
            // The owner drops empty ids; a whitespace-only one would still
            // reach the context as a blank guidance line.
            if (trim($id) === '') {
                continue;
            }
            $budget->add('guidance', '  ' . $id . ' (' . $relative . ')');
        }

        if (!$output->hasFacts()) {
            return false;
        }
        if (!$output->areFactsReadable()) {
            // Compiled facts exist but cannot be read. Returning true keeps the
            // caller from silently falling back to the live map, which would
            // present current navigation as though the bundle had supplied it.
            $budget->skip('recall: invalid compiled facts in ' . RecallOutputRoot::relativeTo($this->rootPath, $directory));

            return true;
        }

        $handledNavigation = false;
        $addedCoordination = false;
        foreach ($output->facts() as $fact) {
            if ($fact->type === 'kanban') {
                $card = is_array($fact->payload['card'] ?? null) ? $fact->payload['card'] : [];
                if (!$addedCoordination) {
                    $budget->section('Task coordination');
                    $addedCoordination = true;
                }
                $source = $fact->sourceRef ?? 'unknown board source';
                $title = is_string($card['title'] ?? null) ? trim($card['title']) : '';
                $lane = is_string($card['lane'] ?? null) ? trim($card['lane']) : '';
                $status = is_string($card['status'] ?? null) ? trim($card['status']) : '';
                $budget->add('coordination', '  ' . ($title === '' ? $source : $title) . ' (' . trim(implode(' / ', array_filter([$lane, $status]))) . ')');
                if (is_string($card['next_action'] ?? null) && trim($card['next_action']) !== '') {
                    $budget->add('coordination', '  Next: ' . trim($card['next_action']));
                }

                continue;
            }
            if ($fact->type === 'navigation_candidates') {
                (new WorkflowRankedMapContextExpander($this->rootPath))->add($budget, $fact);
                continue;
            }
            if ($fact->type === 'navigation_status') {
                $scope = $fact->scope === [] ? 'unknown' : implode(', ', $fact->scope);
                $status = is_string($fact->payload['status'] ?? null) ? $fact->payload['status'] : 'unavailable';
                $budget->skip('agent-map: ' . $status . ' for ' . $scope . ' (recorded in recall bundle)');
                $handledNavigation = true;
                continue;
            }
            if ($fact->type !== 'navigation') {
                continue;
            }
            $handledNavigation = true;
            $budget->section('Relevant symbols');
            $file = is_string($fact->payload['path'] ?? null) ? $fact->payload['path'] : 'unknown';
            foreach ($fact->payload['symbols'] ?? [] as $symbol) {
                if (!is_array($symbol) || !is_string($symbol['fqn'] ?? null)) {
                    continue;
                }
                $line = is_int($symbol['line_start'] ?? null) ? $symbol['line_start'] : 0;
                $budget->add('symbol', '  ' . $symbol['fqn'] . ' — ' . $file . ':' . $line);
            }
        }

        return $handledNavigation;
    }

    /** @param list<string> $scope */
    private function addMap(WorkflowContextBudget $budget, array $scope): void
    {
        $indexPath = (new ProjectLayout($this->rootPath))->mapIndex();
        $relativeIndex = PathResolver::relativeTo($this->rootPath, $indexPath);
        if (!is_file($indexPath)) {
            $budget->skip('agent-map: index missing (' . $relativeIndex . ')');

            return;
        }
        try {
            $index = (new IndexReader())->read($indexPath);
        } catch (Throwable) {
            $budget->skip('agent-map: index invalid (' . $relativeIndex . ')');

            return;
        }
        $budget->section('Relevant symbols');
        foreach ($scope as $path) {
            $file = $index->file($path);
            if ($file instanceof FileEntry) {
                $this->addFileSymbols($budget, $file);

                continue;
            }

            $prefix = rtrim($path, '/') . '/';
            $matches = array_filter($index->files, static fn (FileEntry $entry): bool => str_starts_with($entry->path, $prefix));
            if ($matches !== []) {
                foreach ($matches as $match) {
                    $this->addFileSymbols($budget, $match);
                }

                continue;
            }

            $budget->skip("agent-map: scope entry '{$path}' matched no file in the index (check the path, or that {$relativeIndex} is up to date)");
        }
    }

    private function addFileSymbols(WorkflowContextBudget $budget, FileEntry $file): void
    {
        foreach ($file->symbols as $symbol) {
            $budget->add('symbol', '  ' . $symbol->fqn . ' — ' . $file->path . ':' . $symbol->lineStart);
            foreach ($symbol->methods as $method) {
                $budget->add('symbol', '    ' . $symbol->name . '::' . $method->name . '() — ' . $file->path . ':' . $method->lineStart);
            }
        }
    }

    private function positive(string $value, string $option): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new InvalidArgumentException($option . ' requires a positive integer.');
        }

        return (int) $value;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item)));
    }
}
