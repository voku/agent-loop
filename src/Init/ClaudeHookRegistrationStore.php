<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use Throwable;

/**
 * Crash-recoverable persistence for Claude hook settings plus the ownership receipt.
 *
 * A transaction marker is written only after both previous states are snapshotted
 * and both desired payloads are staged. Reads recover an interrupted transaction
 * before exposing either file to the projector.
 */
final readonly class ClaudeHookRegistrationStore
{
    public function __construct(private string $rootPath)
    {
    }

    public function settingsPath(): string
    {
        return rtrim($this->rootPath, '/') . '/.claude/settings.json';
    }

    public function receiptPath(): string
    {
        return rtrim($this->rootPath, '/') . '/.claude/hooks/.agent-loop-registration.json';
    }

    public function readSettingsContent(): ?string
    {
        $this->recover();

        return $this->readOptional($this->settingsPath());
    }

    public function readReceiptContent(): ?string
    {
        $this->recover();

        return $this->readOptional($this->receiptPath());
    }

    public function commit(?string $settingsContent, string $receiptContent): void
    {
        $this->recover();
        $this->ensureDirectories();
        $this->cleanupUnjournaledArtifacts();

        if ($settingsContent !== null) {
            $this->writeStaged($this->settingsTempPath(), $settingsContent);
        }
        $this->writeStaged($this->receiptTempPath(), $receiptContent);

        $this->snapshot($this->settingsPath(), $this->settingsBackupPath(), $this->settingsAbsentPath());
        $this->snapshot($this->receiptPath(), $this->receiptBackupPath(), $this->receiptAbsentPath());

        $this->writeStaged($this->journalTempPath(), "v1\n");
        $this->replace($this->journalTempPath(), $this->journalPath());

        try {
            $this->replaceOptional($this->settingsTempPath(), $this->settingsPath(), $settingsContent !== null);
            $this->replace($this->receiptTempPath(), $this->receiptPath());
            $this->replace($this->journalPath(), $this->committedMarkerPath());
        } catch (Throwable $exception) {
            try {
                $this->recover();
            } catch (Throwable $recoveryFailure) {
                throw new InvalidArgumentException(
                    'Claude hook registration transaction failed and rollback also failed: '
                    . $recoveryFailure->getMessage(),
                    0,
                    $exception,
                );
            }

            throw new InvalidArgumentException(
                'Claude hook registration transaction failed and was rolled back: ' . $exception->getMessage(),
                0,
                $exception,
            );
        }

        $this->cleanupUnjournaledArtifacts();
    }

    public function recover(): void
    {
        if (!is_file($this->journalPath())) {
            return;
        }

        $this->ensureDirectories();
        $this->restoreSnapshot(
            $this->settingsPath(),
            $this->settingsBackupPath(),
            $this->settingsAbsentPath(),
            $this->settingsRestorePath(),
        );
        $this->restoreSnapshot(
            $this->receiptPath(),
            $this->receiptBackupPath(),
            $this->receiptAbsentPath(),
            $this->receiptRestorePath(),
        );

        $this->replace($this->journalPath(), $this->committedMarkerPath());
        $this->cleanupUnjournaledArtifacts();
    }

    private function snapshot(string $target, string $backup, string $absent): void
    {
        $this->unlinkIfExists($backup);
        $this->unlinkIfExists($absent);

        if (is_file($target)) {
            if (!copy($target, $backup)) {
                throw new InvalidArgumentException('Unable to snapshot Claude hook registration file: ' . $target);
            }

            return;
        }

        if (file_put_contents($absent, '') === false) {
            throw new InvalidArgumentException('Unable to record absent Claude hook registration file: ' . $target);
        }
    }

    private function restoreSnapshot(string $target, string $backup, string $absent, string $restore): void
    {
        if (is_file($backup)) {
            if (!copy($backup, $restore)) {
                throw new InvalidArgumentException('Unable to stage Claude hook rollback file: ' . $target);
            }
            $this->replace($restore, $target);

            return;
        }

        if (is_file($absent)) {
            $this->unlinkIfExists($target);

            return;
        }

        throw new InvalidArgumentException('Claude hook transaction journal has no recoverable snapshot for: ' . $target);
    }

    private function replaceOptional(string $staged, string $target, bool $present): void
    {
        if (!$present) {
            $this->unlinkIfExists($target);

            return;
        }

        $this->replace($staged, $target);
    }

    private function replace(string $staged, string $target): void
    {
        if (!is_file($staged)) {
            throw new InvalidArgumentException('Claude hook staged file is missing: ' . $staged);
        }

        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new InvalidArgumentException('Unable to create Claude hook target directory: ' . $directory);
        }

        $this->unlinkIfExists($target);
        if (!rename($staged, $target)) {
            throw new InvalidArgumentException('Unable to replace Claude hook file: ' . $target);
        }
    }

    private function writeStaged(string $path, string $content): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new InvalidArgumentException('Unable to create Claude hook staging directory: ' . $directory);
        }
        if (file_put_contents($path, $content) === false) {
            throw new InvalidArgumentException('Unable to stage Claude hook file: ' . $path);
        }
    }

    private function readOptional(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if (!is_string($content)) {
            throw new InvalidArgumentException('Unable to read Claude hook file: ' . $path);
        }

        return $content;
    }

    private function ensureDirectories(): void
    {
        foreach ([dirname($this->settingsPath()), dirname($this->receiptPath())] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
                throw new InvalidArgumentException('Unable to create Claude hook directory: ' . $directory);
            }
        }
    }

    private function cleanupUnjournaledArtifacts(): void
    {
        foreach ($this->artifactPaths() as $path) {
            $this->unlinkIfExists($path);
        }
    }

    /** @return list<string> */
    private function artifactPaths(): array
    {
        return [
            $this->settingsTempPath(),
            $this->receiptTempPath(),
            $this->settingsBackupPath(),
            $this->receiptBackupPath(),
            $this->settingsAbsentPath(),
            $this->receiptAbsentPath(),
            $this->settingsRestorePath(),
            $this->receiptRestorePath(),
            $this->journalTempPath(),
            $this->committedMarkerPath(),
        ];
    }

    private function unlinkIfExists(string $path): void
    {
        if (is_file($path) && !unlink($path)) {
            throw new InvalidArgumentException('Unable to remove Claude hook transaction file: ' . $path);
        }
    }

    private function journalPath(): string
    {
        return rtrim($this->rootPath, '/') . '/.claude/.agent-loop-hook-registration.txn';
    }

    private function journalTempPath(): string
    {
        return $this->journalPath() . '.new';
    }

    private function committedMarkerPath(): string
    {
        return $this->journalPath() . '.done';
    }

    private function settingsTempPath(): string
    {
        return rtrim($this->rootPath, '/') . '/.claude/.agent-loop-settings.new';
    }

    private function receiptTempPath(): string
    {
        return rtrim($this->rootPath, '/') . '/.claude/hooks/.agent-loop-registration.new';
    }

    private function settingsBackupPath(): string
    {
        return rtrim($this->rootPath, '/') . '/.claude/.agent-loop-settings.bak';
    }

    private function receiptBackupPath(): string
    {
        return rtrim($this->rootPath, '/') . '/.claude/hooks/.agent-loop-registration.bak';
    }

    private function settingsAbsentPath(): string
    {
        return rtrim($this->rootPath, '/') . '/.claude/.agent-loop-settings.absent';
    }

    private function receiptAbsentPath(): string
    {
        return rtrim($this->rootPath, '/') . '/.claude/hooks/.agent-loop-registration.absent';
    }

    private function settingsRestorePath(): string
    {
        return rtrim($this->rootPath, '/') . '/.claude/.agent-loop-settings.restore';
    }

    private function receiptRestorePath(): string
    {
        return rtrim($this->rootPath, '/') . '/.claude/hooks/.agent-loop-registration.restore';
    }
}
