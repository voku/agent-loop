<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Die ausgelieferten Hook-Vorlagen duerfen ihre Skripte nicht ueber einen relativen Pfad
 * aufrufen. `sync-hooks` schreibt diese Kommandos unveraendert in die Client-Konfiguration,
 * und der Client fuehrt sie nicht zwingend im Repository-Wurzelverzeichnis aus -- dann
 * scheitert der Hook mit "Could not open input file" und die Sitzung verliert ihn
 * stillschweigend.
 *
 * @internal
 */
final class ShippedHookCommandPathTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function shippedBundles(): array
    {
        return [
            'claude' => [__DIR__ . '/../resources/hooks/claude/hooks.json', '.claude'],
            'codex'  => [__DIR__ . '/../resources/hooks/codex/hooks.json', '.codex'],
        ];
    }

    #[DataProvider('shippedBundles')]
    public function testShippedHookCommandsResolveIndependentlyOfTheWorkingDirectory(
        string $hooksJsonPath,
        string $clientDirectory,
    ): void {
        foreach (self::commandsOf($hooksJsonPath) as $event => $command) {
            self::assertStringNotContainsString(
                'php ' . $clientDirectory . '/hooks/',
                $command,
                $event . ' hook command must not call its script through a relative path.',
            );
            self::assertStringContainsString(
                '$(git rev-parse --show-toplevel)/' . $clientDirectory . '/hooks/',
                $command,
                $event . ' hook command must resolve the repository root before calling its script.',
            );
        }
    }

    #[DataProvider('shippedBundles')]
    public function testShippedHookCommandsStayValidForTheirClient(
        string $hooksJsonPath,
        string $clientDirectory,
    ): void {
        // Der Validator kennt genau zwei erlaubte Kommandoformen; die Vorlage muss eine davon treffen.
        $errors = \voku\AgentLoop\Init\HooksDefinition::validationErrors(
            \dirname($hooksJsonPath),
            $clientDirectory,
        );

        self::assertSame([], $errors);
    }

    /**
     * @return array<string, string>
     */
    private static function commandsOf(string $hooksJsonPath): array
    {
        $raw = file_get_contents($hooksJsonPath);
        self::assertIsString($raw, $hooksJsonPath . ' must be readable');

        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('hooks', $decoded);
        self::assertIsArray($decoded['hooks']);

        $commands = [];
        foreach ($decoded['hooks'] as $event => $groups) {
            self::assertIsArray($groups);
            foreach ($groups as $groupIndex => $group) {
                self::assertIsArray($group);
                self::assertIsArray($group['hooks'] ?? null);
                foreach ($group['hooks'] as $hookIndex => $hook) {
                    self::assertIsArray($hook);
                    self::assertIsString($hook['command'] ?? null);
                    $commands[$event . '[' . $groupIndex . '][' . $hookIndex . ']'] = $hook['command'];
                }
            }
        }

        self::assertNotSame([], $commands, $hooksJsonPath . ' must declare at least one hook command');

        return $commands;
    }
}
