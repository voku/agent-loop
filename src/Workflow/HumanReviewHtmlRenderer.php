<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use RuntimeException;

final readonly class HumanReviewHtmlRenderer
{
    public function __construct(
        private string $css,
        private string $javascript,
    ) {
    }

    public static function fromPackageResources(): self
    {
        $root = dirname(__DIR__, 2) . '/resources/review';
        $css = file_get_contents($root . '/review.css');
        $javascript = file_get_contents($root . '/review.js');
        if (!is_string($css) || !is_string($javascript)) {
            throw new RuntimeException('Human review assets are missing from the installed agent-loop package.');
        }

        return new self($css, $javascript);
    }

    /**
     * @param array<string, mixed> $report
     * @param list<array{id:string,severity:string,message:string,evidence:list<string>}> $findings
     */
    public function render(array $report, array $findings, HumanReviewDiff $diff): string
    {
        $css = str_ireplace('</style', '<\\/style', $this->css);
        $javascript = str_ireplace('</script', '<\\/script', $this->javascript);
        $styleHash = base64_encode(hash('sha256', $css, true));
        $scriptHash = base64_encode(hash('sha256', $javascript, true));
        $csp = "default-src 'none'; base-uri 'none'; form-action 'none'; img-src data:; "
            . "style-src 'sha256-{$styleHash}'; script-src 'sha256-{$scriptHash}'";

        $taskIdValue = $report['task_id'] ?? null;
        $taskId = is_string($taskIdValue) ? $taskIdValue : 'unknown';
        $runIdValue = $report['run_id'] ?? null;
        $runId = is_string($runIdValue) ? $runIdValue : 'missing';

        $contractValue = $report['contract'] ?? null;
        $contract = is_array($contractValue) ? $contractValue : [];
        $reviewValue = $report['review'] ?? null;
        $review = is_array($reviewValue) ? $reviewValue : [];
        $scopeValue = $report['scope'] ?? null;
        $scope = is_array($scopeValue) ? $scopeValue : [];
        $learningValue = $report['learning'] ?? null;
        $learning = is_array($learningValue) ? $learningValue : [];
        $acceptedRiskValue = $report['accepted_risk'] ?? null;
        $acceptedRisk = is_array($acceptedRiskValue) ? $acceptedRiskValue : [];

        /** @var list<array{command:string,status:string,source:string,executed_at:?string}> $validation */
        $validation = [];
        $validationValue = $report['validation'] ?? null;
        if (is_array($validationValue)) {
            foreach ($validationValue as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $commandValue = $item['command'] ?? null;
                $statusValue = $item['status'] ?? null;
                $sourceValue = $item['source'] ?? null;
                $executedValue = $item['executed_at'] ?? null;
                $validation[] = [
                    'command' => is_string($commandValue) ? $commandValue : 'unknown command',
                    'status' => is_string($statusValue) ? $statusValue : 'missing',
                    'source' => is_string($sourceValue) ? $sourceValue : 'unknown',
                    'executed_at' => is_string($executedValue) ? $executedValue : null,
                ];
            }
        }

        $reviewStatusValue = $review['status'] ?? null;
        $reviewStatus = ($review['invalid'] ?? false) === true
            ? 'invalid'
            : (($review['exists'] ?? false) === true && is_string($reviewStatusValue) ? $reviewStatusValue : 'missing');
        $reportStatusValue = $review['report_status'] ?? null;
        $reportStatus = is_string($reportStatusValue) ? $reportStatusValue : 'missing';
        $contractStatusValue = $contract['status'] ?? null;
        $contractStatus = is_string($contractStatusValue) ? $contractStatusValue : 'missing';
        $revisionValue = $contract['revision'] ?? null;
        $revision = is_int($revisionValue) ? sprintf('%d', $revisionValue) : 'missing';
        $implementationSnapshotValue = $review['implementation_snapshot'] ?? null;
        $implementationSnapshot = is_string($implementationSnapshotValue) ? $implementationSnapshotValue : 'missing';
        $reviewShaValue = $review['sha256'] ?? null;
        $reviewSha = is_string($reviewShaValue) ? $reviewShaValue : 'missing';
        $acknowledgedByValue = $review['acknowledged_by'] ?? null;
        $acknowledgedBy = is_string($acknowledgedByValue) ? $acknowledgedByValue : 'nobody';
        $goalValue = $contract['goal'] ?? null;
        $goal = is_string($goalValue) ? $goalValue : 'No durable goal is available.';

        $changedFilesValue = $scope['changed_files'] ?? $diff->changedFiles;
        /** @var list<string> $changedFiles */
        $changedFiles = is_array($changedFilesValue)
            ? array_values(array_filter($changedFilesValue, 'is_string'))
            : $diff->changedFiles;
        $outsideScopeValue = $scope['outside_approved_scope'] ?? null;
        /** @var list<string> $outsideScope */
        $outsideScope = is_array($outsideScopeValue)
            ? array_values(array_filter($outsideScopeValue, 'is_string'))
            : [];

        $acceptanceValue = $contract['acceptance_criteria'] ?? null;
        /** @var list<string> $acceptanceCriteria */
        $acceptanceCriteria = is_array($acceptanceValue)
            ? array_values(array_filter($acceptanceValue, 'is_string'))
            : [];
        $anchorsValue = $contract['behavior_anchors'] ?? null;
        /** @var list<string> $behaviorAnchors */
        $behaviorAnchors = is_array($anchorsValue)
            ? array_values(array_filter($anchorsValue, 'is_string'))
            : [];
        $contractScopeValue = $contract['scope'] ?? null;
        /** @var list<string> $contractScope */
        $contractScope = is_array($contractScopeValue)
            ? array_values(array_filter($contractScopeValue, 'is_string'))
            : [];
        $nonGoalsValue = $contract['non_goals'] ?? null;
        /** @var list<string> $nonGoals */
        $nonGoals = is_array($nonGoalsValue)
            ? array_values(array_filter($nonGoalsValue, 'is_string'))
            : [];

        $findingHtml = $this->findings($findings);
        $validationHtml = $this->validations($validation);
        $fileHtml = $this->changedFiles($changedFiles, $outsideScope, $diff->untrackedFiles);
        $contractHtml = $this->contract($acceptanceCriteria, $behaviorAnchors, $contractScope, $nonGoals);
        $uncertaintyHtml = $this->uncertainty($reviewStatus, $diff);
        $diffHtml = $diff->available
            ? '<pre class="diff" data-searchable>' . self::escape($diff->patch === '' ? '[No scoped Git diff.]' : $diff->patch) . '</pre>'
            : '<div class="section-body"><p class="callout">' . self::escape($diff->unavailableReason ?? 'Git diff unavailable.') . '</p></div>';

        $findingCount = count($findings);
        $changedCount = count($changedFiles);
        $failCount = count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'FAIL'));
        $warnCount = count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'WARN'));
        $validationPassed = count(array_filter($validation, static fn (array $item): bool => $item['status'] === 'passed'));
        $validationTotal = count($validation);

        $learningStatusValue = $learning['status'] ?? null;
        $learningStatus = is_string($learningStatusValue) ? $learningStatusValue : 'unavailable';
        $learningDecisionValue = $learning['decision'] ?? null;
        $learningDecision = is_string($learningDecisionValue) ? $learningDecisionValue : 'missing';
        $riskPathValue = $acceptedRisk['path'] ?? null;
        $riskPath = is_string($riskPathValue) ? $riskPathValue : 'unknown';
        $riskText = ($acceptedRisk['recorded'] ?? false) === true ? 'recorded at ' . $riskPath : 'none recorded';

        $html = '<!doctype html>' . "\n"
            . '<html lang="en">' . "\n"
            . '<head>' . "\n"
            . '  <meta charset="utf-8">' . "\n"
            . '  <meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '  <meta http-equiv="Content-Security-Policy" content="' . self::escape($csp) . '">' . "\n"
            . '  <title>Human review: ' . self::escape($taskId) . '</title>' . "\n"
            . '  <style>' . $css . '</style>' . "\n"
            . '</head>' . "\n"
            . '<body>' . "\n"
            . '<main>' . "\n"
            . '<header>'
            . '<div class="badges">'
            . self::badge('review ' . $reviewStatus, $reviewStatus)
            . self::badge('report ' . $reportStatus, $reportStatus)
            . self::badge('contract ' . $contractStatus, $contractStatus)
            . '</div>'
            . '<h1>' . self::escape($taskId) . '</h1>'
            . '<p class="subtitle">Human review workbench. This HTML is a disposable projection, not approval authority.</p>'
            . '<div class="metrics">'
            . self::metric('Contract revision', $revision)
            . self::metric('Run', $runId)
            . self::metric('Implementation snapshot', $implementationSnapshot)
            . self::metric('Review SHA-256', $reviewSha)
            . '</div>'
            . '</header>'
            . '<div class="callout"><strong>Authority boundary:</strong> approval remains bound to the exact Contract revision, implementation snapshot and persisted review SHA. Git diff, browser filters and viewed sections are orientation only.</div>'
            . '<section><div class="section-head"><h2>Intent and Contract</h2></div><div class="section-body">'
            . '<p><strong>Goal:</strong> ' . self::escape($goal) . '</p>'
            . $contractHtml
            . '</div></section>'
            . '<section><div class="section-head"><h2>Review hotspots</h2><span class="meta">'
            . $failCount . ' fail · ' . $warnCount . ' warn</span></div><div class="section-body">'
            . '<div class="toolbar">'
            . '<button type="button" data-severity-filter="all" aria-pressed="true">All</button>'
            . '<button type="button" data-severity-filter="fail" aria-pressed="false">Fail</button>'
            . '<button type="button" data-severity-filter="warn" aria-pressed="false">Warn</button>'
            . '<button type="button" data-severity-filter="info" aria-pressed="false">Info</button>'
            . '<input type="search" data-review-search aria-label="Search review evidence" placeholder="Search findings and changed files">'
            . '<span class="meta" data-result-count>' . $findingCount . ' findings visible</span>'
            . '</div>'
            . $findingHtml
            . '</div></section>'
            . '<section><div class="section-head"><h2>Validation evidence</h2><span class="meta">'
            . $validationPassed . '/' . $validationTotal . ' passed</span></div><div class="section-body">'
            . $validationHtml
            . '</div></section>'
            . '<section><div class="section-head"><h2>Change overview</h2><span class="meta">'
            . $changedCount . ' changed file(s)</span></div><div class="section-body">'
            . $fileHtml
            . '</div></section>'
            . '<section><div class="section-head"><h2>Blind spots and uncertainty</h2></div><div class="section-body">'
            . $uncertaintyHtml
            . '</div></section>'
            . '<section><div class="section-head"><h2>Full scoped diff</h2><span class="meta">base '
            . self::escape($diff->baseCommit ?? 'unavailable') . '</span></div>'
            . $diffHtml
            . '</section>'
            . '<section><div class="section-head"><h2>Close-out context</h2></div><div class="section-body grid">'
            . '<div class="panel"><h3>Acknowledgement</h3><p>' . self::escape($acknowledgedBy) . '</p></div>'
            . '<div class="panel"><h3>Learning</h3><p>' . self::escape($learningStatus . ' / ' . $learningDecision) . '</p></div>'
            . '<div class="panel"><h3>Accepted risk</h3><p>' . self::escape($riskText) . '</p></div>'
            . '</div></section>'
            . '<div class="toolbar">'
            . '<button type="button" data-details-action="open">Expand all details</button>'
            . '<button type="button" data-details-action="close">Collapse all details</button>'
            . '</div>'
            . '<footer>Generated deterministically from current agent-loop audit evidence plus a non-authoritative scoped Git orientation.</footer>'
            . '</main>' . "\n"
            . '<script>' . $javascript . '</script>' . "\n"
            . '</body>' . "\n"
            . '</html>' . "\n";

        return $html;
    }

    /** @param list<array{id:string,severity:string,message:string,evidence:list<string>}> $findings */
    private function findings(array $findings): string
    {
        if ($findings === []) {
            return '<p class="empty">No typed blind-spot findings are available for this report.</p>';
        }

        $html = '';
        foreach ($findings as $finding) {
            $severity = self::statusClass($finding['severity']);
            $evidence = $finding['evidence'] === []
                ? '<p class="empty">No evidence strings recorded.</p>'
                : '<ul>' . implode('', array_map(
                    static fn (string $item): string => '<li><code>' . self::escape($item) . '</code></li>',
                    $finding['evidence'],
                )) . '</ul>';
            $html .= '<article class="finding" data-finding data-searchable data-severity="' . self::escape($severity) . '">'
                . '<div class="badges">' . self::badge($finding['severity'], $severity) . '</div>'
                . '<h3>' . self::escape($finding['id']) . '</h3>'
                . '<p>' . self::escape($finding['message']) . '</p>'
                . '<details><summary>Evidence</summary><div class="details-body evidence">' . $evidence . '</div></details>'
                . '</article>';
        }

        return $html;
    }

    /** @param list<array{command:string,status:string,source:string,executed_at:?string}> $validation */
    private function validations(array $validation): string
    {
        if ($validation === []) {
            return '<p class="empty">No Contract validation commands are recorded.</p>';
        }

        $html = '';
        foreach ($validation as $item) {
            $suffix = $item['executed_at'] === null
                ? $item['source']
                : $item['source'] . ' · ' . $item['executed_at'];
            $html .= '<div class="validation-row" data-searchable>'
                . '<span class="status ' . self::escape(self::statusClass($item['status'])) . '">' . self::escape($item['status']) . '</span>'
                . '<div><code>' . self::escape($item['command']) . '</code><div class="meta">' . self::escape($suffix) . '</div></div>'
                . '</div>';
        }

        return $html;
    }

    /**
     * @param list<string> $changedFiles
     * @param list<string> $outsideScope
     * @param list<string> $untracked
     */
    private function changedFiles(array $changedFiles, array $outsideScope, array $untracked): string
    {
        if ($changedFiles === []) {
            return '<p class="empty">No changed files were observed for the scoped Git orientation.</p>';
        }

        $outside = array_fill_keys($outsideScope, true);
        $new = array_fill_keys($untracked, true);
        $html = '';
        foreach ($changedFiles as $file) {
            $labels = [self::category($file)];
            if (isset($new[$file])) {
                $labels[] = 'untracked/new';
            }
            if (isset($outside[$file])) {
                $labels[] = 'outside Contract scope';
            }
            $html .= '<div class="file-row" data-searchable>'
                . '<span class="meta">' . self::escape(implode(' · ', $labels)) . '</span>'
                . '<code>' . self::escape($file) . '</code>'
                . '</div>';
        }

        return $html;
    }

    /**
     * @param list<string> $acceptanceCriteria
     * @param list<string> $behaviorAnchors
     * @param list<string> $scope
     * @param list<string> $nonGoals
     */
    private function contract(array $acceptanceCriteria, array $behaviorAnchors, array $scope, array $nonGoals): string
    {
        $groups = [
            'Acceptance criteria' => $acceptanceCriteria,
            'Behavior anchors' => $behaviorAnchors,
            'Approved scope' => $scope,
            'Non-goals' => $nonGoals,
        ];

        $html = '<div class="grid">';
        foreach ($groups as $label => $items) {
            $html .= '<div class="panel"><h3>' . self::escape($label) . '</h3>';
            if ($items === []) {
                $html .= '<p class="empty">None recorded.</p>';
            } else {
                $html .= '<ul>' . implode('', array_map(
                    static fn (string $item): string => '<li>' . self::escape($item) . '</li>',
                    $items,
                )) . '</ul>';
            }
            $html .= '</div>';
        }

        return $html . '</div>';
    }

    private function uncertainty(string $reviewStatus, HumanReviewDiff $diff): string
    {
        $items = [];
        if (in_array($reviewStatus, ['missing', 'invalid', 'stale', 'unacknowledged'], true)) {
            $items[] = 'Review lifecycle status is ' . $reviewStatus . '; do not treat the page as approval evidence.';
        }
        if (!$diff->available) {
            $items[] = $diff->unavailableReason ?? 'Git diff is unavailable.';
        }
        if ($diff->untrackedFiles !== []) {
            $items[] = 'Untracked files are rendered from current file content because they have no base-commit blob.';
        }
        $items[] = 'Absence of a finding means "not reported by current review evidence", not proof that a behavior is safe.';

        return '<ul>' . implode('', array_map(
            static fn (string $item): string => '<li>' . self::escape($item) . '</li>',
            $items,
        )) . '</ul>';
    }

    private static function badge(string $label, string $status): string
    {
        return '<span class="badge ' . self::escape(self::statusClass($status)) . '">' . self::escape($label) . '</span>';
    }

    private static function metric(string $label, string $value): string
    {
        return '<div class="metric"><strong>' . self::escape($label) . '</strong><code>' . self::escape($value) . '</code></div>';
    }

    private static function category(string $file): string
    {
        $lower = strtolower($file);
        if (str_starts_with($lower, 'tests/') || str_starts_with($lower, 'test/')) {
            return 'tests';
        }
        if (str_starts_with($lower, 'docs/') || preg_match('/(^|\/)(readme|changelog|upgrading)(\.|$)/i', $file) === 1) {
            return 'docs';
        }
        if (preg_match('/(^|\/)(composer\.(json|lock)|[^\/]+\.(json|ya?ml|xml|neon|ini|toml))$/i', $file) === 1) {
            return 'config';
        }

        return 'source';
    }

    private static function statusClass(string $status): string
    {
        $normalized = strtolower(trim($status));

        return preg_match('/^[a-z][a-z0-9_-]*$/', $normalized) === 1 ? $normalized : 'unknown';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
