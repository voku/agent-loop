#!/usr/bin/env python3
"""H2b oracle: count agentLoop.phpstan.inProcessRuleTestCase diagnostics with the hidden fixture from 0866edaf.

usage: oracle_h2b.py <commit|dir> <vendor-dir>   -> prints the count; PASS needs 5
"""
import json, shutil, subprocess, sys, tempfile
from pathlib import Path
LOOP = Path("/home/user/agent-loop")
FIXTURE = "tests/PHPStan/data/in-process-phpstan-type-inference-test-case.php"
src, vendor = sys.argv[1], Path(sys.argv[2])
with tempfile.TemporaryDirectory() as scratch:
    snap = Path(scratch) / "snap"
    if Path(src).is_dir():
        shutil.copytree(src, snap, ignore=shutil.ignore_patterns("vendor", ".git", ".agent-loop"))
    else:
        snap.mkdir(); env = {"GIT_INDEX_FILE": str(Path(scratch) / "index"), "PATH": "/usr/local/bin:/usr/bin:/bin"}
        subprocess.run(["git", "-C", str(LOOP), "read-tree", src], env=env, check=True)
        subprocess.run(["git", "-C", str(LOOP), f"--work-tree={snap}", "checkout-index", "--force", "--all"], env=env, check=True)
    (snap / FIXTURE).write_bytes(subprocess.run(["git", "-C", str(LOOP), "show", f"0866edaf:{FIXTURE}"], capture_output=True, check=True).stdout)
    subprocess.run(["cp", "-al", str(vendor), str(snap / "vendor")], check=True)  # hardlinks: one path for every autoloader
    p = subprocess.run(["php", "vendor/phpstan/phpstan/phpstan", "analyse", "--configuration=phpstan/project-rule-test.neon", "--error-format=json", "--no-progress", "--memory-limit=1G"],
                       cwd=snap, capture_output=True, text=True, timeout=900)
    try:
        data = json.loads(p.stdout)
    except json.JSONDecodeError:
        raise SystemExit("phpstan output not JSON:\n" + (p.stdout + p.stderr)[-1500:])
    hits = [(f, m["line"]) for f, d in data.get("files", {}).items() for m in d["messages"] if m.get("identifier") == "agentLoop.phpstan.inProcessRuleTestCase"]
    print(len(hits)); [print(" ", Path(f).name, l) for f, l in sorted(hits)]
