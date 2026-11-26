<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use voku\AgentLoop\PathResolver;
use Throwable;
use voku\AgentLoop\Run\RunManifestProjector;
use voku\AgentLoop\Run\RunPolicyEvaluator;
use voku\AgentLoop\Run\RunPolicyEvaluation;

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
        $language = WorkflowPresentationLanguage::resolve($this->rootPath);
        $german = $language->isGerman();
        $contract = $decision->contract;
        $card = (new WorkflowDecisionCardFactory($this->rootPath, $language))->contractApproval($decision);

        // The card comes first so a host that shows only the top of the output still shows it.
        $lines = explode("\n", rtrim($this->renderCard($card, $german), "\n"));
        $lines[] = '';
        $lines[] = $german ? 'Contract-Details:' : 'Contract details:';
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

        $subject = $decision->subject(PathResolver::relativeTo($this->rootPath, $decision->contract->path));
        $advice = (new WorkflowDecisionAdvisor($this->rootPath))->advise('contract_approval', $subject);
        $lines[] = '';
        $lines[] = $german ? 'Unverbindliche Entscheidungsempfehlung:' : 'Advisory decision recommendation:';
        $choice = $advice['suggested_choice'];
        $lines[] = ($german ? '  Vorschlag: ' : '  Suggestion: ') . ($choice === null ? 'abstain' : $choice);
        $lines[] = '  ' . $advice['reason'];
        $lines[] = $german
            ? '  Die Entscheidung und Freigabe verbleiben ausschließlich beim Menschen.'
            : '  The human retains sole decision and approval authority.';

        return implode("\n", $lines) . "\n";
    }

    /**
     * Text form of a decision card. Every line comes from the typed card, so the
     * text and JSON projections cannot drift apart.
     */
    public function renderCard(WorkflowDecisionCard $card, ?bool $german = null): string
    {
        $german ??= WorkflowPresentationLanguage::resolve($this->rootPath)->isGerman();
        $lines = [$card->title];
        $taskId = $card->subject['task_id'] ?? null;
        if (is_string($taskId)) {
            $lines[] = ($german ? 'Aufgabe: ' : 'Task: ') . $taskId;
        }
        $revision = $card->subject['contract_revision'] ?? null;
        if (is_int($revision)) {
            $lines[] = sprintf('Revision: %d', $revision);
        }
        $goal = $card->subject['goal'] ?? null;
        if (is_string($goal)) {
            $this->appendText($lines, $german ? 'Ziel' : 'Goal', $goal);
        }
        $lines[] = ($german ? 'Warum jetzt: ' : 'Why now: ') . $card->whyNow['summary'];
        foreach ($card->whyNow['changes'] as $change) {
            $lines[] = '  - ' . $change;
        }
        if ($card->proposedOutcomes !== null) {
            $lines[] = $german ? 'Vorgeschlagene Ergebnisse (nicht nachgewiesen):' : 'Proposed outcomes (not verified):';
            $this->appendList($lines, $german ? 'Deklarierte Verhaltensanker' : 'Declared behavior anchors', $card->proposedOutcomes['behavior_anchors'], $german);
            $this->appendList($lines, $german ? 'Abnahmekriterien' : 'Acceptance criteria', $card->proposedOutcomes['acceptance_criteria'], $german);
        }
        if ($card->mayChange !== [] || $card->type === 'contract_approval') {
            $this->appendList($lines, $german ? 'Darf ändern' : 'May change', $card->mayChange, $german);
        }
        if ($card->outOfScope !== [] || $card->type === 'contract_approval') {
            $this->appendList($lines, $german ? 'Nicht enthalten' : 'Out of scope', $card->outOfScope, $german);
        }
        $lines[] = $german ? 'Nachweis:' : 'Proof:';
        $validation = $card->proof['validation'];
        $lines[] = '  ' . ($german ? 'Validierung: ' : 'Validation: ') . $validation['state']
            . ($validation['detail'] === null ? '' : ' (' . $validation['detail'] . ')');
        foreach ($validation['planned'] as $command) {
            $lines[] = '    ' . ($german ? 'geplant: ' : 'planned: ') . $command;
        }
        $review = $card->proof['review'];
        $lines[] = '  Review: ' . $review['state'] . ($review['detail'] === null ? '' : ' (' . $review['detail'] . ')');
        if ($card->unknown !== []) {
            $lines[] = $german ? 'Unbekannt:' : 'Unknown:';
            foreach ($card->unknown as $item) {
                $lines[] = '  - ' . $item;
            }
        }
        $lines[] = '';
        $lines[] = $card->authorizes;
        $lines[] = $german ? 'Mögliche Entscheidungen:' : 'Available choices:';
        foreach ($card->choices as $choice) {
            $lines[] = '  ' . $choice['label'] . ': ' . $choice['effect'];
            if ($choice['command'] !== null) {
                $lines[] = '    ' . ($choice['id'] === 'approve' ? ($german ? 'Freigabeaktion: ' : 'Approval action: ') : '') . $choice['command'];
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Supplemental non-authoritative advice when the HTML link replaces
     * the larger CLI decision presentation. Never changes approval state.
     */
    public function renderHtmlDecisionAdvice(string $taskId): string
    {
        try {
            $manifest = (new RunManifestProjector($this->rootPath))->project($taskId);
            $policy = (new RunPolicyEvaluator())->evaluateManifest($manifest);
            if ($policy->nextActionKind !== RunPolicyEvaluation::KIND_DECISION_REQUIRED) {
                return '';
            }

            $decision = (new WorkflowHumanDecisionProjector($this->rootPath))->project(
                $taskId,
                $policy->nextAction,
                $policy->nextActionKind,
                $policy->blockers,
            );
            $recommendation = $decision['recommendation'] ?? null;
            if (!is_array($recommendation)) {
                return '';
            }

            $choice = $recommendation['suggested_choice'] ?? null;

            return "\nAdvisory recommendation: "
                . (is_string($choice) ? $choice : 'abstain')
                . "\nReason: "
                . (string) ($recommendation['reason'] ?? 'Review the exact subject.')
                . "\nThe decision still requires explicit human approval.\n";
        } catch (Throwable) {
            // This optional presentation cannot hide or grant a human gate.
            return "\n[WARN] Decision advice unavailable; inspect the exact human gate in agent-ui.\n";
        }
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
