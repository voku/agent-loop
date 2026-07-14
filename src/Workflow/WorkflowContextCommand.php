<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use JsonException;
use Throwable;
use voku\AgentLoop\RecallOutputRoot;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexReader;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStore;

/**
 * Read-only, budgeted task context assembled from existing workflow artifacts.
 * It intentionally never compiles recall, refreshes a map, or changes session
 * state: stale and missing inputs are reported as such instead of repaired.
 */
final readonly class WorkflowContextCommand
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
            $context = $this->build($taskId->value, $options['learningRoot'], $options['maxLines'], $options['maxBytes']);
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
     * @return array{format: 'text'|'json', learningRoot: string|null, maxLines: int, maxBytes: int}
     */
    private function parse(array $tokens): array
    {
        $format = 'text';
        $learningRoot = null;
        $maxLines = 120;
        $maxBytes = 12000;
        for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i];
            if (!in_array($token, ['--format', '--learning-root', '--max-lines', '--max-bytes'], true)) {
                throw new InvalidArgumentException('Unknown option: ' . $token);
            }
            if (!isset($tokens[$i + 1]) || str_starts_with($tokens[$i + 1], '--')) {
                throw new InvalidArgumentException($token . ' requires a value.');
            }
            $value = trim($tokens[++$i]);
            if ($value === '') {
                throw new InvalidArgumentException($token . ' requires a non-empty value.');
            }
            match ($token) {
                '--format' => $format = $value,
                '--learning-root' => $learningRoot = $value,
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
        return compact('format', 'learningRoot', 'maxLines', 'maxBytes');
    }

    /** @return array{schema_version: string, task_id: string, lines: list<string>, omitted: array<string, int>, skipped: list<string>} */
    public function build(string $taskId, ?string $learningRoot, int $maxLines, int $maxBytes): array
    {
        $report = (new WorkflowReportCommand($this->rootPath))->buildReport($taskId, $learningRoot);
        $budget = new WorkflowContextBudget($maxLines, $maxBytes);
        $budget->add('header', 'Task: ' . $taskId);
        $budget->add('header', 'Session: ' . ($report['session']['id'] ?? 'missing'));
        $brief = $report['work_brief'];
        if ($brief['status'] === 'missing') {
            $budget->add('brief', 'Work brief: missing');
        } else {
            $budget->add('brief', 'Work brief: revision ' . $brief['revision'] . ', ' . ($brief['approval']['by'] === null ? 'not approved' : 'approved by ' . $brief['approval']['by']));
            $budget->section('Goal');
            $budget->add('brief', '  ' . $brief['goal']);
            $budget->section('Approved scope');
            foreach ($brief['scope'] as $scope) {
                $budget->add('scope', '  ' . $scope);
            }
            if ($brief['non_goals'] !== []) {
                $budget->section('Non-goals');
                foreach ($brief['non_goals'] as $nonGoal) {
                    $budget->add('brief', '  ' . $nonGoal);
                }
            }
        }

        $session = $this->session($taskId, $report['session']['id'] ?? null);
        if ($session !== null) {
            $this->addSessionState($budget, $session);
        }
        $this->addRecall($budget, $taskId);
        $this->addMap($budget, $brief['scope']);
        $budget->section('Required validation');
        foreach ($report['validation'] as $validation) {
            $budget->add('validation', sprintf('  [%s] %s', $validation['status'], $validation['command']));
        }
        if ($report['validation'] === []) {
            $budget->add('validation', '  [SKIP] no work brief validation requirements');
        }
        $budget->finish();

        return [
            'schema_version' => '1.0',
            'task_id' => $taskId,
            'lines' => $budget->lines(),
            'omitted' => $budget->omitted(),
            'skipped' => $budget->skipped(),
        ];
    }

    private function session(string $taskId, mixed $id): ?Session
    {
        if (!is_string($id) || $id === '') {
            return null;
        }
        $root = rtrim($this->rootPath, '/') . '/session_plan';
        $session = (new SessionStore())->load($root, $id);

        return $session->taskId === $taskId ? $session : null;
    }

    private function addSessionState(WorkflowContextBudget $budget, Session $session): void
    {
        $budget->section('Session decisions and assumptions');
        foreach (['decisions.md' => 'decision', 'assumptions.md' => 'assumption'] as $file => $category) {
            $content = is_file($session->path . '/' . $file) ? (string) file_get_contents($session->path . '/' . $file) : '';
            foreach ($this->headings($content) as $heading) {
                $budget->add($category, '  ' . $heading . ' (' . $file . ')');
            }
        }
        $budget->section('Recent checkpoints');
        foreach (array_slice(array_reverse($session->checkpoints), 0, 5) as $checkpoint) {
            $budget->add('checkpoint', '  ' . $checkpoint['id'] . ' ' . $checkpoint['title']);
        }
    }

    /** @return list<string> */
    private function headings(string $content): array
    {
        preg_match_all('/^##\s+(?:Decision|Assumption):\s+(.+)$/mi', $content, $matches);

        return array_values(array_filter(array_map('trim', $matches[1]), static fn (string $heading): bool => $heading !== ''));
    }

    private function addRecall(WorkflowContextBudget $budget, string $taskId): void
    {
        $path = RecallOutputRoot::resolve($this->rootPath) . '/' . $taskId . '/meta.json';
        $relative = RecallOutputRoot::relativeTo($this->rootPath, $path);
        if (!is_file($path)) {
            $budget->skip('recall: missing ' . $relative);

            return;
        }
        try {
            $meta = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $budget->skip('recall: invalid ' . $relative);

            return;
        }
        if (!is_array($meta)) {
            $budget->skip('recall: invalid ' . $relative);

            return;
        }
        $budget->section('Selected guidance');
        foreach ($this->strings($meta['selected_guidance'] ?? []) as $id) {
            $budget->add('guidance', '  ' . $id . ' (' . $relative . ')');
        }
        foreach ($meta['selected_constraints'] ?? [] as $constraint) {
            if (is_array($constraint) && is_string($constraint['id'] ?? null)) {
                $budget->add('guidance', '  ' . $constraint['id'] . ' (' . $relative . ')');
            }
        }
    }

    /** @param list<string> $scope */
    private function addMap(WorkflowContextBudget $budget, array $scope): void
    {
        $indexPath = rtrim($this->rootPath, '/') . '/.agent-map/php-symbols.json';
        if (!is_file($indexPath)) {
            $budget->skip('agent-map: index missing (.agent-map/php-symbols.json)');

            return;
        }
        try {
            $index = (new IndexReader())->read($indexPath);
        } catch (Throwable) {
            $budget->skip('agent-map: index invalid (.agent-map/php-symbols.json)');

            return;
        }
        $budget->section('Relevant symbols');
        foreach ($scope as $path) {
            $file = $index->file($path);
            if ($file instanceof FileEntry) {
                $this->addFileSymbols($budget, $file);
            }
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

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && trim($item) !== '')) : [];
    }

    private function positive(string $value, string $option): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new InvalidArgumentException($option . ' requires a positive integer.');
        }

        return (int) $value;
    }
}
