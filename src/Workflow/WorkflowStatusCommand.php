<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use Throwable;
use voku\AgentLoop\RecallOutputRoot;
use voku\AgentSession\LearningDecisionStore;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStore;
use voku\AgentSession\WorkBriefStatus;
use voku\AgentSession\WorkBriefStore;

/**
 * One joined view of where a task stands across every package that owns part of its lifecycle.
 *
 * The stages are printed in lifecycle order and the command ends with the single next command,
 * because six packages reporting their own green light leaves the reader to join six state machines
 * in their head - which is exactly where the surprises live.
 */
final readonly class WorkflowStatusCommand
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        try {
            $taskId = new WorkflowTaskId($args[0] ?? '');

            $session = $this->activeSession($taskId->value);
            $stages = [
                'Session' => $this->sessionStage($taskId->value, $session),
                'Work brief' => $this->workBriefStage($session),
                'Recall' => $this->recallStage($taskId->value),
                'Edit bundle' => $this->editStage($taskId->value),
                'Review' => $this->reviewStage($taskId->value),
                'Learning' => $this->learningStage($session),
            ];

            echo 'Task ' . $taskId->value . "\n";
            foreach ($stages as $label => [$state, $detail]) {
                printf("  %-13s %-9s %s\n", $label . ':', $state, $detail);
            }

            echo "\nNext:\n  " . $this->nextCommand($taskId->value, $stages) . "\n";

            return 0;
        } catch (Throwable $e) {
            fwrite(\STDERR, '[FAIL] workflow status: ' . $e->getMessage() . "\n");

            return 1;
        }
    }

    private function activeSession(string $taskId): ?Session
    {
        $sessionsRoot = rtrim($this->rootPath, '/') . '/session_plan';
        if (!is_dir($sessionsRoot)) {
            return null;
        }

        $sessions = array_values(array_filter(
            (new SessionStore())->all($sessionsRoot),
            static fn (Session $session): bool => $session->taskId === $taskId && !$session->status->isClosed(),
        ));

        return count($sessions) === 1 ? $sessions[0] : null;
    }

    /** @return array{0: string, 1: string} */
    private function sessionStage(string $taskId, ?Session $session): array
    {
        if ($session === null) {
            return ['missing', 'no single active session for ' . $taskId];
        }

        return [
            $session->ephemeral ? 'ephemeral' : $session->status->value,
            $session->id . ($session->ephemeral ? ' (an experiment; repository gates ignore it)' : ''),
        ];
    }

    /** @return array{0: string, 1: string} */
    private function workBriefStage(?Session $session): array
    {
        if ($session === null) {
            return ['missing', 'needs a session first'];
        }

        $briefs = new WorkBriefStore();
        $brief = $briefs->find($session);
        if ($brief === null) {
            return ['missing', 'no work brief on this session'];
        }

        $approval = $briefs->approval($session);
        if ($brief->status === WorkBriefStatus::APPROVED && $approval !== null && $approval->workBriefRevision === $brief->revision) {
            return ['approved', 'revision ' . $brief->revision . ' by ' . $approval->approvedBy];
        }

        return [$brief->status->value, 'revision ' . $brief->revision . ' awaits approval'];
    }

    /** @return array{0: string, 1: string} */
    private function recallStage(string $taskId): array
    {
        $path = RecallOutputRoot::resolve($this->rootPath) . '/' . $taskId . '/meta.json';
        $relative = RecallOutputRoot::relativeTo($this->rootPath, $path);

        return is_file($path) ? ['compiled', $relative] : ['missing', 'no briefing at ' . $relative];
    }

    /**
     * An edit bundle is optional - plenty of tasks never run `edit` - but once one exists, close
     * demands a passing verification, so the two are reported as one stage.
     *
     * @return array{0: string, 1: string}
     */
    private function editStage(string $taskId): array
    {
        $bundle = rtrim($this->rootPath, '/') . '/.agent-loop/edit/' . $taskId;
        if (!is_dir($bundle)) {
            return ['none', 'no edit bundle; nothing to verify'];
        }

        $resultFile = $bundle . '/verification-result.json';
        if (!is_file($resultFile)) {
            return ['unverified', '.agent-loop/edit/' . $taskId . ' has no verification-result.json'];
        }

        $decoded = json_decode((string) file_get_contents($resultFile), true);
        $status = is_array($decoded) && is_string($decoded['status'] ?? null) ? $decoded['status'] : null;
        if ($status === null) {
            return ['unreadable', 'verification-result.json has no status'];
        }

        return [$status === 'passed' ? 'verified' : $status, '.agent-loop/edit/' . $taskId];
    }

    /** @return array{0: string, 1: string} */
    private function reviewStage(string $taskId): array
    {
        $reader = new WorkflowReviewReportReader($this->rootPath);
        $report = $reader->read($taskId);
        $relative = $reader->relativePath($taskId);
        if (!$report['exists']) {
            return ['missing', 'no report at ' . $relative];
        }
        if ($report['invalid']) {
            return ['invalid', $relative . ' is not valid JSON'];
        }

        return [is_string($report['status']) ? $report['status'] : 'unknown', $relative];
    }

    /** @return array{0: string, 1: string} */
    private function learningStage(?Session $session): array
    {
        if ($session === null) {
            return ['missing', 'needs a session first'];
        }

        $decision = (new LearningDecisionStore())->find($session);

        return $decision === null
            ? ['missing', 'no learning decision recorded']
            : [$decision->decision->value, 'recorded by ' . $decision->decidedBy];
    }

    /**
     * @param array<string, array{0: string, 1: string}> $stages
     */
    private function nextCommand(string $taskId, array $stages): string
    {
        return match (true) {
            $stages['Session'][0] === 'missing' => 'agent-loop workflow plan ' . $taskId . ' --by <actor> --file <path> --goal "..."',
            $stages['Session'][0] === 'ephemeral' => 'agent-loop session close <session-id> --status dropped',
            $stages['Work brief'][0] !== 'approved' => 'agent-loop workflow approve ' . $taskId . ' --by <human-actor>',
            $stages['Recall'][0] === 'missing' => 'agent-loop workflow approve ' . $taskId . ' --by <human-actor>',
            $stages['Edit bundle'][0] === 'unverified' => 'agent-loop edit verify --bundle=.agent-loop/edit/' . $taskId,
            $stages['Review'][0] === 'missing' => 'agent-loop review blindspots ' . $taskId,
            $stages['Learning'][0] === 'missing' => 'agent-loop session learning decide <session-id> --by <actor> --status findings_recorded',
            default => 'agent-loop workflow close ' . $taskId . ' --status done',
        };
    }
}
