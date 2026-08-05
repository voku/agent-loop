<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit\Verify;

use JsonException;
use RuntimeException;
use Throwable;

/**
 * Verifies one concrete edit execution: `agent-loop edit verify --bundle=.agent-loop/edit/EDIT-123`.
 *
 * Distinct from `agent-loop verify`, which checks cross-package consistency and drift across the
 * whole repository. This grades a single bundle against the plan and key it was compiled with, and
 * writes `verification-result.json` next to it.
 *
 * The verdict rule is deliberately blunt: any required gate, probe or checklist item that is not
 * `passed` fails the verification, and `not_run` counts as not passed. A gate that could not be
 * executed is an unanswered question, and unanswered questions do not deserve a green result.
 */
final readonly class EditVerifyCommand
{
    public const FILE_NAME = 'verification-result.json';

    public function __construct(
        private string $projectRoot,
        private ProbeGrader $probeGrader = new ProbeGrader(),
        private ChecklistValidator $checklistValidator = new ChecklistValidator(),
        private ObjectiveGateRunner $gateRunner = new ObjectiveGateRunner(),
    ) {
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        if (in_array($tokens[0] ?? '', ['help', '--help', '-h'], true)) {
            echo $this->help();

            return 0;
        }

        try {
            [$bundleDirectory, $runCommands] = $this->options($tokens);
            $bundle = VerificationBundle::load($bundleDirectory);
        } catch (Throwable $exception) {
            fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . "\n");

            return 2;
        }

        try {
            $result = $this->verify($bundle, $runCommands);
            $this->write($bundle->directory . '/' . self::FILE_NAME, $this->json($result));
        } catch (Throwable $exception) {
            fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . "\n");

            return 2;
        }

        $this->report($bundle, $result);

        return $result['status'] === 'passed' ? 0 : 1;
    }

    /** @return array<string, mixed> */
    private function verify(VerificationBundle $bundle, bool $runCommands): array
    {
        $probes = $this->probeGrader->grade(
            $bundle->knowledgeProbes(),
            $bundle->keyProbes(),
            is_array($bundle->result['probe_answers'] ?? null) ? $bundle->result['probe_answers'] : [],
        );
        $checklist = $this->checklistValidator->validate(
            $bundle->checklist(),
            is_array($bundle->result['checklist_evidence'] ?? null) ? $bundle->result['checklist_evidence'] : [],
            $bundle,
            $this->projectRoot,
        );
        $gates = $this->gateRunner->run($bundle, $this->projectRoot, $runCommands);

        $objectiveGate = $this->requiredAllPassed($gates) ? 'passed' : 'failed';
        $status = $objectiveGate === 'passed'
            && $this->requiredAllPassed($probes)
            && $this->requiredAllPassed($checklist)
                ? 'passed'
                : 'failed';

        return [
            'schema_version' => '1.0',
            'task_id' => $bundle->taskId,
            'target' => $bundle->target,
            'verification_plan_sha256' => $bundle->planSha256,
            'status' => $status,
            'objective_gate' => $objectiveGate,
            'knowledge_probes' => $this->tally($probes),
            'checklist' => $this->tally($checklist),
            'gates' => $this->gateStatuses($gates),
            'details' => [
                'knowledge_probes' => $probes,
                'checklist' => $checklist,
                'gates' => $gates,
            ],
        ];
    }

    /**
     * A row without an explicit `required` flag is required: probes and checklist items are
     * obligations unless the plan says otherwise.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function requiredAllPassed(array $rows): bool
    {
        foreach ($rows as $row) {
            if (($row['required'] ?? true) !== false && ($row['status'] ?? null) !== 'passed') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array{passed: int, failed: int, missing: int}
     */
    private function tally(array $rows): array
    {
        $tally = ['passed' => 0, 'failed' => 0, 'missing' => 0];
        foreach ($rows as $row) {
            $status = $row['status'] ?? null;
            if ($status === 'passed' || $status === 'failed' || $status === 'missing') {
                ++$tally[$status];
                continue;
            }
            // `not_run` is not a fourth outcome to report as its own tally; it is a gate that did
            // not answer, which is exactly what `missing` means everywhere else in this file.
            ++$tally['missing'];
        }

        return $tally;
    }

    /**
     * @param list<array{id: string, kind: string, required: bool, status: string, detail: string}> $gates
     *
     * @return array<string, string>
     */
    private function gateStatuses(array $gates): array
    {
        $statuses = [];
        foreach ($gates as $gate) {
            $statuses[$gate['kind']] = $gate['status'];
        }
        ksort($statuses, SORT_STRING);

        return $statuses;
    }

    /**
     * @param list<string> $tokens
     *
     * @return array{0: string, 1: bool}
     */
    private function options(array $tokens): array
    {
        $bundle = null;
        $runCommands = false;
        for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i];
            if ($token === '--run-commands') {
                $runCommands = true;
                continue;
            }
            if (str_starts_with($token, '--bundle=')) {
                $bundle = substr($token, 9);
                continue;
            }
            if ($token === '--bundle') {
                $bundle = $tokens[++$i] ?? null;
                continue;
            }
            if (!str_starts_with($token, '--') && $bundle === null) {
                $bundle = $token;
                continue;
            }

            throw new RuntimeException('Unknown option for edit verify: ' . $token);
        }

        if ($bundle === null || trim($bundle) === '') {
            throw new RuntimeException('edit verify requires --bundle=<edit bundle directory>.');
        }

        return [$this->absolute($bundle), $runCommands];
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : rtrim($this->projectRoot, '/') . '/' . $path;
    }

    /** @param array<string, mixed> $result */
    private function report(VerificationBundle $bundle, array $result): void
    {
        /** @var array{passed: int, failed: int, missing: int} $probes */
        $probes = $result['knowledge_probes'];
        /** @var array{passed: int, failed: int, missing: int} $checklist */
        $checklist = $result['checklist'];

        echo 'Edit verification: ' . $result['status'] . "\n";
        echo '- bundle: ' . $bundle->directory . "\n";
        echo '- task: ' . $bundle->taskId . ' (' . $bundle->target . ")\n";
        echo '- objective gate: ' . $result['objective_gate'] . "\n";
        echo '- knowledge probes: ' . $probes['passed'] . ' passed, ' . $probes['failed'] . ' failed, ' . $probes['missing'] . " missing\n";
        echo '- checklist: ' . $checklist['passed'] . ' passed, ' . $checklist['failed'] . ' failed, ' . $checklist['missing'] . " missing\n";

        /** @var list<array{id: string, kind: string, required: bool, status: string, detail: string}> $gates */
        $gates = $result['details']['gates'];
        foreach ($gates as $gate) {
            if ($gate['status'] !== 'passed') {
                echo '  ! ' . $gate['kind'] . ': ' . $gate['status'] . ' - ' . $gate['detail'] . "\n";
            }
        }
        echo '- result: ' . $bundle->directory . '/' . self::FILE_NAME . "\n";
    }

    private function write(string $path, string $content): void
    {
        $temporary = $path . '.tmp-' . getmypid();
        if (file_put_contents($temporary, $content) === false) {
            throw new RuntimeException('Unable to write verification result: ' . $temporary);
        }
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to publish verification result: ' . $path);
        }
    }

    /** @param array<string, mixed> $result */
    private function json(array $result): string
    {
        try {
            return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode verification result: ' . $exception->getMessage(), 0, $exception);
        }
    }

    private function help(): string
    {
        return <<<'TXT'
        Usage:
          agent-loop edit verify --bundle=.agent-loop/edit/<task-id> [--run-commands]

        Verifies one concrete edit execution against the verification plan and the
        verifier-owned key it was compiled with, and writes verification-result.json
        into the bundle.

        Checked: artifact bindings and hashes, canonical probe answers against the
        private key, checklist evidence, and the declared objective gates (runner
        exit, PHP lint of changed files, post-edit map freshness, target
        resolvability).

        Options:
          --bundle PATH    Edit bundle directory. Required.
          --run-commands   Also execute gates that shell out (approved validation
                           commands, agent-loop verify). Without it those report
                           not_run, and a required gate that did not run fails the
                           verification rather than passing it.

        Exit codes: 0 verified, 1 verification failed, 2 the bundle could not be read.

        This is not `agent-loop verify`, which checks cross-package consistency and
        drift. Different jobs, different commands.

        TXT;
    }
}
