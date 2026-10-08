<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Init\FirstPartyPackageCatalog;

/**
 * @internal
 */
final class ProjectInstructionPlaceholdersTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-placeholders-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/.agent-loop', 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/.agent-loop/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root . '/.agent-loop');
        rmdir($this->root);
    }

    public function testLearningRootPlaceholderNamesTheConfiguredRootRelativeToTheProject(): void
    {
        file_put_contents($this->root . '/.agent-loop/init.json', json_encode(['paths' => ['learning_root' => 'infra/doc/agent-learning']], \JSON_THROW_ON_ERROR));

        $text = FirstPartyPackageCatalog::resolveProjectPlaceholders('Findings live below `{{learning_root}}/`.', $this->root);

        self::assertSame('Findings live below `infra/doc/agent-learning/`.', $text);
    }

    public function testLearningRootPlaceholderFallsBackToTheDefaultStateRoot(): void
    {
        $text = FirstPartyPackageCatalog::resolveProjectPlaceholders('Below `{{learning_root}}/`.', $this->root);

        self::assertSame('Below `.agent-loop/learning/`.', $text);
    }

    public function testTextWithoutPlaceholdersIsReturnedUnchanged(): void
    {
        self::assertSame('Plain text', FirstPartyPackageCatalog::resolveProjectPlaceholders('Plain text', $this->root));
    }
}
