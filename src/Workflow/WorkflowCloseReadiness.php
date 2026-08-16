<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

/**
 * Read-only result of evaluating whether an ordinary `workflow close --status done`
 * can succeed against the current implementation and evidence boundary.
 *
 * Non-waivable failures are integrity/Contract failures that `--accept-risk` must
 * never bypass. Gate failures may be accepted explicitly during the mutating close
 * transition, but they still mean the ordinary close action is not ready now.
 */
final readonly class WorkflowCloseReadiness
{
    /**
     * @param list<array{gate: string, detail: string}> $nonWaivableFailures
     * @param list<array{gate: string, detail: string}> $gateFailures
     * @param list<array{command: string, status: string, exit_code: int|null, executed_at: string|null, recorded_by: string|null, duration_ms: int|null}> $validationObligations
     * @param list<string> $passedMessages
     */
    public function __construct(
        public ?PostExecutionEvidenceBoundary $boundary,
        public array $nonWaivableFailures,
        public array $gateFailures,
        public array $validationObligations,
        public array $passedMessages,
    ) {
    }

    public function isReady(): bool
    {
        return $this->nonWaivableFailures === [] && $this->gateFailures === [];
    }

    /** @return array{gate: string, detail: string}|null */
    public function firstFailure(): ?array
    {
        return $this->nonWaivableFailures[0] ?? $this->gateFailures[0] ?? null;
    }

    public function firstUnsatisfiedValidationCommand(): ?string
    {
        foreach ($this->validationObligations as $obligation) {
            if ($obligation['status'] !== 'passed') {
                return $obligation['command'];
            }
        }

        return null;
    }
}
