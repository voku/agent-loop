<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class PhaseFRecoveryGateTest extends TestCase
{
    public function testRecoveryGateRejectsMissingOrAlteredEvidence(): void
    {
        $workflow = Yaml::parseFile(dirname(__DIR__) . '/.github/workflows/ci.yml');
        self::assertArrayNotHasKey('shell', $workflow['defaults']['run'] ?? [], 'The gate must use the default shell.');
        $job = $workflow['jobs']['release-set-dogfood'];
        self::assertArrayNotHasKey('if', $job, 'The recovery job must not be conditional.');
        self::assertArrayNotHasKey('needs', $job, 'The recovery job must not depend on a skipped job.');
        self::assertArrayNotHasKey('continue-on-error', $job);
        self::assertArrayNotHasKey('shell', $job['defaults']['run'] ?? [], 'The gate must use the default shell.');
        $steps = $job['steps'];
        $gates = array_values(array_filter($steps, static fn (array $step): bool => ($step['name'] ?? '') === 'Verify Phase-F recovery convergence evidence'));
        self::assertCount(1, $gates, 'The release-set job must retain exactly one Phase-F recovery gate.');
        self::assertArrayNotHasKey('if', $gates[0], 'The recovery gate must not be conditional.');
        self::assertArrayNotHasKey('continue-on-error', $gates[0]);
        self::assertArrayNotHasKey('shell', $gates[0], 'The test must exercise the same shell semantics as CI.');
        $script = $gates[0]['run'];
        self::assertIsString($script);

        $scenarios = [
            ['scenario' => 'recovery.absent-discovery', 'kind' => 'decision_required', 'obeyed' => false, 'action' => 'resolve'],
            ['scenario' => 'recovery.failed-validation', 'kind' => 'host_work', 'obeyed' => false, 'action' => 'repair'],
            ['scenario' => 'recovery.recall-meta-invalid', 'kind' => 'host_work', 'obeyed' => false, 'action' => 'agent-recall-compiler .agent-loop/recall/REC-3/meta.json'],
            ['scenario' => 'recovery.review-report-invalid', 'kind' => 'command', 'obeyed' => true, 'action' => 'review'],
            ['scenario' => 'recovery.execution-contract-blocked', 'kind' => 'decision_required', 'obeyed' => false, 'action' => 'decide'],
            ['scenario' => 'recovery.preparation-refusal', 'kind' => 'host_work', 'obeyed' => false, 'action' => 'repair'],
        ];

        $root = sys_get_temp_dir() . '/phase-f-gate-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root . '/build', 0777, true));
        try {
            $check = static function (array $evidence) use ($root, $script): bool {
                file_put_contents($root . '/build/release-set-report.json', json_encode(['recovery_convergence' => $evidence], JSON_THROW_ON_ERROR));
                $process = new Process(['bash', '-e', '-c', $script], $root);
                $process->run();

                return $process->isSuccessful();
            };

            self::assertTrue($check($scenarios), 'Valid recovery evidence must pass the actual CI step.');
            $extra = $scenarios;
            $extra[] = ['scenario' => 'recovery.unexpected', 'kind' => 'host_work', 'obeyed' => false, 'action' => 'repair'];
            self::assertFalse($check($extra), 'Extra recovery scenarios must not pass.');
            foreach (array_keys($scenarios) as $index) {
                $missing = $scenarios;
                array_splice($missing, $index, 1);
                self::assertFalse($check($missing), 'Missing scenario ' . $index);
                foreach (['scenario' => 'wrong', 'kind' => 'wrong', 'obeyed' => !$scenarios[$index]['obeyed'], 'action' => ''] as $field => $value) {
                    $changed = $scenarios;
                    $changed[$index][$field] = $value;
                    self::assertFalse($check($changed), sprintf('Changed %s for scenario %d', $field, $index));
                }
                $missingAction = $scenarios;
                unset($missingAction[$index]['action']);
                self::assertFalse($check($missingAction), 'Missing action for scenario ' . $index);
            }
            $wrongObeyedType = $scenarios;
            $wrongObeyedType[3]['obeyed'] = 'true';
            self::assertFalse($check($wrongObeyedType), 'A string is not the required true value.');
            foreach (array_keys($scenarios) as $first) {
                for ($second = $first + 1; $second < count($scenarios); ++$second) {
                    $reordered = $scenarios;
                    [$reordered[$first], $reordered[$second]] = [$reordered[$second], $reordered[$first]];
                    self::assertFalse($check($reordered), sprintf('Scenario order matters: %d, %d', $first, $second));
                }
            }
            foreach (['agent-recall-compiler', '.agent-loop/recall/REC-3/meta.json'] as $required) {
                $wrongAction = $scenarios;
                $wrongAction[2]['action'] = str_replace($required, 'missing', $wrongAction[2]['action']);
                self::assertFalse($check($wrongAction), 'Recall repair must include ' . $required);
                for ($length = 1; $length < strlen($required); ++$length) {
                    foreach ([substr($required, 0, $length), substr($required, -$length)] as $fragment) {
                        $wrongAction = $scenarios;
                        $wrongAction[2]['action'] = str_replace($required, $fragment, $wrongAction[2]['action']);
                        self::assertFalse($check($wrongAction), 'A partial Recall requirement must not pass: ' . $fragment);
                    }
                }
            }
            $wrongAction = $scenarios;
            $wrongAction[2]['action'] .= ' workflow manifest';
            self::assertFalse($check($wrongAction), 'The Recall repair must not suggest workflow manifest.');
        } finally {
            @unlink($root . '/build/release-set-report.json');
            @rmdir($root . '/build');
            @rmdir($root);
        }
    }
}
