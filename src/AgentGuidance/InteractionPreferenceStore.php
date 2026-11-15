<?php

declare(strict_types=1);

namespace voku\AgentLoop\AgentGuidance;

use JsonException;
use RuntimeException;

/** Stores host session preferences independently of task and project configuration. */
final readonly class InteractionPreferenceStore
{
    public function __construct(private string $directory)
    {
    }

    private function save(InteractionPreference $preference): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Cannot create interaction preference directory.');
        }
        $path = $this->path($preference->sessionId);
        $temporary = tempnam($this->directory, '.interaction-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create interaction preference file.');
        }
        try {
            $data = json_encode(['session_id' => $preference->sessionId, 'default' => $preference->defaultMode, 'tasks' => $preference->taskOverrides], JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $data, LOCK_EX) === false || !rename($temporary, $path)) {
                throw new RuntimeException('Cannot save interaction preference.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function update(string $sessionId, string $mode, ?string $taskId): void
    {
        if (trim($sessionId) === '') {
            throw new RuntimeException('A host session identity is required.');
        }
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Cannot create interaction preference directory.');
        }
        $lock = fopen($this->path($sessionId) . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open interaction preference lock.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot lock interaction preference.');
            }
            $existing = $this->forSession($sessionId);
            if ($taskId !== null) {
                if ($existing === null) {
                    throw new RuntimeException('Set a session default before setting a task override.');
                }
                $overrides = $existing->taskOverrides;
                $overrides[$taskId] = $mode;
                $preference = new InteractionPreference($sessionId, $existing->defaultMode, $overrides);
            } else {
                $preference = new InteractionPreference($sessionId, $mode, $existing !== null ? $existing->taskOverrides : []);
            }
            $this->save($preference);
        } finally {
            fclose($lock);
        }
    }

    public function forSession(string $sessionId): ?InteractionPreference
    {
        if (trim($sessionId) === '') {
            return null;
        }
        $current = $this->find($sessionId);
        if ($current !== null) {
            return $current;
        }
        $default = $this->find("\0host-default");
        return $default === null ? null : new InteractionPreference($sessionId, $default->defaultMode);
    }

    public function find(string $sessionId): ?InteractionPreference
    {
        if (trim($sessionId) === '') {
            return null;
        }
        $path = $this->path($sessionId);
        if (is_link($path)) {
            throw new RuntimeException('Interaction preference must not be a symlink.');
        }
        if (!is_file($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Cannot read interaction preference.');
        }
        try {
            $data = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid interaction preference.', 0, $exception);
        }
        if (!is_array($data) || ($data['session_id'] ?? null) !== $sessionId || !is_string($data['default'] ?? null) || !is_array($data['tasks'] ?? null)) {
            throw new RuntimeException('Invalid interaction preference.');
        }
        foreach ($data['tasks'] as $mode) {
            if (!is_string($mode)) {
                throw new RuntimeException('Invalid interaction preference override.');
            }
        }
        return new InteractionPreference($sessionId, $data['default'], $data['tasks']);
    }

    private function path(string $sessionId): string
    {
        return $this->directory . '/' . hash('sha256', $sessionId) . '.json';
    }
}
