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
        $this->appendList(
            $lines,
            $german ? 'Operating-Prompt-Manifest' : 'Operating prompt manifest',
            $contract->operatingPromptManifest === null ? [] : [$contract->operatingPromptManifest],
            $german,
        );
        $this->appendList(
            $lines,
            $german ? 'Operating-Prompts' : 'Operating prompts',
            $decision->operatingPromptSummaries($contract->operatingPrompts),
            $german,
        );
        $this->appendRevisionDelta($lines, $decision, $german);
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
            ? '  Ablehnen: Die Ablehnung mit Begründung festhalten. Vor weiterer Arbeit ist eine neue Contract-Revision erforderlich.'
            : '  Reject: Record a rejection and reason. A new Contract revision is required before work can proceed.';
        $lines[] = '    agent-loop workflow reject ' . $contract->taskId . ' --revision ' . $contract->revision . ' --by <actor> --reason <reason>';

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
        WorkflowContractApprovalPresentation $decision,
        bool $german,
    ): void {
        $delta = $decision->revisionDelta();
        if ($delta === null) {
            return;
        }

        $lines[] = $german
            ? sprintf('Änderungen seit Revision %d (neue Revision %d):', $delta['from_revision'], $delta['to_revision'])
            : sprintf('Changes since revision %d (new revision %d):', $delta['from_revision'], $delta['to_revision']);
        if ($delta['previous_approved_by'] !== null) {
            $lines[] = '  ' . ($german ? 'Vorherige Revision freigegeben von: ' : 'Previous revision approved by: ') . $delta['previous_approved_by'];
        }
        if ($delta['previous_approved_at'] !== null) {
            $lines[] = '  ' . ($german ? 'Vorherige Freigabe am: ' : 'Previous approval at: ') . $delta['previous_approved_at'];
        }
        if ($delta['previous_goal'] !== null) {
            $lines[] = '  ' . ($german ? 'Vorheriges Ziel: ' : 'Previous goal: ') . $delta['previous_goal'];
        }

        foreach ($delta['lists'] as $label => $changes) {
            $lines[] = '  ' . $this->revisionLabel($label, $german) . ':';
            foreach ($changes['added'] as $item) {
                $lines[] = '    + ' . $item;
            }
            foreach ($changes['removed'] as $item) {
                $lines[] = '    - ' . $item;
            }
        }

        if ($delta['empty']) {
            $lines[] = '  ' . ($german
                ? 'Ziel, Scope, Validierung, Ausschlüsse, Abnahmekriterien, Verhaltensanker und Operating-Prompts sind unverändert.'
                : 'Goal, scope, validation, non-goals, acceptance criteria, behavior anchors, and operating prompts are unchanged.');
        }
    }

    private function revisionLabel(string $label, bool $german): string
    {
        if (!$german) {
            return $label;
        }

        return match ($label) {
            'Validation' => 'Validierung',
            'Non-goals' => 'Ausgeschlossen',
            'Acceptance criteria' => 'Abnahmekriterien',
            'Behavior anchors' => 'Verhaltensanker',
            'Operating prompt manifest' => 'Operating-Prompt-Manifest',
            'Operating prompts' => 'Operating-Prompts',
            'Operating prompt order' => 'Operating-Prompt-Reihenfolge',
            default => $label,
        };
    }
}
