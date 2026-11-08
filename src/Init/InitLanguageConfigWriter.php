<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use JsonException;
use RuntimeException;
use stdClass;

/**
 * Narrow, locked, atomic writer for an explicitly accepted language setting.
 * Never writes to unrelated project files or executes project-owned code.
 */
final readonly class InitLanguageConfigWriter
{
    private const int MAX_CONFIG_BYTES = 1048576;

    public function __construct(private string $rootPath)
    {
    }

    /** @return 'applied'|'unchanged' */
    public function apply(string $language, string $expectedEvidenceSha256): string
    {
        $root = realpath($this->rootPath);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('Repository root is not an existing directory.');
        }

        $directory = $root . '/.agent-loop';
        $this->assertSafeDirectory($directory);
        $lockPath = $directory . '/.init-language.lock';
        if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) {
            throw new RuntimeException('Init language lock is not a regular file.');
        }

        $handle = fopen($lockPath, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open init language mutation lock.');
        }

        try {
            if (!$this->isSameFile($handle, $lockPath)) {
                throw new RuntimeException('Init language lock changed during acquisition.');
            }
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to acquire init language mutation lock.');
            }

            if (!$this->isSameFile($handle, $lockPath)) {
                throw new RuntimeException('Init language lock changed while waiting for the lock.');
            }

            return $this->applyLocked($root, $directory, $language, $expectedEvidenceSha256);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return 'applied'|'unchanged' */
    private function applyLocked(string $root, string $directory, string $language, string $expectedEvidenceSha256): string
    {
        $this->assertSafeDirectory($directory);
        $plan = (new InitPlanCommand($root))->inspect($language);
        if (!hash_equals($plan['evidence_sha256'], $expectedEvidenceSha256)) {
            throw new RuntimeException('Setup evidence changed before write; refresh the reviewed plan.');
        }

        if ($plan['review_diffs'] === []) {
            return 'unchanged';
        }

        $target = $directory . '/init.json';
        $original = $this->readTarget($target);
        $updated = $this->withLanguage($original, $language);
        $this->publishAtomic($directory, $target, $original, $updated);

        return 'applied';
    }

    private function withLanguage(?string $original, string $language): string
    {
        $object = $this->decodeConfig($original);
        if (!property_exists($object, 'prompting')) {
            $object->prompting = new stdClass();
        }
        if (!$object->prompting instanceof stdClass) {
            throw new RuntimeException('Existing prompting config is not a JSON object.');
        }
        $object->prompting->language = strtolower($language);

        return json_encode($object, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    private function publishAtomic(string $directory, string $target, ?string $original, string $updated): void
    {
        $temporary = tempnam($directory, '.init-language-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to create temporary init config.');
        }

        try {
            if (dirname($temporary) !== $directory) {
                throw new RuntimeException('Temporary init config escaped the project directory.');
            }
            if (file_put_contents($temporary, $updated, LOCK_EX) !== strlen($updated)) {
                throw new RuntimeException('Unable to write complete temporary init config.');
            }
            $permissions = $original === null ? 0o600 : fileperms($target);
            if ($permissions === false || !chmod($temporary, $permissions & 0o777)) {
                throw new RuntimeException('Unable to preserve init config permissions.');
            }

            $this->assertSafeDirectory($directory);
            if ($this->readTarget($target) !== $original) {
                throw new RuntimeException('Init config changed during write; refusing to overwrite it.');
            }
            if (!rename($temporary, $target)) {
                throw new RuntimeException('Unable to atomically replace init config.');
            }
        } finally {
            if (is_file($temporary) && !unlink($temporary)) {
                throw new RuntimeException('Unable to remove abandoned temporary init config.');
            }
        }
    }

    private function assertSafeDirectory(string $directory): void
    {
        if (is_link($directory) || !is_dir($directory) || realpath($directory) !== $directory) {
            throw new RuntimeException('Expected an existing, non-symlinked .agent-loop directory; run init scaffold first.');
        }
    }

    /** @param resource $handle */
    private function isSameFile($handle, string $path): bool
    {
        $opened = fstat($handle);
        $visible = lstat($path);

        return is_array($opened) && is_array($visible)
            && ($visible['mode'] & 0o170000) === 0o100000
            && $opened['dev'] === $visible['dev'] && $opened['ino'] === $visible['ino'];
    }

    private function readTarget(string $target): ?string
    {
        if (is_link($target) || (file_exists($target) && !is_file($target))) {
            throw new RuntimeException('Init config target must be a regular file, not a symlink.');
        }
        if (!file_exists($target)) {
            return null;
        }
        $size = filesize($target);
        if ($size === false || $size > self::MAX_CONFIG_BYTES) {
            throw new RuntimeException('Init config is unreadable or exceeds the bounded input limit.');
        }
        $content = file_get_contents($target);
        if (!is_string($content) || strlen($content) > self::MAX_CONFIG_BYTES) {
            throw new RuntimeException('Unable to read bounded init config.');
        }

        return $content;
    }

    private function decodeConfig(?string $raw): stdClass
    {
        if ($raw === null) {
            return (object) ['version' => 1];
        }
        try {
            $decoded = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Existing init config is malformed JSON.', 0, $exception);
        }
        if (!$decoded instanceof stdClass) {
            throw new RuntimeException('Existing init config must be a JSON object.');
        }

        return $decoded;
    }
}
