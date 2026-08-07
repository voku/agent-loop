<?php

declare(strict_types=1);

namespace voku\AgentLoop\AgentGuidance;

use JsonException;
use RuntimeException;
use UnexpectedValueException;

final readonly class AgentDisciplineHook
{
    private const int MAX_INPUT_BYTES = 1_048_576;
    private const int MAX_CONTEXT_BYTES = 32_768;

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

        return [
            'continue' => true,
            'suppressOutput' => true,
            'systemMessage' => 'AGENT_LOOP_DISCIPLINE',
            'hookSpecificOutput' => [
                'hookEventName' => $event,
                'additionalContext' => $this->disciplineContext(),
            ],
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
        if ($this->isExternalAddonBootstrap($command)) {
            return $this->deny(
                'External agent add-on bootstrap is disabled for this repository.',
                'Use package-owned assets: agent-loop init install-assets --agent=<agent>.',
            );
        }

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

    private function disciplineContext(): string
    {
        foreach ([
            $this->repositoryRoot . '/.codex/skills/agent-loop-discipline/SKILL.md',
            $this->repositoryRoot . '/docs/agents/skills/agent-loop-discipline/SKILL.md',
        ] as $candidate) {
            if (!is_file($candidate) || !is_readable($candidate)) {
                continue;
            }

            $content = file_get_contents($candidate);
            if (!is_string($content) || trim($content) === '') {
                continue;
            }

            return substr($this->stripFrontmatter($content), 0, self::MAX_CONTEXT_BYTES);
        }

        return <<<'TEXT'
        Agent Loop discipline:
        - Keep human-facing progress concise and factual.
        - Use agent-map before broad PHP reads.
        - Choose the smallest correct change in the owning package.
        - Preserve full diffs, source, tests, and verification artifacts unchanged.
        - Never claim validation that was not executed.
        TEXT;
    }

    private function stripFrontmatter(string $content): string
    {
        return preg_replace('/\A---\R.*?\R---\R/s', '', $content, 1) ?? $content;
    }

    private function isExternalAddonBootstrap(string $command): bool
    {
        $externalSource = '(?:JuliusBrussee/caveman|DietrichGebert/ponytail|rtk-ai/rtk)';

        return preg_match('~\b(?:curl|wget|Invoke-WebRequest|iwr)\b[^\r\n]*' . $externalSource . '~i', $command) === 1
            || preg_match('~\b(?:npm|pnpm|yarn)\b\s+(?:install|add|i)\b[^\r\n]*(?:@juliusbrussee/caveman|\bponytail\b)~i', $command) === 1
            || preg_match('~\b(?:codex\s+plugin|agy\s+plugin|gemini\s+extensions)\b[^\r\n]*(?:DietrichGebert/ponytail|ponytail@ponytail)~i', $command) === 1
            || preg_match('~(?:^|[;&|]\s*)/?plugin\s+(?:marketplace\s+add|install)\b[^\r\n]*(?:DietrichGebert/ponytail|ponytail@ponytail)~i', $command) === 1
            || preg_match('~\b(?:bash|sh|pwsh|powershell)\b[^\r\n]*caveman-install\.(?:sh|ps1)~i', $command) === 1
            || preg_match('~(?:^|[;&|]\s*)rtk\s+init(?:\s|$)~i', $command) === 1;
    }

    private function isUnboundedMapDump(string $command): bool
    {
        return preg_match('~(?:^|[;&|]\s*)(?:cat|less|more)\b[^;&|]*\.agent-map/(?:php-symbols\.json|search\.sqlite)~i', $command) === 1
            || preg_match('~(?:^|[;&|]\s*)sqlite3\b[^;&|]*\.agent-map/search\.sqlite(?:\s|$)~i', $command) === 1
            || preg_match('~(?:^|[;&|]\s*)jq\b[^;&|]*\.agent-map/php-symbols\.json(?:\s|$)~i', $command) === 1;
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
