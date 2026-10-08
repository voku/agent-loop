<?php

declare(strict_types=1);

namespace voku\AgentLoop\AgentGuidance;

use JsonException;
use RuntimeException;
use UnexpectedValueException;
use Throwable;
use voku\AgentLearning\FindingStatus;
use voku\AgentLearning\LearningCatalog;
use voku\AgentLoop\PackageResources;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Workflow\WorkflowDreamAutoRun;

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
                'Unbounded read of generated agent-map state is blocked.',
                'Use agent-loop map query, related, file, changed, or stats. Generated map state is navigation state, not prompt evidence.',
            );
        }
        if ($this->isLegacyRepositorySearch($command)) {
            return $this->deny(
                'Legacy repository search command is blocked.',
                'Use rg for literal or file discovery. For PHP symbols, callers, tests, and source scope, use agent-loop map query, related, scope, or context first.',
            );
        }
        if ($this->isInPlaceSedEdit($command)) {
            return $this->deny(
                'In-place sed edits are blocked.',
                'Use agent-loop edit for an exact deterministic PHP replacement, or apply the verified patch through the host edit tool. Keep bounded sed reads only for already-selected non-map source.',
            );
        }

        return [
            'continue' => true,
            'hookSpecificOutput' => ['hookEventName' => 'PreToolUse'],
        ];
    }

    /**
     * Claude's hard permission rules intentionally cover canonical direct
     * publication forms. This optional hook adds bounded defense-in-depth for
     * a few known alternate shell spellings without widening that security claim.
     *
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
    public function claudePreToolUseOutput(string $rawPayload): array
    {
        $payload = $this->decodePayload($rawPayload);
        if (($payload['hook_event_name'] ?? null) !== 'PreToolUse') {
            throw new UnexpectedValueException('Expected hook_event_name PreToolUse.');
        }

        $command = $this->extractCommand($payload);
        if ($this->isAlternateRemotePublicationCommand($command)) {
            return $this->deny(
                'Alternate remote publication command is blocked by the Claude workflow guardrail.',
                'Use the canonical direct publication path only after explicit human authority. Claude permission deny rules remain the hard boundary for the direct command forms; this hook is defense in depth and does not claim universal shell or MCP coverage.',
            );
        }

        return $this->preToolUseOutput($rawPayload);
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

        $context = $this->workflowResumeHint()
            . $this->learningBacklogHint()
            . ($event === 'SessionStart' ? $this->dreamHint() : '')
            . $this->disciplineContext($clientDirectory);

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
            $this->repositoryRoot . '/' . PackageResources::SKILLS . '/agent-loop-discipline/SKILL.md',
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

    /**
     * Surface Learning-owned Finding attention without reconstructing lifecycle semantics.
     *
     * Learning decides which Findings deserve attention and projects their status.
     * Loop only renders that owner truth as a non-blocking observation.
     */
    private function learningBacklogHint(): string
    {
        if (!class_exists(LearningCatalog::class)) {
            return '';
        }

        $root = (new ProjectLayout($this->repositoryRoot))->learningRoot();

        try {
            $catalog = new LearningCatalog($root);
            $candidates = [];
            $unconsolidated = [];
            // One batch call: LearningCatalog::finding() validates the whole Learning root on every call,
            // so looking each attention id up separately cost about 0.2s per finding on a mature root.
            $findingsById = [];
            foreach ($catalog->findings() as $projection) {
                $findingsById[$projection->id] = $projection;
            }
            foreach ($catalog->overview()->findingAttentionIds as $findingId) {
                $finding = $findingsById[$findingId] ?? null;
                if ($finding === null) {
                    throw new RuntimeException('Learning owner projected missing Finding: ' . $findingId);
                }
                if ($finding->status === FindingStatus::CANDIDATE->value) {
                    $candidates[] = $finding;

                    continue;
                }
                if ($finding->status !== FindingStatus::VALIDATED->value) {
                    throw new RuntimeException(sprintf(
                        'Learning owner projected unsupported attention status %s for %s.',
                        $finding->status,
                        $findingId,
                    ));
                }
                $unconsolidated[] = $finding;
            }
        } catch (Throwable $exception) {
            // The owner could not produce a consistent attention projection.
            // Reporting that is the point of this hint; silently omitting it
            // would hide exactly the kind of state this observation exists to
            // surface. Bootstrap context still must not fail, so it is reported.
            return $this->learningObservation([
                '- the Learning owner could not project its attention state: ' . $exception->getMessage(),
                '- `vendor/bin/agent-loop learn validate` reports this authoritatively.',
            ]);
        }
        if ($candidates === [] && $unconsolidated === []) {
            return '';
        }

        $lines = [];
        if ($candidates !== []) {
            $lines[] = sprintf(
                '- observed: %d candidate finding(s) require human/reviewer attention.',
                count($candidates),
            );
        }
        if ($unconsolidated !== []) {
            $lines[] = sprintf(
                '- observed: %d validated finding(s) need downstream Learning handling.',
                count($unconsolidated),
            );
            $lines[] = '- `vendor/bin/agent-loop learn backlog` lists validated downstream work; consolidation stays an explicit decision.';
        }

        return $this->learningObservation($lines);
    }

    /** @param list<string> $lines */
    private function learningObservation(array $lines): string
    {
        return implode("\n", [
            '## Agent Loop Learning Backlog',
            '',
            ...$lines,
            '- this is an observation, not a blocker, and not a next command.',
            '',
            '',
        ]);
    }

    /**
     * Run the read-only Dream preview when it is due and report its numbers.
     *
     * Whether Dream is due is a fact check in WorkflowDreamAutoRun, so this does not rely on
     * an agent remembering to run it. The preview writes nothing into the Learning root;
     * candidates and every approval stay explicit human decisions.
     */
    private function dreamHint(): string
    {
        try {
            $digest = (new WorkflowDreamAutoRun($this->repositoryRoot))->runIfDue();
        } catch (Throwable $exception) {
            // A failing Dream must not break bootstrap, and hiding it would defeat the point.
            return implode("\n", [
                '## Agent Loop Dream',
                '',
                '- the automatic Dream preview failed: ' . $exception->getMessage(),
                '- `vendor/bin/agent-loop learn dream --dry-run` reproduces it.',
                '',
                '',
            ]);
        }
        if ($digest === null) {
            return '';
        }

        $lines = [];
        if ($digest['ran']) {
            $lines[] = sprintf(
                '- ran the read-only Dream preview automatically (%s): %d guidance evaluated, %d warning kind(s)%s, %d review decision(s), %d suppressed.',
                $digest['reason'],
                $digest['evaluated'],
                count($digest['warnings']),
                $digest['warnings'] === [] ? '' : ' [' . implode(', ', $digest['warnings']) . ']',
                $digest['reviewDecisions'],
                $digest['suppressedDecisions'],
            );
        }
        if ($digest['reviewDecisions'] > 0) {
            $lines[] = sprintf(
                '- %d Dream review decision(s) are candidates, not decisions: `vendor/bin/agent-loop learn dream --dry-run` shows them, a named human decides.',
                $digest['reviewDecisions'],
            );
        }

        return $lines === []
            ? ''
            : implode("\n", [
                '## Agent Loop Dream',
                '',
                ...$lines,
                '- the preview wrote nothing into the Learning root; this is an observation, not a blocker, and not a next command.',
                '',
                '',
            ]);
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
                '- before any governed mutation: `vendor/bin/agent-loop workflow status %s --format=toon`.',
                $taskId,
            );
        } else {
            $lines[] = '- multiple unfinished tasks exist. Resolve the task from the current request/repository context; do not guess.';
            $lines[] = '- before any governed mutation, run `vendor/bin/agent-loop workflow status <task-id> --format=toon` for the resolved task.';
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
        if (!json_validate($content, 32)) {
            return null;
        }

        $manifest = json_decode($content, true, 32);
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
        $mapRoot = preg_quote(rtrim($layout->display($layout->mapRoot()), '/') . '/', '~');

        return preg_match(
            '~(?:^|[;&|]\s*)(?:cat|less|more|jq|sqlite3)\b[^;&|]*' . $mapRoot . '[^;&|]*(?:\s|$)~i',
            $command,
        ) === 1;
    }

    private function isLegacyRepositorySearch(string $command): bool
    {
        return preg_match('~(?:^|[;&|]\s*)(?:grep|find)\b~i', $this->withoutQuotedText($command)) === 1;
    }

    private function isInPlaceSedEdit(string $command): bool
    {
        return preg_match('~(?:^|[;&|]\s*)sed\b[^;&|]*(?:\s-i(?:\s|$)|\s--in-place(?:=\S+|\s|$))~i', $this->withoutQuotedText($command)) === 1;
    }

    /**
     * A tool name inside a quoted argument (an rg alternation such as "a|find", an echo) is text, not a command:
     * boundary characters in it must not start a new command for the patterns above.
     */
    private function withoutQuotedText(string $command): string
    {
        return preg_replace('~"(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'~s', '""', $command) ?? $command;
    }

    private function isAlternateRemotePublicationCommand(string $command): bool
    {
        if (preg_match('~^\\s*(?:git\\s+push|gh\\s+pr\\s+(?:create|merge))(?:\\s|$)~i', $command) === 1) {
            return false;
        }

        $boundary = '(?:^|(?:&&|\\|\\||[;&|\\r\\n])\\s*)';
        $wrapper = '(?:(?:sudo|env(?:\\s+[A-Za-z_][A-Za-z0-9_]*=\\S+)*)\\s+)?(?:\\S*/)?';

        return preg_match(
            '~' . $boundary . $wrapper . 'git(?:\\s+(?:-C\\s+\\S+|-c\\s+\\S+))*\\s+push(?:\\s|$)~i',
            $command,
        ) === 1
            || preg_match(
                '~' . $boundary . $wrapper . 'gh\\s+pr\\s+(?:create|merge)(?:\\s|$)~i',
                $command,
            ) === 1;
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
