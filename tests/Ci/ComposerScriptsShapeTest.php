<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests\Ci;

use PHPUnit\Framework\TestCase;

/**
 * Composer passes everything after `@putenv ` to a single `putenv()` call, so a line like
 * `@putenv A=1 B=2` sets `A` to `1 B=2` and never sets `B`, and `@putenv A=1 && phpunit` sets
 * `A` to `1 && phpunit`. Neither fails; the second variable or command is silently lost.
 *
 * The guard fails closed: a `@putenv` entry is one `NAME=value` (or a bare `NAME`) with no
 * whitespace, so every assignment and every following command is its own script entry.
 */
final class ComposerScriptsShapeTest extends TestCase
{
    private const PUTENV_PREFIX = '@putenv';

    private const SINGLE_ASSIGNMENT = '/^[A-Za-z_][A-Za-z0-9_]*(=\S*)?$/';

    public function testPutenvIsAlwaysItsOwnScriptEntry(): void
    {
        $violations = $this->violations($this->composerScripts());

        self::assertSame([], $violations, implode("\n", $violations));
    }

    public function testTheScannerActuallySeesTheCurrentPutenvEntries(): void
    {
        self::assertContains(
            '@putenv AGENT_LOOP_VERIFY_RELEASE_PIN_SHAS=1',
            $this->putenvEntries($this->composerScripts()),
        );
    }

    public function testAPutenvSettingTwoVariablesIsReported(): void
    {
        self::assertSame(
            ['scripts.example[0]: "@putenv A=1 B=2" must set exactly one variable; split it into one @putenv entry per variable and keep commands in their own entries'],
            $this->violations(['example' => ['@putenv A=1 B=2', 'phpunit']]),
        );
    }

    public function testAPutenvChainedWithACommandIsReported(): void
    {
        self::assertCount(1, $this->violations(['example' => '@putenv A=1 && phpunit']));
    }

    public function testAPutenvWithoutAnAssignmentIsReported(): void
    {
        self::assertCount(1, $this->violations(['example' => ['@putenv']]));
        self::assertCount(1, $this->violations(['example' => ['@putenv  ']]));
    }

    public function testSingleAssignmentsAndUnsetsAreAccepted(): void
    {
        self::assertSame([], $this->violations([
            'example' => ['@putenv A=1', '@putenv B=', '@putenv C', 'phpunit'],
            'other' => '@putenv D=two',
            'unrelated' => 'php -r "putenv(\'A=1 B=2\');"',
        ]));
    }

    /**
     * @param array<string, mixed> $scripts
     *
     * @return list<string>
     */
    private function violations(array $scripts): array
    {
        $violations = [];
        foreach ($scripts as $name => $entries) {
            foreach (\is_array($entries) ? $entries : [$entries] as $index => $entry) {
                if (!\is_string($entry) || !$this->isPutenv($entry)) {
                    continue;
                }

                $assignment = substr($entry, \strlen(self::PUTENV_PREFIX) + 1);
                if (preg_match(self::SINGLE_ASSIGNMENT, $assignment) === 1) {
                    continue;
                }

                $violations[] = \sprintf(
                    'scripts.%s[%s]: "%s" must set exactly one variable; split it into one @putenv entry per variable and keep commands in their own entries',
                    $name,
                    $index,
                    $entry,
                );
            }
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $scripts
     *
     * @return list<string>
     */
    private function putenvEntries(array $scripts): array
    {
        $found = [];
        foreach ($scripts as $entries) {
            foreach (\is_array($entries) ? $entries : [$entries] as $entry) {
                if (\is_string($entry) && $this->isPutenv($entry)) {
                    $found[] = $entry;
                }
            }
        }

        return $found;
    }

    private function isPutenv(string $entry): bool
    {
        return $entry === self::PUTENV_PREFIX || str_starts_with($entry, self::PUTENV_PREFIX . ' ');
    }

    /**
     * @return array<string, mixed>
     */
    private function composerScripts(): array
    {
        $composer = json_decode(
            (string) file_get_contents(\dirname(__DIR__, 2) . '/composer.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($composer);
        self::assertIsArray($composer['scripts'] ?? null);

        /** @var array<string, mixed> $scripts */
        $scripts = $composer['scripts'];

        return $scripts;
    }
}
