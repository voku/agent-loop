<?php

declare(strict_types=1);

namespace voku\AgentLoop\Run;

use RuntimeException;
use Throwable;
use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Domain\CardId;
use voku\AgentKanban\Exception\ValidationException;
use voku\AgentKanban\Repository\MarkdownCardRepository;
use voku\AgentLoop\RecallOutputRoot;
use voku\AgentLoop\Workflow\WorkflowReviewReportReader;
use voku\AgentSession\LearningDecisionStore;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStore;
use voku\AgentSession\WorkBrief;
use voku\AgentSession\WorkBriefStore;

/**
 * Reads the current owning-package artifacts and builds one task-scoped
 * projection. It does not repair, rewrite, or reinterpret the source state.
 */
final readonly class RunManifestProjector
{
    public function __construct(private string $rootPath)
    {
    }

    public function project(string $taskId): RunManifest
    {
        /** @var list<array{code: string, owner: string, message: string}> $disagreements */
        $disagreements = [];
        $session = $this->sessionForTask($taskId, $disagreements);
        $briefStore = new WorkBriefStore();
        $brief = $session === null ? null : $briefStore->find($session);
        $approval = $session === null ? null : $briefStore->approval($session);

        if ($brief !== null && $approval !== null && $approval->workBriefRevision !== $brief->revision) {
            $disagreements[] = [
                'code' => 'approval.revision_mismatch',
                'owner' => 'agent-session',
                'message' => sprintf(
                    'Approval references work-brief revision %d while the current revision is %d.',
                    $approval->workBriefRevision,
                    $brief->revision,
                ),
            ];
        }

        $references = [
            'board' => $this->boardReference($taskId, $disagreements),
            'session' => $this->sessionReference($session),
            'work_brief' => $this->workBriefReference($session, $brief),
            'approval' => $this->approvalReference($session, $brief, $approval),
            'map' => $this->fileReference('agent-map', '.agent-map/php-symbols.json', 'missing'),
            'search_index' => $this->fileReference('agent-map', '.agent-map/search.sqlite', 'not_built'),
            'recall' => $this->recallReference($taskId, $disagreements),
            'edit' => $this->editReference($taskId),
            'verification' => $this->verificationReference($taskId, $disagreements),
            'review' => $this->reviewReference($taskId, $disagreements),
            'learning' => $this->learningReference($session),
            'outcome_lineage' => [
                'owner' => 'agent-learning',
                'state' => 'contract_pending',
                'observation_mode' => 'unknown',
                'reason' => 'Run-linked immutable outcome inspection is tracked in voku/agent-learning#10.',
            ],
        ];

        usort(
            $disagreements,
            static fn (array $left, array $right): int => strcmp($left['code'], $right['code']),
        );

        $mode = $session === null ? 'legacy_inferred' : ($session->ephemeral ? 'ephemeral' : 'governed');
        $runId = $session === null ? 'task:' . $taskId . ':legacy' : 'session:' . $session->id;
        $state = $this->overallState($session, $brief, $references, $disagreements);
        $nextAction = $this->nextAction($taskId, $session, $brief, $references, $disagreements);

        return new RunManifest($taskId, $runId, $mode, $state, $references, $disagreements, $nextAction);
    }

    /**
     * @param list<array{code: string, owner: string, message: string}> $disagreements
     */
    private function sessionForTask(string $taskId, array &$disagreements): ?Session
    {
        $root = rtrim($this->rootPath, '/') . '/session_plan';
        if (!is_dir($root)) {
            return null;
        }

        $matches = array_values(array_filter(
            (new SessionStore())->all($root),
            static fn (Session $session): bool => $session->taskId === $taskId,
        ));
        $active = array_values(array_filter(
            $matches,
            static fn (Session $session): bool => !$session->status->isClosed(),
        ));

        if (count($active) > 1) {
            $disagreements[] = [
                'code' => 'session.multiple_active',
                'owner' => 'agent-session',
                'message' => sprintf('Task %s has %d active sessions; the run identity is ambiguous.', $taskId, count($active)),
            ];

            return null;
        }
        if ($active !== []) {
            return $active[0];
        }
        if ($matches === []) {
            return null;
        }

        usort(
            $matches,
            static fn (Session $left, Session $right): int => [$left->updatedAt, $left->id] <=> [$right->updatedAt, $right->id],
        );

        return $matches[array_key_last($matches)];
    }

    /** @return array<string, mixed> */
    private function boardReference(string $taskId, array &$disagreements): array
    {
        $configPath = rtrim($this->rootPath, '/') . '/todo/kanban.config.json';
        if (!is_file($configPath)) {
            return [
                'owner' => 'agent-kanban',
                'state' => 'not_configured',
                'observation_mode' => 'checked',
            ];
        }

        try {
            $cardId = CardId::fromString($taskId);
        } catch (ValidationException) {
            return [
                'owner' => 'agent-kanban',
                'state' => 'ad_hoc_task',
                'observation_mode' => 'checked',
                'config' => $this->artifact($configPath),
            ];
        }

        try {
            $repository = new MarkdownCardRepository(
                $this->rootPath,
                BoardConfig::fromJsonFile($configPath),
            );
            if (!$repository->exists($cardId)) {
                return [
                    'owner' => 'agent-kanban',
                    'state' => 'not_linked',
                    'observation_mode' => 'checked',
                    'card_id' => $taskId,
                    'config' => $this->artifact($configPath),
                ];
            }

            $card = $repository->load($cardId);

            return [
                'owner' => 'agent-kanban',
                'state' => 'linked',
                'observation_mode' => 'checked',
                'card_id' => $taskId,
                'revision' => $card->revision->toString(),
                'lane' => $card->lane->toString(),
                'status' => $card->status->toString(),
                'source' => $this->artifact($card->sourceFile),
                'config' => $this->artifact($configPath),
            ];
        } catch (Throwable $exception) {
            $disagreements[] = [
                'code' => 'board.unreadable',
                'owner' => 'agent-kanban',
                'message' => $exception->getMessage(),
            ];

            return [
                'owner' => 'agent-kanban',
                'state' => 'invalid',
                'observation_mode' => 'checked',
                'config' => $this->artifact($configPath),
            ];
        }
    }

    /** @return array<string, mixed> */
    private function sessionReference(?Session $session): array
    {
        if ($session === null) {
            return [
                'owner' => 'agent-session',
                'state' => 'missing',
                'observation_mode' => 'checked',
            ];
        }

        return [
            'owner' => 'agent-session',
            'state' => $session->status->value,
            'observation_mode' => 'checked',
            'session_id' => $session->id,
            'task_id' => $session->taskId,
            'ephemeral' => $session->ephemeral,
            'base_commit' => $session->baseCommit,
            'source' => $this->artifact($session->path . '/session.json'),
        ];
    }

    /** @return array<string, mixed> */
    private function workBriefReference(?Session $session, ?WorkBrief $brief): array
    {
        if ($session === null) {
            return [
                'owner' => 'agent-session',
                'state' => 'unavailable',
                'observation_mode' => 'checked',
            ];
        }
        if ($brief === null) {
            return [
                'owner' => 'agent-session',
                'state' => 'missing',
                'observation_mode' => 'checked',
            ];
        }

        return [
            'owner' => 'agent-session',
            'state' => $brief->status->value,
            'observation_mode' => 'checked',
            'revision' => $brief->revision,
            'source' => $this->artifact($brief->path),
        ];
    }

    /** @return array<string, mixed> */
    private function approvalReference(?Session $session, ?WorkBrief $brief, mixed $approval): array
    {
        if ($session === null || $brief === null) {
            return [
                'owner' => 'agent-session',
                'state' => 'unavailable',
                'observation_mode' => 'checked',
            ];
        }
        if ($approval === null) {
            return [
                'owner' => 'agent-session',
                'state' => 'missing',
                'observation_mode' => 'checked',
            ];
        }

        return [
            'owner' => 'agent-session',
            'state' => $approval->workBriefRevision === $brief->revision ? 'current' : 'superseded',
            'observation_mode' => 'checked',
            'work_brief_revision' => $approval->workBriefRevision,
            'approved_by' => $approval->approvedBy,
            'approved_at' => $approval->approvedAt,
            'source' => $this->artifact($approval->path),
        ];
    }

    /** @return array<string, mixed> */
    private function recallReference(string $taskId, array &$disagreements): array
    {
        $directory = RecallOutputRoot::resolve($this->rootPath) . '/' . $taskId;
        $metaPath = $directory . '/meta.json';
        if (!is_file($metaPath)) {
            return [
                'owner' => 'agent-recall-compiler',
                'state' => 'missing',
                'observation_mode' => 'checked',
                'path' => RecallOutputRoot::relativeTo($this->rootPath, $metaPath),
            ];
        }

        try {
            $meta = $this->decodeJson($metaPath);
        } catch (RuntimeException $exception) {
            $disagreements[] = [
                'code' => 'recall.meta_invalid',
                'owner' => 'agent-recall-compiler',
                'message' => $exception->getMessage(),
            ];

            return [
                'owner' => 'agent-recall-compiler',
                'state' => 'invalid',
                'observation_mode' => 'checked',
                'source' => $this->artifact($metaPath),
            ];
        }

        $metaTaskId = $meta['task_id'] ?? null;
        if (is_string($metaTaskId) && $metaTaskId !== $taskId) {
            $disagreements[] = [
                'code' => 'recall.task_mismatch',
                'owner' => 'agent-recall-compiler',
                'message' => sprintf('Recall metadata belongs to task %s, not %s.', $metaTaskId, $taskId),
            ];
        }

        return [
            'owner' => 'agent-recall-compiler',
            'state' => ($meta['blocked'] ?? false) === true ? 'blocked' : 'compiled',
            'observation_mode' => 'checked',
            'compilation_id' => is_string($meta['compilation_id'] ?? null) ? $meta['compilation_id'] : null,
            'bundle_sha256' => is_string($meta['bundle_sha256'] ?? null) ? $meta['bundle_sha256'] : null,
            'snapshot_sha256' => is_string($meta['snapshot_sha256'] ?? null) ? $meta['snapshot_sha256'] : null,
            'source' => $this->artifact($metaPath),
            'compilation_receipt' => is_file($directory . '/compilation-receipt.json')
                ? $this->artifact($directory . '/compilation-receipt.json')
                : null,
        ];
    }

    /** @return array<string, mixed> */
    private function editReference(string $taskId): array
    {
        $directory = rtrim($this->rootPath, '/') . '/.agent-loop/edit/' . $taskId;
        if (!is_dir($directory)) {
            return [
                'owner' => 'agent-loop',
                'state' => 'none',
                'observation_mode' => 'checked',
            ];
        }

        $artifacts = [];
        foreach (['request.json', 'execution.json', 'agent-result.json'] as $file) {
            $path = $directory . '/' . $file;
            if (is_file($path)) {
                $artifacts[$file] = $this->artifact($path);
            }
        }

        return [
            'owner' => 'agent-loop',
            'state' => 'present',
            'observation_mode' => 'checked',
            'path' => $this->relativePath($directory),
            'artifacts' => $artifacts,
        ];
    }

    /** @return array<string, mixed> */
    private function verificationReference(string $taskId, array &$disagreements): array
    {
        $bundle = rtrim($this->rootPath, '/') . '/.agent-loop/edit/' . $taskId;
        if (!is_dir($bundle)) {
            return [
                'owner' => 'agent-loop',
                'state' => 'not_required',
                'observation_mode' => 'checked',
            ];
        }

        $path = $bundle . '/verification-result.json';
        if (!is_file($path)) {
            return [
                'owner' => 'agent-loop',
                'state' => 'missing',
                'observation_mode' => 'checked',
                'path' => $this->relativePath($path),
            ];
        }

        try {
            $data = $this->decodeJson($path);
        } catch (RuntimeException $exception) {
            $disagreements[] = [
                'code' => 'verification.result_invalid',
                'owner' => 'agent-loop',
                'message' => $exception->getMessage(),
            ];

            return [
                'owner' => 'agent-loop',
                'state' => 'invalid',
                'observation_mode' => 'checked',
                'source' => $this->artifact($path),
            ];
        }

        $status = $data['status'] ?? null;
        if (!is_string($status) || $status === '') {
            $disagreements[] = [
                'code' => 'verification.status_missing',
                'owner' => 'agent-loop',
                'message' => 'verification-result.json has no non-empty status.',
            ];
            $status = 'invalid';
        }

        return [
            'owner' => 'agent-loop',
            'state' => $status,
            'observation_mode' => 'checked',
            'source' => $this->artifact($path),
        ];
    }

    /** @return array<string, mixed> */
    private function reviewReference(string $taskId, array &$disagreements): array
    {
        $reader = new WorkflowReviewReportReader($this->rootPath);
        $report = $reader->read($taskId);
        if (!$report['exists']) {
            return [
                'owner' => 'agent-loop',
                'state' => 'missing',
                'observation_mode' => 'checked',
                'path' => $reader->relativePath($taskId),
            ];
        }
        if ($report['invalid']) {
            $disagreements[] = [
                'code' => 'review.report_invalid',
                'owner' => 'agent-loop',
                'message' => $reader->relativePath($taskId) . ' is not a valid blind-spot report.',
            ];

            return [
                'owner' => 'agent-loop',
                'state' => 'invalid',
                'observation_mode' => 'checked',
                'source' => $this->artifact($reader->absolutePath($taskId)),
            ];
        }

        return [
            'owner' => 'agent-loop',
            'state' => $report['status'],
            'observation_mode' => 'checked',
            'source' => $this->artifact($reader->absolutePath($taskId)),
        ];
    }

    /** @return array<string, mixed> */
    private function learningReference(?Session $session): array
    {
        if ($session === null) {
            return [
                'owner' => 'agent-session',
                'state' => 'unavailable',
                'observation_mode' => 'checked',
            ];
        }

        $decision = (new LearningDecisionStore())->find($session);
        if ($decision === null) {
            return [
                'owner' => 'agent-session',
                'state' => 'missing',
                'observation_mode' => 'checked',
            ];
        }

        return [
            'owner' => 'agent-session',
            'state' => $decision->decision->value,
            'observation_mode' => 'checked',
            'decided_by' => $decision->decidedBy,
            'decided_at' => $decision->decidedAt,
            'source' => $this->artifact($session->path . '/learning-decision.json'),
        ];
    }

    /** @return array<string, mixed> */
    private function fileReference(string $owner, string $relativePath, string $missingState): array
    {
        $path = rtrim($this->rootPath, '/') . '/' . ltrim($relativePath, '/');
        if (!is_file($path)) {
            return [
                'owner' => $owner,
                'state' => $missingState,
                'observation_mode' => 'checked',
                'path' => $relativePath,
            ];
        }

        return [
            'owner' => $owner,
            'state' => 'present',
            'observation_mode' => 'legacy_path_reference',
            'source' => $this->artifact($path),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $references
     * @param list<array{code: string, owner: string, message: string}> $disagreements
     */
    private function overallState(?Session $session, ?WorkBrief $brief, array $references, array $disagreements): string
    {
        if ($disagreements !== []) {
            return 'blocked';
        }
        if ($session === null) {
            return 'incomplete';
        }
        if ($session->ephemeral) {
            return 'experiment';
        }
        if ($brief === null || $brief->status->value !== 'approved') {
            return 'incomplete';
        }
        if (($references['recall']['state'] ?? null) !== 'compiled') {
            return 'incomplete';
        }
        if (($references['verification']['state'] ?? null) === 'missing') {
            return 'incomplete';
        }
        if (in_array($references['verification']['state'] ?? null, ['failed', 'invalid'], true)) {
            return 'blocked';
        }
        if (in_array($references['review']['state'] ?? null, ['missing', 'invalid'], true)) {
            return 'incomplete';
        }
        if (($references['learning']['state'] ?? null) === 'missing') {
            return 'incomplete';
        }
        if ($session->status->isClosed()) {
            return 'complete';
        }

        return 'ready_to_close';
    }

    /**
     * @param array<string, array<string, mixed>> $references
     * @param list<array{code: string, owner: string, message: string}> $disagreements
     */
    private function nextAction(string $taskId, ?Session $session, ?WorkBrief $brief, array $references, array $disagreements): string
    {
        if ($disagreements !== []) {
            return 'agent-loop workflow manifest ' . $taskId . ' --format=json';
        }
        if ($session === null) {
            return 'agent-loop workflow plan ' . $taskId . ' --by <actor> --file <path> --goal "..." --validation "..."';
        }
        if ($session->ephemeral) {
            return 'agent-loop session close ' . $session->id . ' --status dropped';
        }
        if ($brief === null || $brief->status->value !== 'approved') {
            return 'agent-loop workflow approve ' . $taskId . ' --by <human-actor>';
        }
        if (($references['recall']['state'] ?? null) !== 'compiled') {
            return 'agent-loop workflow approve ' . $taskId . ' --by <human-actor>';
        }
        if (($references['verification']['state'] ?? null) === 'missing') {
            return 'agent-loop edit verify --bundle=.agent-loop/edit/' . $taskId;
        }
        if (in_array($references['review']['state'] ?? null, ['missing', 'invalid'], true)) {
            return 'agent-loop review blindspots ' . $taskId;
        }
        if (($references['learning']['state'] ?? null) === 'missing') {
            return 'agent-loop session learning decide ' . $session->id . ' --by <actor> --status findings_recorded';
        }
        if ($session->status->isClosed()) {
            return 'none';
        }

        return 'agent-loop workflow close ' . $taskId . ' --status done';
    }

    /** @return array<string, mixed> */
    private function artifact(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Referenced artifact does not exist: ' . $path);
        }
        $sha = hash_file('sha256', $path);
        if ($sha === false) {
            throw new RuntimeException('Unable to hash referenced artifact: ' . $path);
        }

        return [
            'path' => $this->relativePath($path),
            'sha256' => 'sha256:' . $sha,
        ];
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read JSON artifact: ' . $path);
        }
        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON artifact: ' . $path);
        }

        return $decoded;
    }

    private function relativePath(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $this->rootPath), '/');
        $normalized = str_replace('\\', '/', $path);
        if ($root !== '' && str_starts_with($normalized, $root . '/')) {
            return substr($normalized, strlen($root) + 1);
        }

        return $normalized;
    }
}
