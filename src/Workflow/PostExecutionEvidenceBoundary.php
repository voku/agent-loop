<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use voku\AgentLoop\Run\CanonicalJson;
use voku\AgentSession\Session;
use voku\AgentSession\ValidationEvidence;
use voku\AgentSession\ValidationEvidenceStore;

/**
 * One read-only interpretation of which validation/review evidence describes
 * the current approved implementation snapshot.
 */
final readonly class PostExecutionEvidenceBoundary
{
    /**
     * @param list<ValidationEvidence|null> $validationEvidence
     * @param list<string> $staleValidationCommands
     * @param array{
     *   exists: bool,
     *   status: string|null,
     *   invalid: bool,
     *   contract_revision: int|null,
     *   implementation_snapshot: string|null,
     *   sha256: string|null
     * } $review
     */
    private function __construct(
        public ImplementationSnapshot $implementation,
        public array $validationEvidence,
        public array $staleValidationCommands,
        public array $review,
    ) {
    }

    public static function inspect(string $rootPath, TaskContract $contract, Session $session): self
    {
        $implementation = ImplementationSnapshot::capture($rootPath, $contract);
        $all = (new ValidationEvidenceStore())->all($session);
        $selected = [];
        $stale = [];
        foreach ($contract->validation as $command) {
            $sameObligation = array_values(array_filter(
                $all,
                static fn (ValidationEvidence $item): bool => $item->contractRevision === $contract->revision
                    && $item->command === $command,
            ));
            $current = array_values(array_filter(
                $sameObligation,
                static fn (ValidationEvidence $item): bool => $item->implementationSnapshot === $implementation->digest,
            ));
            $selected[] = $current === [] ? null : $current[count($current) - 1];
            if ($current === [] && $sameObligation !== []) {
                $stale[] = $command;
            }
        }

        return new self(
            $implementation,
            $selected,
            $stale,
            (new WorkflowReviewReportReader($rootPath))->read($contract->taskId),
        );
    }

    public function validationEvidenceSha256(): ?string
    {
        if (in_array(null, $this->validationEvidence, true)) {
            return null;
        }

        return 'sha256:' . hash('sha256', CanonicalJson::pretty(array_map(
            static fn (ValidationEvidence $item): array => $item->toArray(),
            $this->validationEvidence,
        )));
    }

    public function reviewEvidenceSha256(): ?string
    {
        if (
            !$this->review['exists']
            || $this->review['invalid']
            || $this->review['contract_revision'] !== $this->implementation->contractRevision
            || $this->review['implementation_snapshot'] !== $this->implementation->digest
        ) {
            return null;
        }

        return $this->review['sha256'];
    }

    /** @return list<string> */
    public function integrityFailures(): array
    {
        $failures = [];
        foreach ($this->staleValidationCommands as $command) {
            $failures[] = sprintf(
                "stale validation evidence for '%s': no observation is bound to current implementation snapshot %s",
                $command,
                $this->implementation->digest,
            );
        }
        if ($this->review['exists'] && !$this->review['invalid'] && $this->reviewEvidenceSha256() === null) {
            $failures[] = sprintf(
                'stale review evidence: expected Contract revision %d and implementation snapshot %s',
                $this->implementation->contractRevision,
                $this->implementation->digest,
            );
        }

        return $failures;
    }
}
