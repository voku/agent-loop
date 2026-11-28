<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use Throwable;
use voku\AgentLoop\PathResolver;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunManifestProjector;

/**
 * Builds the decision card for each decision_required type from facts the owners
 * already hold: the Contract and its revision history, the RunManifest's
 * validation and review references, the review report and the gate's blockers.
 * It adds no judgment: a state the owner does not report becomes "unknown", and
 * nothing is described as passed unless the owner's own state says so.
 */
final readonly class WorkflowDecisionCardFactory
{
    public const string NOT_RUN = 'not_run';
    public const string UNKNOWN = 'unknown';

    public function __construct(private string $rootPath, private WorkflowPresentationLanguage $language)
    {
    }

    public static function forRoot(string $rootPath): self
    {
        return new self($rootPath, WorkflowPresentationLanguage::resolve($rootPath));
    }

    public function contractApproval(WorkflowContractApprovalPresentation $presentation): WorkflowDecisionCard
    {
        $contract = $presentation->contract;
        $delta = $presentation->revisionDelta();
        try {
            $run = (new GovernedRunStore($this->rootPath))->find($contract->taskId);
            $runBound = $run !== null && $run->contractRevision === $contract->revision;
        } catch (Throwable) {
            // An unreadable Run (e.g. an older schema) must not break planning; it proves nothing.
            $runBound = false;
        }

        if ($delta === null) {
            $whyNow = [
                'kind' => 'first_revision',
                'summary' => $this->t(
                    sprintf('Revision %d is the first revision of this Contract; no earlier revision exists.', $contract->revision),
                    sprintf('Revision %d ist die erste Revision dieses Contracts; es gibt keine frühere Revision.', $contract->revision),
                ),
                'changes' => [],
            ];
        } else {
            $approved = $delta['previous_was_approved'];
            $decisionKind = $approved ? null : $delta['previous_rejection_kind'];
            $decidedBy = $delta['previous_rejected_by'] ?? 'a human';
            $decisionReason = $delta['previous_rejection_reason'] ?? '';
            $whyNow = [
                'kind' => match (true) {
                    $approved => 'supersedes_approved',
                    $decisionKind === TaskContract::CHANGE_REQUEST => 'supersedes_change_request',
                    $decisionKind === TaskContract::REJECTION => 'supersedes_rejection',
                    default => 'supersedes_draft',
                },
                'summary' => match (true) {
                    $approved => $this->t(
                        sprintf('Revision %d replaces revision %d, which %s approved%s.', $delta['to_revision'], $delta['from_revision'], $delta['previous_approved_by'] ?? 'a human', $delta['previous_approved_at'] === null ? '' : ' at ' . $delta['previous_approved_at']),
                        sprintf('Revision %d ersetzt Revision %d, freigegeben von %s%s.', $delta['to_revision'], $delta['from_revision'], $delta['previous_approved_by'] ?? 'einem Menschen', $delta['previous_approved_at'] === null ? '' : ' am ' . $delta['previous_approved_at']),
                    ),
                    $decisionKind === TaskContract::CHANGE_REQUEST => $this->t(
                        sprintf('Revision %d answers the change request %s made on revision %d: %s', $delta['to_revision'], $decidedBy, $delta['from_revision'], $decisionReason),
                        sprintf('Revision %d beantwortet die Änderungsanforderung von %s an Revision %d: %s', $delta['to_revision'], $decidedBy, $delta['from_revision'], $decisionReason),
                    ),
                    $decisionKind === TaskContract::REJECTION => $this->t(
                        sprintf('Revision %d replaces revision %d, which %s rejected: %s', $delta['to_revision'], $delta['from_revision'], $decidedBy, $decisionReason),
                        sprintf('Revision %d ersetzt Revision %d, die %s abgelehnt hat: %s', $delta['to_revision'], $delta['from_revision'], $decidedBy, $decisionReason),
                    ),
                    default => $this->t(
                        sprintf('Revision %d replaces revision %d, which was never approved.', $delta['to_revision'], $delta['from_revision']),
                        sprintf('Revision %d ersetzt Revision %d, die nie freigegeben wurde.', $delta['to_revision'], $delta['from_revision']),
                    ),
                },
                'changes' => $this->deltaLines($delta),
            ];
        }

        $bound = $runBound ? $this->manifestProof($contract->taskId) : null;
        $validation = $bound !== null
            ? $bound['validation']
            : ['state' => self::NOT_RUN, 'detail' => $this->t(
                sprintf('No Run exists for revision %d yet; nothing has been validated.', $contract->revision),
                sprintf('Für Revision %d gibt es noch keinen Run; nichts wurde validiert.', $contract->revision),
            )];
        $review = $bound !== null ? $bound['review'] : ['state' => self::NOT_RUN, 'detail' => null];
        $validation['planned'] = $contract->validation;

        return new WorkflowDecisionCard(
            'contract_approval',
            $this->language->rendered(),
            $this->language->language,
            $this->t('Decision required: Contract approval', 'Entscheidung erforderlich: Contract-Freigabe'),
            $this->t(
                'What approval authorizes: work only within the Contract scope. It does not approve the implementation, validation results, or Learning.',
                'Was die Freigabe erlaubt: Arbeit ausschließlich innerhalb des im Contract festgelegten Scope. Sie genehmigt weder die Implementierung noch Validierungsergebnisse oder Learning.',
            ),
            [
                'task_id' => $contract->taskId,
                'contract_revision' => $contract->revision,
                'goal' => $contract->goal,
                'head_commit' => $presentation->headCommit,
                'contract_path' => PathResolver::relativeTo($this->rootPath, $contract->path),
            ],
            $whyNow,
            $contract->scope,
            $contract->nonGoals,
            [
                'validation' => $validation,
                'review' => $review,
            ],
            [
                $this->choice('approve', $this->t('Approve', 'Freigeben'), $this->t(
                    'Work may begin within the described scope. Without an explicit selection the profile defaults to standard: independent correctness and blindspot reviews. Surgical has one fresh review. After approval, select a different profile before the first enter fixes it; consider technical and user impact and uncertainty.',
                    'Die Arbeit darf innerhalb des beschriebenen Scope beginnen. Ohne ausdrückliche Auswahl gilt standard: unabhängige Correctness- und Blindspot-Reviews. Surgical hat ein Review in frischem Kontext. Ein anderes Profil nach der Freigabe und vor dem ersten enter wählen, das die Auswahl festlegt; technische Auswirkungen, Auswirkungen auf Nutzer und Unsicherheit berücksichtigen.',
                ), $presentation->action === '' ? null : $presentation->action),
                $this->choice('request_changes', $this->t('Request changes', 'Änderungen anfordern'), $this->t(
                    'Describe what must change. The Contract remains unapproved until a revised version is presented and explicitly approved.',
                    'Beschreibe die nötigen Änderungen. Der Contract bleibt nicht freigegeben, bis eine neue Revision vorgelegt und ausdrücklich freigegeben wird.',
                ), 'agent-loop workflow request-changes ' . $contract->taskId . ' --revision ' . $contract->revision . ' --by <actor> --reason <reason>'),
                $this->choice('reject', $this->t('Reject', 'Ablehnen'), $this->t(
                    'Record a rejection and reason. A new Contract revision is required before work can proceed.',
                    'Die Ablehnung mit Begründung festhalten. Vor weiterer Arbeit ist eine neue Contract-Revision erforderlich.',
                ), 'agent-loop workflow reject ' . $contract->taskId . ' --revision ' . $contract->revision . ' --by <actor> --reason <reason>'),
            ],
            [$this->t(
                'What led to this revision (the owner records no lifecycle trigger for a plan).',
                'Was zu dieser Revision geführt hat (der Owner speichert für einen Plan keinen Auslöser).',
            ), ...$this->translationGap()],
        );
    }

    /** @param array<string, mixed> $subject */
    public function forDecision(string $type, string $taskId, string $action, array $subject): WorkflowDecisionCard
    {
        $proof = $this->manifestProof($taskId);
        try {
            $contract = (new TaskContractStore($this->rootPath))->find($taskId);
        } catch (Throwable) {
            $contract = null;
        }
        $proof['validation']['planned'] = $contract === null ? [] : $contract->validation;
        $mayChange = [];
        $outOfScope = [];
        $unknown = [];
        $blockers = $this->blockerMessages($subject['blockers'] ?? []);

        switch ($type) {
            case 'review_acknowledgement':
                $findings = is_array($subject['findings'] ?? null) ? count($subject['findings']) : 0;
                $status = is_string($subject['report_status'] ?? null) ? $subject['report_status'] : self::UNKNOWN;
                $title = $this->t('Decision required: acknowledge the review report', 'Entscheidung erforderlich: Review-Bericht bestätigen');
                $whyNow = ['kind' => 'review_report_ready', 'summary' => $this->t(
                    sprintf('The review report (status %s, %d finding(s)) must be acknowledged before Learning and close.', $status, $findings),
                    sprintf('Der Review-Bericht (Status %s, %d Befund(e)) muss vor Learning und Abschluss bestätigt werden.', $status, $findings),
                ), 'changes' => []];
                $proof['review'] = ['state' => $status, 'detail' => $this->t(sprintf('%d finding(s) in the report', $findings), sprintf('%d Befund(e) im Bericht', $findings))];
                $outOfScope = [
                    $this->t('Approving the implementation.', 'Die Implementierung freigeben.'),
                    $this->t('The Learning disposition.', 'Die Learning-Entscheidung.'),
                ];
                $authorizes = $this->t(
                    'Acknowledging records that you read this exact report. It does not approve the implementation or Learning.',
                    'Die Bestätigung hält fest, dass du genau diesen Bericht gelesen hast. Sie genehmigt weder die Implementierung noch Learning.',
                );
                $digest = is_string($subject['review_sha256'] ?? null) ? $subject['review_sha256'] : null;
                if ($digest === null) {
                    $unknown[] = $this->t('The review report digest, so no acknowledgement command can be offered.', 'Der Digest des Review-Berichts; daher kann kein Bestätigungsbefehl angeboten werden.');
                }
                // Same template RunPolicyEvaluator routes for an unacknowledged report.
                $choices = [
                    $this->choice('acknowledge', $this->t('Acknowledge', 'Bestätigen'), $this->t('Bind the acknowledgement to this report digest.', 'Die Bestätigung an diesen Bericht-Digest binden.'), $digest === null ? null : 'agent-loop finish ' . $taskId . ' --reviewed-report-sha256 ' . $digest . ' --by <actor>'),
                    $this->choice('inspect', $this->t('Inspect first', 'Erst ansehen'), $this->t('Open the report presentation; no decision is recorded.', 'Die Bericht-Darstellung öffnen; keine Entscheidung wird festgehalten.'), 'agent-loop workflow review ' . $taskId),
                ];
                break;
            case 'learning_disposition':
                $observations = $this->stringList($subject['review_observations'] ?? []);
                $allowed = $this->stringList($subject['allowed_dispositions'] ?? []);
                $title = $this->t('Decision required: Learning disposition', 'Entscheidung erforderlich: Learning-Entscheidung');
                $whyNow = ['kind' => 'learning_required', 'summary' => $observations === []
                    ? $this->t('Validation and review are bound; the Run needs a Learning disposition to close.', 'Validierung und Review sind gebunden; der Run braucht zum Abschluss eine Learning-Entscheidung.')
                    : $this->t(sprintf('The reviews recorded %d observation(s); they must become Findings or a follow-up.', count($observations)), sprintf('Die Reviews haben %d Beobachtung(en) festgehalten; sie müssen zu Findings oder einem Follow-up werden.', count($observations))),
                    'changes' => $observations];
                $outOfScope = [$this->t('Changing the implementation.', 'Die Implementierung ändern.')];
                $authorizes = $this->t(
                    'The disposition records what this Run taught. It does not approve the implementation.',
                    'Die Entscheidung hält fest, was dieser Run gelehrt hat. Sie genehmigt nicht die Implementierung.',
                );
                $effects = [
                    'no_durable_learning' => $this->t('Record that nothing reusable came out of this Run.', 'Festhalten, dass aus diesem Run nichts Wiederverwendbares entstand.'),
                    'findings_recorded' => $this->t('Record reviewed Findings for Learning.', 'Geprüfte Findings für Learning festhalten.'),
                    'follow_up_required' => $this->t('Link the issue that tracks the remaining work.', 'Das Issue verknüpfen, das die offene Arbeit verfolgt.'),
                ];
                $commands = [
                    'no_durable_learning' => 'agent-loop finish ' . $taskId . ' --learning no_durable_learning --by <actor>',
                    'findings_recorded' => 'agent-loop finish ' . $taskId . ' --learning findings_recorded --by <actor> --finding <finding-id>',
                    'follow_up_required' => 'agent-loop finish ' . $taskId . ' --by <actor> --follow-up-ref <follow-up-ref>',
                ];
                $choices = [];
                foreach ($allowed as $disposition) {
                    $choices[] = $this->choice($disposition, $disposition, $effects[$disposition] ?? $disposition, $commands[$disposition] ?? null);
                }
                break;
            case 'risk_acceptance':
                $title = $this->t('Decision required: accept a named risk', 'Entscheidung erforderlich: ein benanntes Risiko akzeptieren');
                $whyNow = ['kind' => 'blocked_close', 'summary' => $this->t('Close is blocked; the owner allows it only with an explicitly accepted risk.', 'Der Abschluss ist blockiert; der Owner erlaubt ihn nur mit einem ausdrücklich akzeptierten Risiko.'), 'changes' => $blockers];
                $outOfScope = [$this->t('Fixing the blocker.', 'Den Blocker beheben.')];
                $authorizes = $this->t('Accepting records your name and reason for closing despite the blocker.', 'Die Akzeptanz hält deinen Namen und Grund fest, trotz Blocker abzuschließen.');
                $choices = [
                    $this->choice('accept_risk', $this->t('Accept risk', 'Risiko akzeptieren'), $this->t('Close with the named risk recorded.', 'Mit festgehaltenem Risiko abschließen.'), $action),
                    $this->choice('fix_blocker', $this->t('Fix the blocker', 'Blocker beheben'), $this->t('Leave the task open and repair the blocker first.', 'Die Aufgabe offen lassen und zuerst den Blocker beheben.'), null),
                ];
                break;
            case 'contract_supersession':
                $reason = is_string($subject['reason'] ?? null) ? $subject['reason'] : null;
                $minimum = is_string($subject['minimum_contract_change'] ?? null) ? $subject['minimum_contract_change'] : null;
                $title = $this->t('Decision required: revise the Contract', 'Entscheidung erforderlich: den Contract überarbeiten');
                $whyNow = ['kind' => 'contract_insufficient', 'summary' => $reason ?? $this->t('The current Contract no longer covers the required work.', 'Der aktuelle Contract deckt die nötige Arbeit nicht mehr ab.'), 'changes' => array_values(array_filter([$minimum, ...$blockers], static fn (?string $line): bool => $line !== null && $line !== ''))];
                if ($reason === null) {
                    $unknown[] = $this->t('The owner recorded no reason for this supersession.', 'Der Owner hat keinen Grund für diese Überarbeitung festgehalten.');
                }
                $mayChange = $this->stringList($subject['scope'] ?? []);
                $authorizes = $this->t('Superseding creates a new candidate revision that still needs explicit approval.', 'Die Überarbeitung erzeugt eine neue Kandidaten-Revision, die weiterhin ausdrücklich freigegeben werden muss.');
                $choices = [
                    $this->choice('supersede', $this->t('Revise', 'Überarbeiten'), $this->t('Plan a new revision; nothing is approved yet.', 'Eine neue Revision planen; noch wird nichts freigegeben.'), $action),
                    $this->choice('stop', $this->t('Stop', 'Anhalten'), $this->t('Keep the current revision and stop work.', 'Die aktuelle Revision behalten und die Arbeit anhalten.'), null),
                ];
                break;
            default:
                $title = $this->t('Decision required: human authority', 'Entscheidung erforderlich: menschliche Autorität');
                $whyNow = ['kind' => 'human_authority', 'summary' => $blockers === []
                    ? $this->t('The owner requires a human decision for the next action.', 'Der Owner verlangt für die nächste Aktion eine menschliche Entscheidung.')
                    : $this->t('The owner reports blockers that only a human may resolve.', 'Der Owner meldet Blocker, die nur ein Mensch auflösen darf.'),
                    'changes' => $blockers];
                $unknown[] = $this->t('The owner provides no typed subject for this gate.', 'Der Owner liefert für dieses Gate keinen typisierten Gegenstand.');
                $authorizes = $this->t('Only the action below; nothing else is approved.', 'Nur die Aktion unten; sonst wird nichts genehmigt.');
                $choices = [$this->choice('perform_action', $this->t('Perform the action', 'Aktion ausführen'), $this->t('Run the owner action as written.', 'Die Owner-Aktion wie angegeben ausführen.'), $action)];
        }

        // These two card types can sit behind a decision_required gate that another
        // owner raised (e.g. a stopped execution contract); show that gate's action too.
        if (in_array($type, ['review_acknowledgement', 'learning_disposition'], true) && $action !== '') {
            $choices[] = $this->choice('gate_action', $this->t('Resolve the reported gate', 'Das gemeldete Gate auflösen'), $this->t('The owner reports this action as the current human gate.', 'Der Owner meldet diese Aktion als aktuelles menschliches Gate.'), $this->commandOrNull($action, $unknown));
        }
        foreach ($choices as $index => $choice) {
            if ($choice['command'] !== null && $choice['command'] === $action) {
                $choices[$index]['command'] = $this->commandOrNull($action, $unknown);
            }
        }

        return new WorkflowDecisionCard(
            $type,
            $this->language->rendered(),
            $this->language->language,
            $title,
            $authorizes,
            ['task_id' => $taskId, 'contract_revision' => $contract?->revision, 'action' => $action],
            $whyNow,
            $mayChange,
            $outOfScope,
            $proof,
            $choices,
            [...$unknown, ...$this->translationGap()],
        );
    }

    /**
     * Decision text exists only in English and German. When another language was
     * requested, say so instead of letting the card claim it.
     *
     * @return list<string>
     */
    private function translationGap(): array
    {
        $requested = $this->language->language;
        if ($requested === $this->language->rendered() || str_starts_with($requested, $this->language->rendered() . '-')) {
            return [];
        }

        return ['Card text is in ' . $this->language->rendered() . '; ' . $requested . ' was requested. Translate the explanatory text for the human; keep commands and identifiers verbatim.'];
    }

    /**
     * Validation and review as the RunManifest reports them, unchanged.
     *
     * @return array{validation: array{state: string, detail: string|null, planned: list<string>}, review: array{state: string, detail: string|null}}
     */
    private function manifestProof(string $taskId): array
    {
        try {
            $references = (new RunManifestProjector($this->rootPath))->project($taskId)->references;
        } catch (Throwable) {
            return [
                'validation' => ['state' => self::UNKNOWN, 'detail' => null, 'planned' => []],
                'review' => ['state' => self::UNKNOWN, 'detail' => null],
            ];
        }
        $verification = is_array($references['verification'] ?? null) ? $references['verification'] : [];
        $review = is_array($references['review'] ?? null) ? $references['review'] : [];

        return [
            'validation' => [
                'state' => is_string($verification['state'] ?? null) ? $verification['state'] : self::UNKNOWN,
                'detail' => is_string($verification['reason'] ?? null) ? $verification['reason'] : null,
                'planned' => [],
            ],
            'review' => [
                'state' => is_string($review['state'] ?? null) ? $review['state'] : self::UNKNOWN,
                'detail' => null,
            ],
        ];
    }

    /**
     * @param array{lists: array<string, array{added: list<string>, removed: list<string>}>, previous_goal: string|null, empty: bool} $delta
     * @return list<string>
     */
    private function deltaLines(array $delta): array
    {
        $lines = [];
        if ($delta['previous_goal'] !== null) {
            $lines[] = $this->t('Goal changed (was: ', 'Ziel geändert (vorher: ') . $delta['previous_goal'] . ')';
        }
        foreach ($delta['lists'] as $label => $changes) {
            $label = $this->language->isGerman() ? match ($label) {
                'Validation' => 'Validierung',
                'Non-goals' => 'Ausgeschlossen',
                'Acceptance criteria' => 'Abnahmekriterien',
                'Behavior anchors' => 'Verhaltensanker',
                'Operating prompt manifest' => 'Operating-Prompt-Manifest',
                'Operating prompts' => 'Operating-Prompts',
                'Operating prompt order' => 'Operating-Prompt-Reihenfolge',
                default => $label,
            } : $label;
            foreach ($changes['added'] as $item) {
                $lines[] = $label . ': + ' . $item;
            }
            foreach ($changes['removed'] as $item) {
                $lines[] = $label . ': - ' . $item;
            }
        }
        if ($delta['empty']) {
            $lines[] = $this->t('No Contract field changed.', 'Kein Contract-Feld hat sich geändert.');
        }

        return $lines;
    }

    /** @return list<string> */
    private function blockerMessages(mixed $blockers): array
    {
        $messages = [];
        if (is_array($blockers)) {
            foreach ($blockers as $blocker) {
                if (is_array($blocker) && is_string($blocker['message'] ?? null) && $blocker['message'] !== '') {
                    $messages[] = $blocker['message'];
                }
            }
        }

        return $messages;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * The gate's next_action is sometimes prose ("revise the approved Contract ...")
     * rather than a command; prose is never presented as a runnable command.
     *
     * @param list<string> $unknown
     */
    private function commandOrNull(string $action, array &$unknown): ?string
    {
        if (str_starts_with($action, 'agent-loop ')) {
            return $action;
        }
        $unknown[] = $this->t('A runnable command for: ', 'Ein ausführbarer Befehl für: ') . $action;

        return null;
    }

    /** @return array{id: string, label: string, effect: string, command: string|null} */
    private function choice(string $id, string $label, string $effect, ?string $command): array
    {
        return ['id' => $id, 'label' => $label, 'effect' => $effect, 'command' => $command];
    }

    private function t(string $english, string $german): string
    {
        return $this->language->isGerman() ? $german : $english;
    }
}
