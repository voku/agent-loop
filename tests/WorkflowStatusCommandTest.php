<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Workflow\WorkflowStatusCommand;
use voku\AgentSession\SessionStore;
use voku\AgentSession\WorkBriefStore;

final class WorkflowStatusCommandTest extends TestCase
{
    private string $root;
    protected function setUp(): void { $this->root = sys_get_temp_dir().'/agent-loop-status-'.bin2hex(random_bytes(4)); mkdir($this->root); }
    protected function tearDown(): void { $this->rm($this->root); }

    public function testStatusReportsPendingAndDoesNotWriteFiles(): void
    {
        $before = $this->files(); ob_start(); $exit=(new WorkflowStatusCommand($this->root))->run(['ABC-123']); $out=(string)ob_get_clean();
        self::assertSame(0,$exit); self::assertStringContainsString('[WARN] session: no session found', $out); self::assertStringContainsString('[PENDING] recall', $out); self::assertStringContainsString('[PENDING] review', $out); self::assertSame($before, $this->files());
    }
    public function testStatusReportsArtifactsAndReviewStates(): void
    {
        $session = (new SessionStore())->create($this->root.'/session_plan','ABC-123', by:'lars');
        $briefs = new WorkBriefStore();
        $briefs->create($session, 'Keep the task scope reviewable.', ['src/Foo.php'], [], ['vendor/bin/phpunit']);
        $briefs->approve($session, 'lars');
        mkdir($this->root.'/recall/ABC-123',0775,true); file_put_contents($this->root.'/recall/ABC-123/meta.json','{}'); mkdir($this->root.'/.agent-recall/reviews',0775,true);
        foreach (['ok'=>'[OK] review', 'warn'=>'[WARN] review', 'fail'=>'[WARN] review'] as $status=>$needle) { file_put_contents($this->root.'/.agent-recall/reviews/ABC-123.blindspots.json', json_encode(['status'=>$status])); ob_start(); $exit=(new WorkflowStatusCommand($this->root))->run(['ABC-123']); $out=(string)ob_get_clean(); self::assertSame(0,$exit); self::assertStringContainsString('[OK] session: 1 session(s) found', $out); self::assertStringContainsString('[OK] work brief: revision 1 approved by lars', $out); self::assertStringContainsString('[OK] recall: found', $out); self::assertStringContainsString($needle, $out); self::assertStringContainsString('status '.$status, $out); }
    }
    public function testStatusReportsInvalidJsonAsWarning(): void
    { mkdir($this->root.'/.agent-recall/reviews',0775,true); file_put_contents($this->root.'/.agent-recall/reviews/ABC-123.blindspots.json','{'); ob_start(); $exit=(new WorkflowStatusCommand($this->root))->run(['ABC-123']); $out=(string)ob_get_clean(); self::assertSame(0,$exit); self::assertStringContainsString('report JSON is invalid',$out); }
    /** @return list<string> */
    private function files(): array { $files=[]; if(!is_dir($this->root)) return []; foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS)) as $f){$files[]=$f->getPathname();} sort($files); return $files; }
    private function rm(string $dir): void { if(!is_dir($dir)) return; foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());} rmdir($dir); }
}
