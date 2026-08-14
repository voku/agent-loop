<?php

declare(strict_types=1);

namespace voku\AgentLoop\AgentGuidance;

use JsonException;
use RuntimeException;
use UnexpectedValueException;
use voku\AgentLoop\ProjectLayout;

final readonly class AgentDisciplineHook
{
    private const int MAX_INPUT_BYTES = 1_048_576;
    private const int MAX_CONTEXT_BYTES = 32_768;
    private const int MAX_CLAUDE_CONTEXT_BYTES = 9_500;
    private const int MAX_RUN_MANIFEST_BYTES = 131_072;
    private const int MAX_RESUME_HINTS = 5;

    /** @var list<string> */
    private const array RESUMABLE_MANIFEST_STATES = [
        'blocked',
        'incomplete',
        'ready_to_close',
    ];

    public function __construct(private string $repositoryRoot)
    {
    }

    /**
     * @return array{
     *   continue: true,
     *   suppressOutput: true,
     *   systemMessage: 'AGENT_LOOP_DISCIPLINE',
     *   hookSpecificOutput: array{hookEventName: 'SessionStart'|'SubagentStart', additionalContext: string}
     * }
     */
    public function contextOutput(string $event, string $rawPayload): array
    {
        return [
            'continue' => true,
            'suppressOutput' => true,
            'systemMessage' => 'AGENT_LOOP_DISCIPLINE',
            'hookSpecificOutput' => $this->contextHookSpecificOutput(
                $event,
                $rawPayload,
                self::MAX_CONTEXT_BYTES,
                '.codex',
            ),
        ];
    }

    /**
     * Claude Code renders `systemMessage` as a user-visible warning, so its
     * context hook deliberately omits the Codex marker while preserving the
     * same hidden discipline context.
     *
     * @return array{
     *   continue: true,
     *   suppressOutput: true,
     *   hookSpecificOutput: array{hookEventName: 'SessionStart'|'SubagentStart', additionalContext: string}
     * }
     */
    public function claudeContextOutput(string $event, string $rawPayload): array
    {
        return [
            'continue' => true,
            'suppressOutput' => true,
            'hookSpecificOutput' => $this->contextHookSpecificOutput(
                $event,
                $rawPayload,
                self::MAX_CLAUDE_CONTEXT_BYTES,
                '.claude',
            ),
        ];
    }

    /**
     * @return array{
     *   continue: true,
     *   hookSpecificOutput: array{
     *     hookEventName: 'PreToolUse',
     *     permissionDecision?: 'deny',
     *     permissionDecisionReason?: non-empty-string,
     *     additionalContext?: non-empty-string
     *   }
     * }
     */
    public function preToolUseOutput(string $rawPayload): array
    {
        $payload = $this->decodePayload($rawPayload);
        if (($payload['hook_event_name'] ?? null) !== 'PreToolUse') {
            throw new UnexpectedValueException('Expected hook_event_name PreToolUse.');
        }

        $command = $this->extractCommand($payload);
        if ($this->isUnboundedMapDump($command)) {
            return $this->deny(
                'Unbounded read of the generated agent-map index is blocked.',
                'Use agent-loop map query, related, file, changed, or stats. The index is navigation state, not prompt evidence.',
            );
        }

        return [
            'continue' => true,
            'hookSpecificOutput' => ['hookEventName' => 'PreToolUse'],
        ];
    }

    /**
     * @return array{hookEventName: 'SessionStart'|'SubagentStart', additionalContext: string}
     */
    private function contextHookSpecificOutput(
        string $event,
        string $rawPayload,
        int $maxContextBytes,
        string $clientDirectory,
    ): array {
        if (!in_array($event, ['SessionStart', 'SubagentStart'], true)) {
            throw new UnexpectedValueException('Unsupported context hook event: ' . $event);
        }

        $payload = $this->decodePayload($rawPayload);
        $payloadEvent = $payload['hook_event_name'] ?? null;
        if (!is_string($payloadEvent) || $payloadEvent !== $event) {
            throw new UnexpectedValueException(sprintf(
                'Expected hook_event_name %s, got %s.',
                $event,
                is_scalar($payloadEvent) ? (string) $payloadEvent : get_debug_type($payloadEvent),
            ));
        }

        $context = $this->workflowResumeHint() . $this->disciplineContext($clientDirectory);

        return [
            'hookEventName' => $event,
            'additionalContext' => $this->truncateUtf8ByBytes($context, $maxContextBytes),
        ];
    }

    /** @return array<string, mixed> */
    private function decodePayload(string $rawPayload): array
    {
        if ($rawPayload === '') {
            throw new UnexpectedValueException('Hook payload is empty.');
        }
        if (strlen($rawPayload) > self::MAX_INPUT_BYTES) {
            throw new UnexpectedValueException('Hook payload exceeds 1 MiB.');
        }

        try {
            $decoded = json_decode($rawPayload, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('Hook payload is not valid JSON.', 0, $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new UnexpectedValueException('Hook payload must be a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @param array<string, mixed> $payload */
    private function extractCommand(array $payload): string
    {
        $toolInput = $payload['tool_input'] ?? null;
        if (!is_array($toolInput)) {
            throw new UnexpectedValueException('PreToolUse payload misses tool_input.');
        }

        foreach (['command', 'cmd'] as $key) {
            $value = $toolInput[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        throw new UnexpectedValueException('PreToolUse Bash payload misses command text.');
    }

    private function disciplineContext(string $clientDirectory): string
    {
        foreach ([
            $this->repositoryRoot . '/' . $clientDirectory . '/skills/agent-loop-discipline/SKILL.md',
            $this->repositoryRoot . '/docs/agents/skills/agent-loop-discipline/SKILL.md',
        ] as $candidate) {
            if (!is_file($candidate) || !is_readable($candidate)) {
                continue;
            }

            $content = file_get_contents($candidate);
            if (!is_string($content) || trim($content) === '') {
                continue;
            }

            return $this->stripFrontmatter($content);
        }

        return <<<'TEXT'
        Agent Loop discipline:
        - Keep human-facing progress concise and factual.
        - Use agent-map before broad PHP reads.
        - Choose the smallest correct change in the owning package.
        - Preserve full diffs, source, tests, and verification artifacts unchanged.
        - Hooks are behavioral guardrails, never correctness or security boundaries.
        - Never claim validation that was not executed.
        TEXT;
    }

    private function workflowResumeHint(): string
    {
        $runsRoot = (new ProjectLayout($this->repositoryRoot))->runsRoot();
        if (!is_dir($runsRoot)) {
            return '';
        }

        $manifestPaths = glob($runsRoot . '/*/manifest.json');
        if (!is_array($manifestPaths) || $manifestPaths === []) {
            return '';
        }
        sort($manifestPaths, SORT_STRING);

        /** @var list<array{task_id: non-empty-string, state: non-empty-string}> $unfinished */
        $unfinished = [];
        $omitted = 0;

        foreach ($manifestPaths as $manifestPath) {
            $hint = $this->readResumeManifest($manifestPath);
            if ($hint === null) {
                continue;
            }
            if (count($unfinished) >= self::MAX_RESUME_HINTS) {
                ++$omitted;
                continue;
            }
            $unfinished[] = $hint;
        }

        if ($unfinished === []) {
            return '';
        }

        $lines = [
            '## Agent Loop Resume Hint',
            '',
            'Derived run manifests are navigation only, not workflow authority.',
        ];
        foreach ($unfinished as $hint) {
            $lines[] = sprintf('- observed task: `%s`; projected state: `%s`.', $hint['task_id'], $hint['state']);
        }
        if ($omitted > 0) {
            $lines[] = sprintf('- %d additional unfinished run manifest(s) omitted from bootstrap context.', $omitted);
        }

        if (count($unfinished) === 1) {
            $taskId = $unfinished[0]['task_id'];
            $lines[] = sprintf(
                '- before any governed mutation: `vendor/bin/agent-loop workflow status %s --format=json`.',
                $taskId,
            );
        } else {
            $lines[] = '- multiple unfinished tasks exist. Resolve the task from the current request/repository context; do not guess.';
            $lines[] = '- before any governed mutation, run `vendor/bin/agent-loop workflow status <task-id> --format=json` for the resolved task.';
        }

        $lines[] = '- do not infer approval, validation, review, learning, intent, or a next command from this hint.';
        $lines[] = '';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /** @return array{task_id: non-empty-string, state: non-empty-string}|null */
    private function readResumeManifest(string $manifestPath): ?array
    {
        if (is_link($manifestPath) || !is_file($manifestPath) || !is_readable($manifestPath)) {
            return null;
        }

        $content = file_get_contents($manifestPath, false, null, 0, self::MAX_RUN_MANIFEST_BYTES + 1);
        if (!is_string($content) || $content === '' || strlen($content) > self::MAX_RUN_MANIFEST_BYTES) {
            return null;
        }

        try {
            $manifest = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($manifest) || array_is_list($manifest)) {
            return null;
        }

        $taskId = $manifest['task_id'] ?? null;
        $state = $manifest['state'] ?? null;
        if (!is_string($taskId) || $taskId === '' || !$this->isSafeTaskId($taskId)) {
            return null;
        }
        if (basename(dirname($manifestPath)) !== $taskId) {
            return null;
        }
        if (!is_string($state) || !in_array($state, self::RESUMABLE_MANIFEST_STATES, true)) {
            return null;
        }

        return ['task_id' => $taskId, 'state' => $state];
    }

    private function isSafeTaskId(string $taskId): bool
    {
        return strlen($taskId) <= 128
            && !str_contains($taskId, '..')
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $taskId) === 1;
    }

    private function stripFrontmatter(string $content): string
    {
        return preg_replace('/\A---\R.*?\R---\R/s', '', $content, 1) ?? $content;
    }

    private function truncateUtf8ByBytes(string $context, int $maxBytes): string
    {
        if (preg_match('//u', $context) !== 1) {
            throw new UnexpectedValueException('Agent discipline context must be valid UTF-8.');
        }
        if (strlen($context) <= $maxBytes) {
            return $context;
        }

        $truncated = substr($context, 0, $maxBytes);
        while (preg_match('//u', $truncated) !== 1) {
            // The original context is valid UTF-8, so at most three trailing
            // bytes can belong to the code point cut by the byte limit.
            $truncated = substr($truncated, 0, -1);
        }

        return $truncated;
    }

    private function isUnboundedMapDump(string $command): bool
    {
        $layout = new ProjectLayout($this->repositoryRoot);
        foreach ([$layout->mapIndex(), $layout->mapSearchIndex()] as $mapArtifact) {
            $path = preg_quote($layout->display($mapArtifact), '~');
            if (preg_match('~(?:^|[;&|]\s*)(?:cat|less|more|jq|sqlite3)\b[^;&|]*' . $path . '(?:\s|$)~i', $command) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *   continue: true,
     *   hookSpecificOutput: array{
     *     hookEventName: 'PreToolUse',
     *     permissionDecision: 'deny',
     *     permissionDecisionReason: non-empty-string,
     *     additionalContext: non-empty-string
     *   }
     * }
     */
    private function deny(string $reason, string $context): array
    {
        if ($reason === '' || $context === '') {
            throw new RuntimeException('Hook denial requires a reason and replacement guidance.');
        }

        return [
            'continue' => true,
            'hookSpecificOutput' => [
                'hookEventName' => 'PreToolUse',
                'permissionDecision' => 'deny',
                'permissionDecisionReason' => $reason,
                'additionalContext' => $context,
            ],
        ];
    }
}
