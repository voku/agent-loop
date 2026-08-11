<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Edit\EditCommand;

/**
 * Drives the real chain: `edit` compiles a plan and writes the answer sheet, the answers are filled
 * in the way an agent would, and `edit verify` grades them against the key it never saw.
 */
final class EditVerifyCommandTest extends TestCase
{
    private string $root;
    private string $bundle;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-edit-verify-' . bin2hex(random_bytes(6));
        $this->bundle = $this->root . '/.agent-loop/edit/EDIT-VERIFY';
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/UserService.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Demo;

        final class UserService
        {
            public function save(bool $active): void
            {
                if (!$active) {
                    return;
                }
            }
        }
        PHP);
        file_put_contents($this->root . '/src/UserController.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Demo;

        final class UserController
        {
            public function submit(UserService $service): void
            {
                $service->save(true);
            }
        }
        PHP);
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testGradesCorrectAnswersAgainstTheKeyItNeverSaw(): void
    {
        $this->runEdit();
        $key = $this->key();
        $this->answer(static function (string $probeId) use ($key): array {
            $accepted = $key['probes'][$probeId]['accepted_answers'] ?? [];

            return is_array($accepted) && isset($accepted[0]) ? [(string) $accepted[0]] : [];
        });

        $output = $this->verify();

        $result = $this->verificationResult();
        self::assertGreaterThan(0, $result['knowledge_probes']['passed'], 'the fixture must produce at least one probe');
        self::assertSame(0, $result['knowledge_probes']['failed']);
        self::assertSame(0, $result['knowledge_probes']['missing']);
        self::assertStringContainsString('Edit verification:', $output);
        self::assertSame('sha256:' . hash('sha256', (string) file_get_contents($this->planFile())), $result['verification_plan_sha256']);
    }

    public function testAWrongProbeAnswerFailsAndIsReportedAsFailedNotMissing(): void
    {
        $this->runEdit();
        $this->answer(static fn (string $probeId): array => ['method:Demo\\Nope::nope']);

        $exit = $this->verifyExitCode();
        $result = $this->verificationResult();

        self::assertSame(1, $exit);
        self::assertSame('failed', $result['status']);
        self::assertSame(0, $result['knowledge_probes']['passed']);
        self::assertSame(0, $result['knowledge_probes']['missing'], 'a wrong answer is not a missing answer');
        self::assertGreaterThan(0, $result['knowledge_probes']['failed']);
    }

    public function testAnUnansweredProbeIsMissingRatherThanFailed(): void
    {
        $this->runEdit();

        self::assertSame(1, $this->verifyExitCode());

        $result = $this->verificationResult();
        self::assertSame('failed', $result['status']);
        self::assertSame(0, $result['knowledge_probes']['failed']);
        self::assertGreaterThan(0, $result['knowledge_probes']['missing']);
    }

    public function testDeclaredCommandsAreNotExecutedWithoutOptInAndDoNotCountAsPassed(): void
    {
        $this->runEdit();
        $result = $this->resultAfterVerify();

        $commandGates = array_values(array_filter(
            $result['details']['gates'],
            static fn (array $gate): bool => in_array($gate['kind'], ['approved_validation_command', 'agent_loop_verify'], true),
        ));
        self::assertNotSame([], $commandGates, 'the fixture plan must declare at least one command gate');
        foreach ($commandGates as $gate) {
            self::assertSame('not_run', $gate['status']);
        }
        self::assertSame('failed', $result['objective_gate'], 'a required gate that did not run must not pass');
    }

    public function testRefusesToGradeAnAnswerSheetBoundToADifferentPlan(): void
    {
        $this->runEdit();
        $result = json_decode((string) file_get_contents($this->bundle . '/agent-result.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        $result['verification_plan_sha256'] = 'sha256:' . str_repeat('0', 64);
        file_put_contents($this->bundle . '/agent-result.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        self::assertSame(2, $this->verifyExitCode(), 'a mismatched binding is a hard stop, not a failed gate');
        self::assertFileDoesNotExist($this->bundle . '/verification-result.json');
    }

    public function testVerificationDoesNotTouchTheSharedMapIndex(): void
    {
        $this->runEdit();
        $shared = $this->root . '/.agent-loop/map/php-symbols.json';
        self::assertFileExists($shared);
        $before = (string) file_get_contents($shared);

        file_put_contents($this->root . '/src/UserService.php', (string) file_get_contents($this->root . '/src/UserService.php') . "\n// edited after the map was built\n");
        $this->verify();

        self::assertSame($before, (string) file_get_contents($shared), 'grading must not mutate state the next task reads');
    }

    private function runEdit(): void
    {
        ob_start();
        $exit = (new EditCommand($this->root))->run([
            'Demo\\UserService::save',
            '--task=EDIT-VERIFY',
            '--map-paths=src',
            '--dry-run',
            '--',
            'Reject inactive users before persistence.',
        ]);
        ob_end_clean();

        self::assertSame(0, $exit);
        self::assertFileExists($this->bundle . '/agent-result.json');
    }

    /** @param callable(string): list<string> $answerFor */
    private function answer(callable $answerFor): void
    {
        $file = $this->bundle . '/agent-result.json';
        $result = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($result);

        $answers = [];
        foreach (array_keys($result['probe_answers']) as $probeId) {
            $answers[(string) $probeId] = $answerFor((string) $probeId);
        }
        $result['probe_answers'] = $answers;
        file_put_contents($file, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private function verify(): string
    {
        ob_start();
        (new EditCommand($this->root))->run(['verify', '--bundle=' . $this->bundle]);

        return (string) ob_get_clean();
    }

    private function verifyExitCode(): int
    {
        ob_start();
        $exit = (new EditCommand($this->root))->run(['verify', '--bundle=' . $this->bundle]);
        ob_end_clean();

        return $exit;
    }

    /** @return array<string, mixed> */
    private function resultAfterVerify(): array
    {
        $this->verify();

        return $this->verificationResult();
    }

    /** @return array<string, mixed> */
    private function verificationResult(): array
    {
        $decoded = json_decode((string) file_get_contents($this->bundle . '/verification-result.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function planFile(): string
    {
        return $this->bundle . '/recall/verification-plan.json';
    }

    /** @return array<string, mixed> */
    private function key(): array
    {
        $decoded = json_decode((string) file_get_contents($this->bundle . '/recall/verification-key.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
