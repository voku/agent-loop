<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;

/**
 * Writes the `hooks` object of a Claude Code settings file.
 *
 * Claude Code registers hooks inside `settings.json` rather than in a dedicated
 * hooks file, so the sync owns exactly one key of an otherwise user-owned
 * document: every other setting is read, kept, and written back untouched.
 */
final readonly class ClaudeSettingsHooksWriter
{
    public function __construct(private string $settingsPath)
    {
    }

    public function hasHooks(): bool
    {
        $settings = $this->read();

        return isset($settings['hooks']) && is_array($settings['hooks']) && $settings['hooks'] !== [];
    }

    /**
     * @param array<string, mixed> $hooks
     */
    public function write(array $hooks): void
    {
        $settings = $this->read();
        $settings['hooks'] = $hooks;
        $this->save($settings);
    }

    public function removeHooks(): void
    {
        if (!is_file($this->settingsPath)) {
            return;
        }

        $settings = $this->read();
        unset($settings['hooks']);
        if ($settings === []) {
            if (!unlink($this->settingsPath)) {
                throw new InvalidArgumentException('Unable to remove settings file: ' . $this->settingsPath);
            }

            return;
        }

        $this->save($settings);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        if (!is_file($this->settingsPath)) {
            return [];
        }

        $content = file_get_contents($this->settingsPath);
        if (!is_string($content)) {
            throw new InvalidArgumentException('Unable to read settings file: ' . $this->settingsPath);
        }
        if (trim($content) === '') {
            return [];
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Settings file is not valid JSON: ' . $this->settingsPath);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function save(array $settings): void
    {
        $directory = dirname($this->settingsPath);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new InvalidArgumentException('Unable to create target directory: ' . $directory);
        }

        $json = json_encode($settings, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new InvalidArgumentException('Unable to encode settings file: ' . $this->settingsPath);
        }

        if (file_put_contents($this->settingsPath, $json . "\n") === false) {
            throw new InvalidArgumentException('Unable to write settings file: ' . $this->settingsPath);
        }
    }
}
