import json, shutil, subprocess, sys, tempfile
from pathlib import Path
LOOP=Path("/home/user/agent-loop"); src, vendor = sys.argv[1], Path(sys.argv[2])
FIX='tests/PHPStan/data/out-of-process-levels-test-case.php'
CODE="""<?php

declare(strict_types=1);

namespace voku\\AgentLoop\\Tests\\PHPStan\\Data;

use PHPStan\\Testing\\LevelsTestCase;

final class OutOfProcessLevelsTest extends LevelsTestCase
{
    public static function dataTopics(): array { return []; }
    public function getDataPath(): string { return __DIR__; }
    public function getPhpStanExecutablePath(): string { return 'vendor/bin/phpstan'; }
    public function getPhpStanConfigPath(): ?string { return null; }
}
"""
with tempfile.TemporaryDirectory() as scratch:
    snap=Path(scratch)/"snap"
    if Path(src).is_dir(): shutil.copytree(src, snap, ignore=shutil.ignore_patterns("vendor",".git",".agent-loop"))
    else:
        snap.mkdir(); env={"GIT_INDEX_FILE":str(Path(scratch)/"i"),"PATH":"/usr/local/bin:/usr/bin:/bin"}
        subprocess.run(["git","-C",str(LOOP),"read-tree",src],env=env,check=True)
        subprocess.run(["git","-C",str(LOOP),f"--work-tree={snap}","checkout-index","--force","--all"],env=env,check=True)
    (snap/FIX).write_text(CODE)
    subprocess.run(["cp","-al",str(vendor),str(snap/"vendor")],check=True)
    p=subprocess.run(["php","vendor/phpstan/phpstan/phpstan","analyse","--configuration=phpstan/project-rule-test.neon","--error-format=json","--no-progress","--memory-limit=1G"],cwd=snap,capture_output=True,text=True)
    d=json.loads(p.stdout)
    hits=[(Path(f).name,m["line"]) for f,x in d.get("files",{}).items() for m in x["messages"] if m.get("identifier")=="agentLoop.phpstan.inProcessRuleTestCase" and Path(f).name==Path(FIX).name]
    print("LevelsTestCase subclass flagged:", hits or "no")
