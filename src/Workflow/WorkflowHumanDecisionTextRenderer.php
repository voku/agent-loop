<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use voku\AgentLoop\Init\InitConfigLoader;
use voku\AgentLoop\ProjectLayout;

/**
 * Human-readable CLI view of a typed Contract approval projection.
 *
 * The renderer explains owner-backed facts and consequences. It does not
 * decide whether a revision is acceptable or record the human's choice.
 */
final readonly class WorkflowHumanDecisionTextRenderer
{
    public function __construct(private string $rootPath)
    {
    }

    public function renderContractApproval(WorkflowContractApprovalPresentation $decision): string
    {
        $config = (new InitConfigLoader($this->rootPath))->load((new ProjectLayout($this->rootPath))->configPath());
        $german = $this->isGerman($config['prompting']['language']);
        $contract = $decision->contract;
        $lines = [
            $german ? 'Entscheidung erforderlich: Contract-Freigabe' : 'Decision required: Contract approval',
            ($german ? 'Aufgabe: ' : 'Task: ') . $contract->taskId,
            sprintf('Revision: %d', $contract->revision),
        ];
        $this->appendText($lines, $german ? 'Ziel' : 'Goal', $contract->goal);
        $this->appendList($lines, 'Scope', $contract->scope, $german);
        $this->appendList($lines, $german ? 'Ausgeschlossen' : 'Non-goals', $contract->nonGoals, $german);
        $this->appendList($lines, $german ? 'Abnahmekriterien' : 'Acceptance criteria', $contract->acceptanceCriteria, $german);
        $this->appendList($lines, $german ? 'Validierung' : 'Validation', $contract->validation, $german);
        $this->appendList($lines, $german ? 'Verhaltensanker' : 'Behavior anchors', $contract->behaviorAnchors, $german);
        $this->appendRevisionDelta($lines, $decision->previousRevision, $contract, $german);
        $lines[] = '';
        $lines[] = $german
            ? 'Was die Freigabe erlaubt: Arbeit ausschließlich innerhalb des im Contract festgelegten Scope. Sie genehmigt weder die Implementierung noch Validierungsergebnisse oder Learning.'
            : 'What approval authorizes: work only within the Contract scope. It does not approve the implementation, validation results, or Learning.';
        $lines[] = $german ? 'Mögliche Entscheidungen:' : 'Available choices:';
        $lines[] = $german
            ? '  Freigeben: Die Arbeit darf innerhalb des beschriebenen Scope beginnen.'
            : '  Approve: Work may begin within the described scope.';
        if ($decision->action !== '') {
            $lines[] = '    ' . ($german ? 'Freigabeaktion: ' : 'Approval action: ') . $decision->action;
        }
        $lines[] = $german
            ? '  Änderungen anfordern: Beschreibe die nötigen Änderungen. Der Contract bleibt nicht freigegeben, bis eine neue Revision vorgelegt und ausdrücklich freigegeben wird.'
            : '  Request changes: Describe what must change. The Contract remains unapproved until a revised version is presented and explicitly approved.';
        $lines[] = $german
            ? '  Ablehnen: Nicht freigeben. Es gibt keine separate Ablehnungsaktion; die Aufgabe bleibt blockiert.'
            : '  Decline: Do not approve. There is no separate rejection action; the task stays blocked.';

        return implode("\n", $lines) . "\n";
    }

    private function isGerman(string $language): bool
    {
        $normalized = strtolower($language);

        return $normalized === 'de' || str_starts_with($normalized, 'de-');
    }

    /** @param list<string> $lines */
    private function appendText(array &$lines, string $label, string $value): void
    {
        $lines[] = $label . ':';
        foreach (preg_split('/\R/', $value) ?: [$value] as $line) {
            $lines[] = '  ' . $line;
        }
    }

    /** @param list<string> $lines
     *  @param list<string> $items
     */
    private function appendList(array &$lines, string $label, array $items, bool $german): void
    {
        $lines[] = $label . ':';
        if ($items === []) {
            $lines[] = '  ' . ($german ? '(keine angegeben)' : '(none specified)');

            return;
        }
        foreach ($items as $item) {
            $lines[] = '  - ' . $item;
        }
    }

    /** @param list<string> $lines */
    private function appendRevisionDelta(
        array &$lines,
        ?TaskContract $previous,
        TaskContract $current,
        bool $german,
    ): void {
        if ($previous === null) {
            return;
        }

        $lines[] = $german
            ? sprintf('Änderungen seit Revision %d (neue Revision %d):', $previous->revision, $current->revision)
            : sprintf('Changes since revision %d (new revision %d):', $previous->revision, $current->revision);
        if ($previous->approvedBy !== null) {
            $lines[] = '  ' . ($german ? 'Vorherige Revision freigegeben von: ' : 'Previous revision approved by: ') . $previous->approvedBy;
        }
        if ($previous->approvedAt !== null) {
            $lines[] = '  ' . ($german ? 'Vorherige Freigabe am: ' : 'Previous approval at: ') . $previous->approvedAt;
        }
        if ($previous->goal !== $current->goal) {
            $lines[] = '  ' . ($german ? 'Vorheriges Ziel: ' : 'Previous goal: ') . $previous->goal;
        }

        $changesExist = $this->appendChanges($lines, 'Scope', $previous->scope, $current->scope);
        $changesExist = $this->appendChanges(
            $lines,
            $german ? 'Validierung' : 'Validation',
            $previous->validation,
            $current->validation,
        ) || $changesExist;
        $changesExist = $this->appendChanges(
            $lines,
            $german ? 'Ausgeschlossen' : 'Non-goals',
            $previous->nonGoals,
            $current->nonGoals,
        ) || $changesExist;
        $changesExist = $this->appendChanges(
            $lines,
            $german ? 'Abnahmekriterien' : 'Acceptance criteria',
            $previous->acceptanceCriteria,
            $current->acceptanceCriteria,
        ) || $changesExist;

        if (!$changesExist && $previous->goal === $current->goal) {
            $lines[] = '  ' . ($german
                ? 'Ziel, Scope, Validierung, Ausschlüsse und Abnahmekriterien sind unverändert.'
                : 'Goal, scope, validation, non-goals, and acceptance criteria are unchanged.');
        }
    }

    /**
     * @param list<string> $lines
     * @param list<string> $previous
     * @param list<string> $current
     */
    private function appendChanges(array &$lines, string $label, array $previous, array $current): bool
    {
        $added = array_values(array_diff($current, $previous));
        $removed = array_values(array_diff($previous, $current));
        if ($added === [] && $removed === []) {
            return false;
        }

        $lines[] = '  ' . $label . ':';
        foreach ($added as $item) {
            $lines[] = '    + ' . $item;
        }
        foreach ($removed as $item) {
            $lines[] = '    - ' . $item;
        }

        return true;
    }
}
