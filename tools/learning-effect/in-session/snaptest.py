#!/usr/bin/env python3
"""Run a PHPUnit filter against a git tree-ish of agent-loop (or a worktree dir) with a private vendor/composer.

usage: snaptest.py <commit|dir> <test-file> <filter> [--overlay-tests-from <commit>] [--repeat N]
"""
import shutil, subprocess, sys, tempfile
from pathlib import Path
LOOP = Path("/home/user/agent-loop")
import os
VENDOR = Path(os.environ['SNAP_VENDOR'])
src, test, flt = sys.argv[1], sys.argv[2], sys.argv[3]
overlay = sys.argv[sys.argv.index("--overlay-tests-from") + 1] if "--overlay-tests-from" in sys.argv else None
repeat = int(sys.argv[sys.argv.index("--repeat") + 1]) if "--repeat" in sys.argv else 1
with tempfile.TemporaryDirectory() as scratch:
    snap = Path(scratch) / "snap"
    if Path(src).is_dir():
        shutil.copytree(src, snap, ignore=shutil.ignore_patterns("vendor", ".git", ".agent-loop"))
    else:
        snap.mkdir()
        env = {"GIT_INDEX_FILE": str(Path(scratch) / "index"), "PATH": "/usr/local/bin:/usr/bin:/bin"}
        subprocess.run(["git", "-C", str(LOOP), "read-tree", src], env=env, check=True)
        subprocess.run(["git", "-C", str(LOOP), f"--work-tree={snap}", "checkout-index", "--force", "--all"], env=env, check=True)
    if overlay:
        content = subprocess.run(["git", "-C", str(LOOP), "show", f"{overlay}:{test}"], capture_output=True, check=True).stdout
        (snap / test).write_bytes(content)
    sv = snap / "vendor"; sv.mkdir()
    for e in VENDOR.iterdir():
        if e.name in ("composer", "autoload.php"):
            (shutil.copytree if e.is_dir() else shutil.copy2)(e, sv / e.name)
        else:
            (sv / e.name).symlink_to(e.resolve())
    (snap / "run-phpunit.php").write_text("<?php\n$GLOBALS['_composer_autoload_path'] = __DIR__ . '/vendor/autoload.php';\nrequire __DIR__ . '/vendor/phpunit/phpunit/phpunit';\n")
    passes = 0
    for _ in range(repeat):
        p = subprocess.run(["php", "run-phpunit.php", "--do-not-cache-result", "--filter", flt, test], cwd=snap, capture_output=True, text=True, timeout=900)
        passes += p.returncode == 0
        tail = (p.stdout + p.stderr)[-900:]
    print(f"{passes}/{repeat}")
    if passes < repeat:
        print(tail)
