<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Init\HostRuntimeProbe;
use voku\AgentLoop\Init\InitDoctorCommand;

/**
 * @internal
 */
final class InitDoctorHostCapabilityTest extends TestCase
{
    public function testDoctorReportsCurrentHostCapabilitySupportAndEvidenceWithoutWritingState(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-init-host-capabilities-' . bin2hex(random_bytes(6));
        mkdir($root, 0o775, true);

        try {
            $before = scandir($root);
            self::assertIsArray($before);

            ob_start();
            $exit = (new InitDoctorCommand($root))->run([]);
            $output = (string) ob_get_clean();

            self::assertSame(0, $exit);
            self::assertStringContainsString('Host capabilities [codex]: skill-projection=supported, subagent-projection=supported', $output);
            self::assertStringContainsString('Host capabilities [claude]: skill-projection=supported, subagent-projection=supported', $output);
            self::assertMatchesRegularExpression('/Host capabilities \\[claude\\]: [^\\n]*pre-tool-guardrail=degraded/', $output);
            self::assertMatchesRegularExpression('/Host capabilities \\[claude\\]: [^\\n]*session-bootstrap=degraded/', $output);
            self::assertMatchesRegularExpression('/Host capabilities \\[claude\\]: [^\\n]*subagent-bootstrap=degraded/', $output);
            self::assertMatchesRegularExpression('/Host capabilities \\[claude\\]: [^\\n]*repository-hooks=degraded/', $output);
            self::assertStringContainsString('Host capabilities [copilot]: skill-projection=supported, subagent-projection=supported', $output);
            self::assertMatchesRegularExpression('/Host capabilities \[copilot\]: [^\n]*repository-hooks=unsupported/', $output);
            self::assertStringContainsString('Host capabilities [gemini]: skill-projection=supported, subagent-projection=supported', $output);
            self::assertMatchesRegularExpression('/Host capabilities \[gemini\]: [^\n]*repository-hooks=unsupported/', $output);
            self::assertStringContainsString('Host capabilities [antigravity]: skill-projection=supported, subagent-projection=supported', $output);
            self::assertMatchesRegularExpression('/Host capabilities \[antigravity\]: [^\n]*repository-hooks=unsupported/', $output);
            self::assertStringContainsString('Host capabilities [cursor]: skill-projection=supported, subagent-projection=supported', $output);
            self::assertMatchesRegularExpression('/Host capabilities \\[cursor\\]: [^\\n]*subagent-read-only-enforcement=supported/', $output);
            self::assertMatchesRegularExpression('/Host capabilities \\[cursor\\]: [^\\n]*policy-projection=supported/', $output);
            self::assertMatchesRegularExpression('/Host capabilities \\[cursor\\]: [^\\n]*pre-tool-guardrail=degraded/', $output);
            self::assertMatchesRegularExpression('/Host capabilities \\[cursor\\]: [^\\n]*repository-hooks=degraded/', $output);

            self::assertStringContainsString(
                'Host capability evidence [codex/skill-projection]: mechanism=SKILL.md -> Codex skills directory; evidence=adapter-declared',
                $output,
            );
            self::assertStringContainsString(
                'Host capability evidence [codex/pre-tool-guardrail]: mechanism=Codex hooks.json PreToolUse ^Bash$ command hook; Codex 0.157.0 canonical exec_command/Bash runtime proof observed deny enforcement; live-runtime evidence unverified (tested=0.157.0, current=unknown); evidence=adapter-declared;live-runtime-unverified',
                $output,
            );
            self::assertStringContainsString(
                'Host capability evidence [claude/session-bootstrap]: mechanism=Claude project settings granular hook registrations + repository-local command hooks; evidence=adapter-declared;live-runtime-unverified',
                $output,
            );
            self::assertStringContainsString(
                'Host capability evidence [claude/pre-tool-guardrail]: mechanism=Claude project settings PreToolUse ^Bash$ command hook; Claude 2.1.287 runtime proof observed bounded alternate-publication deny enforcement under bypassPermissions; live-runtime evidence unverified (tested=2.1.287, current=unknown); evidence=adapter-declared;live-runtime-unverified',
                $output,
            );
            self::assertStringContainsString(
                'Host capability evidence [copilot/pre-tool-guardrail]: mechanism=no agent-loop host-native projector; evidence=no-agent-loop-projector',
                $output,
            );
            self::assertStringContainsString(
                'Host capability evidence [cursor/pre-tool-guardrail]: mechanism=Cursor .cursor/hooks.json beforeShellExecution + repository-local fail-closed shell authority guard; evidence=adapter-declared;live-runtime-unverified',
                $output,
            );
            self::assertStringContainsString('Host runtime [gemini]: ', $output);
            self::assertStringContainsString('Host runtime [cursor]: unprobed', $output);
            self::assertStringNotContainsString('live-runtime-observed', $output);
            self::assertSame($before, scandir($root));
        } finally {
            rmdir($root);
        }
    }

    public function testDoctorRestoresVerificationOnlyForTheExactDetectedVersion(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-init-host-version-' . bin2hex(random_bytes(6));
        $binRoot = $root . '/bin';
        if (!mkdir($binRoot, 0o775, true) && !is_dir($binRoot)) {
            throw new RuntimeException('Unable to create version fixture root.');
        }

        try {
            $codex = $binRoot . '/codex';
            $claude = $binRoot . '/claude';
            $this->writeExecutable($codex, "#!/bin/sh\nprintf '%s\\n' 'codex-cli 0.157.0'\n");
            $this->writeExecutable($claude, "#!/bin/sh\nprintf '%s\\n' '2.1.287 (Claude Code)'\n");

            $output = $this->doctorOutput($root, $binRoot);
            self::assertStringContainsString('Host runtime [codex]: available;', $output);
            self::assertStringContainsString('version=0.157.0', $output);
            self::assertStringContainsString('Host capability evidence [codex/pre-tool-guardrail]:', $output);
            self::assertStringContainsString('; evidence=adapter-declared;live-runtime-verified', $output);
            self::assertStringContainsString('Host runtime [claude]: available;', $output);
            self::assertStringContainsString('version=2.1.287', $output);

            $this->writeExecutable($codex, "#!/bin/sh\nprintf '%s\\n' 'codex-cli 0.158.0'\n");
            $drifted = $this->doctorOutput($root, $binRoot);
            self::assertStringContainsString('current=0.158.0', $drifted);
            self::assertStringContainsString('evidence=adapter-declared;live-runtime-unverified', $drifted);
        } finally {
            foreach ([$binRoot . '/codex', $binRoot . '/claude'] as $path) {
                if (is_file($path) && !unlink($path)) {
                    throw new RuntimeException('Unable to remove version fixture binary.');
                }
            }
            if (is_dir($binRoot) && !rmdir($binRoot)) {
                throw new RuntimeException('Unable to remove version fixture directory.');
            }
            if (is_dir($root) && !rmdir($root)) {
                throw new RuntimeException('Unable to remove doctor fixture directory.');
            }
        }
    }

    private function doctorOutput(string $root, string $binRoot): string
    {
        ob_start();
        $exit = (new InitDoctorCommand($root, new HostRuntimeProbe($binRoot)))->run([]);
        $output = (string) ob_get_clean();
        self::assertSame(0, $exit);

        return $output;
    }

    private function writeExecutable(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false || !chmod($path, 0o755)) {
            throw new RuntimeException('Unable to create executable version fixture.');
        }
    }
}
