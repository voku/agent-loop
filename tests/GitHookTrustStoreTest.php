<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\GitHooks\HookConfigTrustStore;

final class GitHookTrustStoreTest extends TestCase
{
    private string $root;

    private string $storePath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-hook-trust-' . bin2hex(random_bytes(6));
        $this->storePath = $this->root . '-external/hook-trust.json';
        mkdir($this->root . '/.agent-loop', 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
        $this->removeDirectory(dirname($this->storePath));
    }

    public function testExplicitTrustStoresDigestOutsideCheckoutAndAcceptsExactBytes(): void
    {
        $configPath = $this->writeConfig('{"pre_commit":{"checks":[{"command":"true"}]}}');
        $content = (string) file_get_contents($configPath);
        $store = new HookConfigTrustStore($this->storePath);

        self::assertSame(hash('sha256', $content), $store->trust($this->root, $configPath));
        self::assertFileExists($this->storePath);
        self::assertStringStartsNotWith($this->root . '/', $this->storePath);
        $store->assertTrusted($this->root, $configPath, $content);
    }

    public function testMissingTrustIsRefused(): void
    {
        $configPath = $this->writeConfig('{"pre_commit":{"checks":[{"command":"true"}]}}');

        try {
            (new HookConfigTrustStore($this->storePath))->assertTrusted(
                $this->root,
                $configPath,
                (string) file_get_contents($configPath),
            );
            self::fail('A missing trust record must be refused.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('not trusted', strtolower($exception->getMessage()));
        }
    }

    public function testChangedConfigBytesAreRefusedAfterTrust(): void
    {
        $configPath = $this->writeConfig('{"pre_commit":{"checks":[{"command":"true"}]}}');
        $store = new HookConfigTrustStore($this->storePath);
        $store->trust($this->root, $configPath);

        try {
            $store->assertTrusted($this->root, $configPath, '{"pre_commit":{"checks":[{"command":"false"}]}}');
            self::fail('Changed config bytes must be refused.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('changed', strtolower($exception->getMessage()));
        }
    }

    public function testTrustIsScopedToCanonicalRepository(): void
    {
        $configPath = $this->writeConfig('{"pre_commit":{"checks":[{"command":"true"}]}}');
        $otherRoot = $this->root . '-other';
        mkdir($otherRoot . '/.agent-loop', 0o700, true);
        $otherConfig = $otherRoot . '/.agent-loop/githooks.json';
        file_put_contents($otherConfig, (string) file_get_contents($configPath));
        $store = new HookConfigTrustStore($this->storePath);
        $store->trust($this->root, $configPath);

        try {
            $store->assertTrusted($otherRoot, $otherConfig, (string) file_get_contents($otherConfig));
            self::fail('A trust record for another repository must not be accepted.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('not trusted', strtolower($exception->getMessage()));
        } finally {
            $this->removeDirectory($otherRoot);
        }
    }

    public function testTrustStoreInsideCheckoutIsRefused(): void
    {
        $configPath = $this->writeConfig('{"pre_commit":{"checks":[{"command":"true"}]}}');

        try {
            (new HookConfigTrustStore($this->root . '/.agent-loop/hook-trust.json'))->trust($this->root, $configPath);
            self::fail('Trust data must not be stored inside the checkout.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('outside', strtolower($exception->getMessage()));
        }
    }

    public function testSymlinkedStoreAncestorIntoCheckoutIsRefusedBeforeCreatingDirectories(): void
    {
        $configPath = $this->writeConfig('{"pre_commit":{"checks":[{"command":"true"}]}}');
        $outsideLink = $this->root . '-store-parent-link';
        $nestedDirectory = $this->root . '/.agent-loop/nested-store';
        if (!@symlink($this->root . '/.agent-loop', $outsideLink)) {
            self::markTestSkipped('symlink() is not available.');
        }

        try {
            try {
                (new HookConfigTrustStore($outsideLink . '/nested-store/hook-trust.json'))->trust($this->root, $configPath);
                self::fail('A trust-store path that resolves inside the checkout must be refused.');
            } catch (RuntimeException) {
                // Refusal is expected; the important property is that resolution has no filesystem side effect.
            }

            self::assertFalse(is_dir($nestedDirectory), 'The rejected path must not create a directory inside the checkout.');
        } finally {
            if (is_link($outsideLink)) {
                unlink($outsideLink);
            }
        }
    }

    public function testSymlinkedConfigIsRefused(): void
    {
        $outside = $this->root . '-outside.json';
        file_put_contents($outside, '{"pre_commit":{"checks":[{"command":"true"}]}}');
        $link = $this->root . '/.agent-loop/githooks.json';
        if (!@symlink($outside, $link)) {
            unlink($outside);
            self::markTestSkipped('symlink() is not available.');
        }

        try {
            (new HookConfigTrustStore($this->storePath))->trust($this->root, $link);
            self::fail('A symlinked hook config must not be trusted.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('symlink', strtolower($exception->getMessage()));
        } finally {
            if (is_link($link)) {
                unlink($link);
            }
            unlink($outside);
        }
    }

    public function testSymlinkedTrustStoreIsRefused(): void
    {
        $configPath = $this->writeConfig('{"pre_commit":{"checks":[{"command":"true"}]}}');
        $outside = $this->root . '-external-target.json';
        mkdir(dirname($this->storePath), 0o700, true);
        file_put_contents($outside, '{"schema_version":"1.0","repositories":[]}');
        if (!@symlink($outside, $this->storePath)) {
            unlink($outside);
            self::markTestSkipped('symlink() is not available.');
        }

        try {
            (new HookConfigTrustStore($this->storePath))->trust($this->root, $configPath);
            self::fail('A symlinked external trust store must be refused.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('symlink', strtolower($exception->getMessage()));
        } finally {
            if (is_link($this->storePath)) {
                unlink($this->storePath);
            }
            unlink($outside);
        }
    }

    public function testGroupOrWorldWritableTrustStoreFileIsRefused(): void
    {
        $configPath = $this->writeConfig('{"pre_commit":{"checks":[{"command":"true"}]}}');
        $content = (string) file_get_contents($configPath);
        $store = new HookConfigTrustStore($this->storePath);
        $store->trust($this->root, $configPath);
        self::assertTrue(chmod($this->storePath, 0660));

        try {
            $store->assertTrusted($this->root, $configPath, $content);
            self::fail('A group-writable trust record must be refused.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('writable by other users', strtolower($exception->getMessage()));
        }
    }

    public function testUnsafeExistingTrustDirectoryIsRejectedWithoutChangingItsPermissions(): void
    {
        $configPath = $this->writeConfig('{"pre_commit":{"checks":[{"command":"true"}]}}');
        $directory = $this->root . '-unsafe-store-directory';
        mkdir($directory, 0o700, true);
        self::assertTrue(chmod($directory, 0777));
        $permissionsBefore = fileperms($directory) & 0777;

        try {
            (new HookConfigTrustStore($directory . '/hook-trust.json'))->trust($this->root, $configPath);
            self::fail('An unsafe existing trust directory must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('writable by other users', strtolower($exception->getMessage()));
        }

        self::assertSame($permissionsBefore, fileperms($directory) & 0777);
        $this->removeDirectory($directory);
    }

    public function testTrustStoreDirectoryOwnedByAnotherUserIsRefused(): void
    {
        if (!is_dir('/etc') || !is_file('/etc/passwd')) {
            self::markTestSkipped('A stable system-owned directory is not available.');
        }
        $effectiveUserId = $this->currentEffectiveUserId();
        if ($effectiveUserId === null || fileowner('/etc') === $effectiveUserId) {
            self::markTestSkipped('The test cannot distinguish another directory owner from the current user.');
        }

        $configPath = $this->writeConfig('{"pre_commit":{"checks":[{"command":"true"}]}}');
        $content = (string) file_get_contents($configPath);

        try {
            (new HookConfigTrustStore('/etc/passwd'))->assertTrusted($this->root, $configPath, $content);
            self::fail('A trust directory owned by another user must be refused.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('not owned by the current user', strtolower($exception->getMessage()));
        }
    }

    private function currentEffectiveUserId(): ?int
    {
        if (function_exists('posix_geteuid')) {
            return posix_geteuid();
        }
        if (PHP_OS_FAMILY !== 'Linux') {
            return null;
        }

        $status = @file_get_contents('/proc/self/status');
        if (!is_string($status) || preg_match('/^Uid:\\s+\\d+\\s+(\\d+)/m', $status, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function writeConfig(string $content): string
    {
        $path = $this->root . '/.agent-loop/githooks.json';
        file_put_contents($path, $content);

        return $path;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            if (is_link($path)) {
                unlink($path);
            }

            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $child = $path . '/' . $entry;
            if (is_link($child) || is_file($child)) {
                unlink($child);
            } elseif (is_dir($child)) {
                $this->removeDirectory($child);
            }
        }

        rmdir($path);
    }
}
