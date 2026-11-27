<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\AgentGuidance\InteractionPreferenceStore;
use voku\AgentLoop\Workflow\WorkflowInteractionCommand;
use voku\AgentLoop\Workflow\WorkflowPresentationLanguage;

/**
 * A host session can present decision text in its own language without
 * changing the project's prompting.language. Presentation only.
 *
 * @internal
 */
final class WorkflowPresentationLanguageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-language-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/interaction', 0o775, true);
        putenv('AGENT_LOOP_INTERACTION_DIR=' . $this->root . '/interaction');
        putenv('AGENT_LOOP_HOST_SESSION_ID');
    }

    protected function tearDown(): void
    {
        putenv('AGENT_LOOP_INTERACTION_DIR');
        putenv('AGENT_LOOP_HOST_SESSION_ID');
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testNormalizeAcceptsBcp47AndRejectsOtherShapes(): void
    {
        self::assertSame('de-de', WorkflowPresentationLanguage::normalize('de-DE'));
        self::assertSame('en', WorkflowPresentationLanguage::normalize('en'));

        $this->expectException(InvalidArgumentException::class);
        WorkflowPresentationLanguage::normalize('DE_de');
    }

    public function testSessionLanguageOverridesOnlyThatSession(): void
    {
        self::assertSame(0, $this->interaction(['sess-a', 'collaborative']));
        self::assertSame(0, $this->interaction(['sess-a', '--language=de-DE']));
        self::assertSame(0, $this->interaction(['sess-b', 'collaborative']));

        self::assertSame(WorkflowPresentationLanguage::SOURCE_PROJECT, WorkflowPresentationLanguage::resolve($this->root)->source);
        self::assertSame('en', WorkflowPresentationLanguage::resolve($this->root)->language);

        putenv('AGENT_LOOP_HOST_SESSION_ID=sess-a');
        $resolved = WorkflowPresentationLanguage::resolve($this->root);
        self::assertSame('de-de', $resolved->language);
        self::assertSame(WorkflowPresentationLanguage::SOURCE_HOST_SESSION, $resolved->source);
        self::assertTrue($resolved->isGerman());

        putenv('AGENT_LOOP_HOST_SESSION_ID=sess-b');
        self::assertSame('en', WorkflowPresentationLanguage::resolve($this->root)->language);
    }

    public function testLanguageSurvivesModeChangesAndInheritClearsIt(): void
    {
        self::assertSame(0, $this->interaction(['sess-a', 'collaborative', '--language=de-DE']));
        self::assertSame(0, $this->interaction(['sess-a', 'autonomous']));
        $store = new InteractionPreferenceStore($this->root . '/interaction');
        $preference = $store->find('sess-a');
        self::assertNotNull($preference);
        self::assertSame('de-de', $preference->language);
        self::assertSame('autonomous', $preference->defaultMode);

        self::assertSame(0, $this->interaction(['sess-a', '--language=inherit']));
        $cleared = $store->find('sess-a');
        self::assertNotNull($cleared);
        self::assertNull($cleared->language);
    }

    public function testLanguageWithoutASessionPreferenceOrWithABadTagIsRefused(): void
    {
        self::assertSame(1, $this->interaction(['sess-new', '--language=de-DE']));
        self::assertNull((new InteractionPreferenceStore($this->root . '/interaction'))->find('sess-new'));

        self::assertSame(0, $this->interaction(['sess-a', 'collaborative']));
        self::assertSame(1, $this->interaction(['sess-a', '--language=DE_de']));
        self::assertSame(1, $this->interaction(['sess-a', '--language=de', '--language=en']));
        self::assertNull((new InteractionPreferenceStore($this->root . '/interaction'))->find('sess-a')?->language);
    }

    public function testDefaultLanguageFollowsTheUserIntoNewSessionsAndASessionLanguageStillWins(): void
    {
        self::assertSame(1, $this->interaction(['--default-language=de']), 'A default language needs a shared default mode first.');
        self::assertSame(1, $this->interaction(['sess-x', 'collaborative', 'TASK-1', '--default-language=de']));
        self::assertNull((new InteractionPreferenceStore($this->root . '/interaction'))->find('sess-x'), 'A refused default language leaves no half-saved task override.');
        self::assertSame(0, $this->interaction(['sess-a', 'collaborative']));
        self::assertSame(0, $this->interaction(['--default-language=de-DE']));

        putenv('AGENT_LOOP_HOST_SESSION_ID=sess-new');
        $fresh = WorkflowPresentationLanguage::resolve($this->root);
        self::assertSame(['de-de', WorkflowPresentationLanguage::SOURCE_USER_DEFAULT], [$fresh->language, $fresh->source], 'A session never seen before uses the default language.');

        self::assertSame(0, $this->interaction(['sess-new', '--language=fr']));
        $override = WorkflowPresentationLanguage::resolve($this->root);
        self::assertSame(['fr', WorkflowPresentationLanguage::SOURCE_HOST_SESSION], [$override->language, $override->source]);

        self::assertSame(0, $this->interaction(['sess-a', 'autonomous']));
        self::assertSame('de-de', (new InteractionPreferenceStore($this->root . '/interaction'))->defaultLanguage(), 'Changing the shared default mode keeps the default language.');

        self::assertSame(0, $this->interaction(['--default-language=inherit']));
        putenv('AGENT_LOOP_HOST_SESSION_ID=sess-other');
        $project = WorkflowPresentationLanguage::resolve($this->root);
        self::assertSame(WorkflowPresentationLanguage::SOURCE_PROJECT, $project->source);
        self::assertFileDoesNotExist($this->root . '/.agent-loop/init.json');
    }

    public function testADefaultLanguageStaysWithItsUserAndIsConfirmedWhenSetWithAMode(): void
    {
        ob_start();
        $exit = (new WorkflowInteractionCommand())->run(['sess-a', 'collaborative', '--default-language=de']);
        $output = (string) ob_get_clean();
        self::assertSame(0, $exit);
        self::assertStringContainsString('[OK] default language de for every host session of this user', $output);

        mkdir($this->root . '/other-user', 0o775, true);
        putenv('AGENT_LOOP_INTERACTION_DIR=' . $this->root . '/other-user');
        putenv('AGENT_LOOP_HOST_SESSION_ID=sess-b');
        $other = WorkflowPresentationLanguage::resolve($this->root);
        self::assertSame(['en', WorkflowPresentationLanguage::SOURCE_PROJECT], [$other->language, $other->source], 'Another user state directory does not see this default.');
    }

    /** @param list<string> $arguments */
    private function interaction(array $arguments): int
    {
        ob_start();
        $exit = (new WorkflowInteractionCommand())->run($arguments);
        ob_end_clean();

        return $exit;
    }
}
