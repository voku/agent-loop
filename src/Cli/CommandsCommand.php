<?php

declare(strict_types=1);

namespace voku\AgentLoop\Cli;

use voku\AgentLoop\AgentOutput;

/**
 * CLI command to discover available commands in machine-readable or grouped text format.
 */
final readonly class CommandsCommand
{
    /**
     * @param list<string> $tokens
     */
    public function run(array $tokens): int
    {
        $format = OptionTokens::value($tokens, 'format') ?? 'text';

        return match ($format) {
            'json' => $this->renderJson(),
            'toon' => $this->renderToon(),
            'text' => $this->renderText(),
            default => $this->renderUnknownFormat($format),
        };
    }

    private function renderJson(): int
    {
        $payload = $this->buildPayload();
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

        return 0;
    }

    private function renderToon(): int
    {
        $payload = $this->buildPayload();
        echo AgentOutput::toon($payload);

        return 0;
    }

    private function renderText(): int
    {
        echo CommandCatalog::renderGrouped();

        return 0;
    }

    private function renderUnknownFormat(string $format): int
    {
        fwrite(STDERR, "[FAIL] commands: supported format is text|json|toon, got {$format}.\n");

        return 1;
    }

    /**
     * @return array{
     *     schema_version: int,
     *     commands: list<array{
     *         id: string,
     *         group: string,
     *         owner: string,
     *         summary: string,
     *         usage: string|null,
     *     }>
     * }
     */
    private function buildPayload(): array
    {
        $commands = [];
        foreach (CommandCatalog::all() as $descriptor) {
            $commands[] = $descriptor->toArray();
        }

        return [
            'schema_version' => 1,
            'commands' => $commands,
        ];
    }
}
