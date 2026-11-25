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
            $payload = ['session_id' => $preference->sessionId, 'default' => $preference->defaultMode, 'tasks' => $preference->taskOverrides];
            if ($preference->language !== null) {
                $payload['language'] = $preference->language;
            }
            $data = json_encode($payload, JSON_THROW_ON_ERROR);
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
            // A task override of a session that only inherits the shared default binds
            // it in the same write, so a rejected mode leaves the session unbound.
            $existing = $taskId === null ? $this->find($sessionId) : $this->effective($sessionId);
            if ($taskId !== null) {
                if ($existing === null) {
                    throw new RuntimeException('Set a session default before setting a task override.');
                }
                $overrides = $existing->taskOverrides;
                if ($mode === 'inherit') {
                    unset($overrides[$taskId]);
                } else {
                    $overrides[$taskId] = $mode;
                }
                $preference = new InteractionPreference($sessionId, $existing->defaultMode, $overrides, $existing->language);
            } else {
                $preference = new InteractionPreference($sessionId, $mode, $existing !== null ? $existing->taskOverrides : [], $existing?->language);
            }
            $this->save($preference);
        } finally {
            fclose($lock);
        }
    }

    /**
     * Set or clear (null) the presentation language of an existing session
     * preference. A language is not a mode, so it never creates a preference.
     */
    public function setLanguage(string $sessionId, ?string $language): void
    {
        if (trim($sessionId) === '') {
            throw new RuntimeException('A host session identity is required.');
        }
        if (!is_dir($this->directory)) {
            throw new RuntimeException('Set a session default before setting a session language.');
        }
        $lock = fopen($this->path($sessionId) . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open interaction preference lock.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot lock interaction preference.');
            }
            $existing = $this->find($sessionId)
                ?? throw new RuntimeException('Set a session default before setting a session language.');
            $this->save(new InteractionPreference($sessionId, $existing->defaultMode, $existing->taskOverrides, $language));
        } finally {
            fclose($lock);
        }
    }

    /** Serialize session-default changes with publication of the shared default. */
    public function setDefault(string $sessionId, string $mode): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Cannot create interaction preference directory.');
        }
        $lock = fopen($this->directory . '/default.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open shared preference lock.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot lock shared preference.');
            }
            $this->update($sessionId, $mode, null);
            try {
                $this->update("\0host-default", $mode, null);
            } catch (\Throwable $exception) {
                throw new RuntimeException('Session preference was saved, but the shared host default was not: ' . $exception->getMessage(), 0, $exception);
            }
        } finally {
            fclose($lock);
        }
    }

    /** Bind a new host session to the current shared default exactly once. */
    public function bindSession(string $sessionId): ?InteractionPreference
    {
        if (trim($sessionId) === '') {
            return null;
        }
        $this->assertSearchable();
        if (!is_dir($this->directory)) {
            return null;
        }
        $lock = fopen($this->path($sessionId) . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open interaction preference lock.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot lock interaction preference.');
            }
            $current = $this->find($sessionId);
            if ($current !== null) {
                return $current;
            }
            $default = $this->find("\0host-default");
            if ($default === null) {
                return null;
            }
            $current = new InteractionPreference($sessionId, $default->defaultMode);
            $this->save($current);
            return $current;
        } finally {
            fclose($lock);
        }
    }

    /**
     * The session's preference or, before it is bound, the shared default it
     * would be bound to. Never writes, so read-only projections may use it.
     */
    public function effective(string $sessionId): ?InteractionPreference
    {
        $current = $this->find($sessionId);
        if ($current !== null || trim($sessionId) === '') {
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
        $this->assertSearchable();
        $path = $this->path($sessionId);
        if (is_link($path)) {
            throw new RuntimeException('Interaction preference must not be a symlink.');
        }
        if (!is_file($path)) {
            return null;
        }
        $contents = is_readable($path) ? file_get_contents($path) : false;
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
        $language = $data['language'] ?? null;
        if ($language !== null && !is_string($language)) {
            throw new RuntimeException('Invalid interaction preference language.');
        }

        return new InteractionPreference($sessionId, $data['default'], $data['tasks'], $language);
    }

    /** Without search permission, also on a parent, every file looks absent; that is not "no preference". */
    private function assertSearchable(): void
    {
        $nearest = $this->directory;
        while (!file_exists($nearest) && dirname($nearest) !== $nearest) {
            $nearest = dirname($nearest);
        }
        // is_executable() describes search permission of a directory on POSIX only.
        if (!is_dir($nearest) || (DIRECTORY_SEPARATOR === '/' && !is_executable($nearest))) {
            throw new RuntimeException('Cannot read interaction preference directory.');
        }
    }

    private function path(string $sessionId): string
    {
        return $this->directory . '/' . hash('sha256', $sessionId) . '.json';
    }
}
