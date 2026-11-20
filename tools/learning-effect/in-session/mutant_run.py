#!/usr/bin/env python3
"""Apply the override-branch mutant to a tree and run a phpunit selection.
usage: mutant_run.py <commit|dir> [phpunit args...]   (prints pass/fail and failing test names)
"""
import re, shutil, subprocess, sys, tempfile
from pathlib import Path
LOOP = Path("/home/user/agent-loop")
VENDOR = Path("/tmp/claude-0/-home-user/aed1987a-fdd5-534b-b32b-a28811e530e8/scratchpad/exp2/runs/C1-R3Cp1/repo/vendor")
src, args = sys.argv[1], sys.argv[2:]
mutate = "--no-mutant" not in args
args = [a for a in args if a != "--no-mutant"]
with tempfile.TemporaryDirectory() as scratch:
    snap = Path(scratch) / "snap"
    if Path(src).is_dir():
        shutil.copytree(src, snap, ignore=shutil.ignore_patterns("vendor", ".git", ".agent-loop"))
    else:
        snap.mkdir()
        env = {"GIT_INDEX_FILE": str(Path(scratch) / "index"), "PATH": "/usr/local/bin:/usr/bin:/bin"}
        subprocess.run(["git", "-C", str(LOOP), "read-tree", src], env=env, check=True)
        subprocess.run(["git", "-C", str(LOOP), f"--work-tree={snap}", "checkout-index", "--force", "--all"], env=env, check=True)
    if mutate:
        cat = snap / "src/Init/ManagedAssetTargetCatalog.php"
        t = cat.read_text()
        n = 0
        for var in ("CLAUDE_SKILLS_DIR", "CLAUDE_AGENTS_DIR"):
            t, k = re.subn(r"PathResolver::fromEnvironment\(\$this->rootPath, '" + var + r"'\)\s*\?\?\s*", "", t)
            n += k
        assert n == 2, f"mutant sites found: {n}"
        cat.write_text(t)
    sv = snap / "vendor"; sv.mkdir()
    for e in VENDOR.iterdir():
        if e.name in ("composer", "autoload.php"):
            (shutil.copytree if e.is_dir() else shutil.copy2)(e, sv / e.name)
        else:
            (sv / e.name).symlink_to(e.resolve())
    (snap / "run-phpunit.php").write_text("<?php\n$GLOBALS['_composer_autoload_path'] = __DIR__ . '/vendor/autoload.php';\nrequire __DIR__ . '/vendor/phpunit/phpunit/phpunit';\n")
    junit = Path(scratch) / "junit.xml"
    p = subprocess.run(["php", "run-phpunit.php", "--do-not-cache-result", "--log-junit", str(junit), *args], cwd=snap, capture_output=True, text=True, timeout=1800)
    fails = []
    if junit.exists():
        import xml.etree.ElementTree as ET
        for tc in ET.parse(junit).iter("testcase"):
            if tc.find("failure") is not None or tc.find("error") is not None:
                fails.append(tc.get("class", "") + "::" + tc.get("name", ""))
    print("exit", p.returncode, "failing", len(fails))
    for f in fails: print("  ", f)
    print((p.stdout + p.stderr)[-300:])
