#!/usr/bin/env python3
"""Behavioural variant of the C1 oracle: identical tests, except the one exact-message
assertion is relaxed to require both the cause and the failing bundle's name.

usage: behavioural_oracle.py <run-name>   (writes result.json["behavioural_oracle"])
"""
import json, re, subprocess, sys, tempfile
from pathlib import Path
EXP2 = Path(__file__).resolve().parent
LOOP = Path("/home/user/agent-loop")
run = sys.argv[1]
realistic = "--realistic" in sys.argv  # fixtures shaped like real bundles: execution.json records its task_id
out = EXP2 / "runs" / run
repo = out / "repo"
vendor = repo / "vendor"
if not vendor.is_dir():
    vendor = EXP2 / "runs" / "C1-R3Cp1" / "repo" / "vendor"  # graded B/C' rounds whose vendor was freed; same PHPUnit + runtime set
oracle = subprocess.run(["git", "-C", str(LOOP), "show", "53e3776:tests/WorkflowCloseReadinessStatusTest.php"], capture_output=True, text=True, check=True).stdout
oracle = re.sub(r"\bfinal class WorkflowCloseReadinessStatusTest\b", "final class HiddenOracleTest", oracle)
strict = "self::assertStringContainsString('missing verification-result.json for edit bundle ABC-123-plan2', $closeOutput);"
assert oracle.count(strict) == 1
if realistic:
    n = oracle.count("/execution.json', '{}')")
    assert n >= 3, n
    oracle = oracle.replace("/execution.json', '{}')", "/execution.json', json_encode(['task_id' => 'ABC-123']))")
oracle = oracle.replace(strict, "self::assertStringContainsString('missing verification-result.json', $closeOutput);\n        self::assertStringContainsString('ABC-123-plan2', $closeOutput);")
with tempfile.TemporaryDirectory() as scratch:
    scratch = Path(scratch)
    env = {"GIT_INDEX_FILE": str(scratch / "index"), "PATH": "/usr/local/bin:/usr/bin:/bin"}
    subprocess.run(["git", "-C", str(repo), "read-tree", "HEAD"], env=env, check=True)
    subprocess.run(["git", "-C", str(repo), "add", "-A"], env=env, check=True)
    tree = subprocess.run(["git", "-C", str(repo), "write-tree"], env=env, check=True, capture_output=True, text=True).stdout.strip()
    snap = scratch / "snap"; snap.mkdir()
    subprocess.run(["git", "-C", str(repo), "read-tree", tree], env=env, check=True)
    subprocess.run(["git", "-C", str(repo), f"--work-tree={snap}", "checkout-index", "--force", "--all"], env=env, check=True)
    # Own vendor dir so Composer's $baseDir (dirname of vendor) is the snapshot itself:
    # packages are linked, composer/ and autoload.php are private copies.
    import shutil
    sv = snap / "vendor"; sv.mkdir()
    for entry in vendor.iterdir():
        if entry.name in ("composer", "autoload.php"):
            (shutil.copytree if entry.is_dir() else shutil.copy2)(entry, sv / entry.name)
        else:
            (sv / entry.name).symlink_to(entry.resolve())
    probe = subprocess.run(["php", "-r", "require 'vendor/autoload.php'; echo (new ReflectionClass('voku\\AgentLoop\\Workflow\\WorkflowCloseReadinessInspector'))->getFileName();"],
                           cwd=snap, capture_output=True, text=True)
    assert probe.stdout.startswith(str(snap)), ("autoload escapes the snapshot", probe.stdout, probe.stderr)
    (snap / "tests/HiddenOracleTest.php").write_text(oracle)
    (snap / "run-phpunit.php").write_text("<?php\n$GLOBALS['_composer_autoload_path'] = __DIR__ . '/vendor/autoload.php';\nrequire __DIR__ . '/vendor/phpunit/phpunit/phpunit';\n")
    passes, tail = 0, ""
    for _ in range(3):
        p = subprocess.run(["php", "run-phpunit.php", "--do-not-cache-result", "--filter",
                            "testEmptyEditBundleDirectoryDoesNotBlockClose|testMultiPlanEditBundlesAllVerifiedPassCloseGate|testMultiPlanEditBundlesWithOneFailingBlocksClose",
                            "tests/HiddenOracleTest.php"], cwd=snap, capture_output=True, text=True, timeout=900)
        passes += p.returncode == 0
        tail = (p.stdout + p.stderr)[-700:]
data = json.loads((out / "result.json").read_text()) if (out / "result.json").exists() else {}
data["realistic_oracle" if realistic else "behavioural_oracle"] = {"passes": f"{passes}/3", "correct": passes == 3, "tail": tail if passes < 3 else ""}
(out / "result.json").write_text(json.dumps(data, indent=2) + "\n")
print(run, f"{passes}/3", "" if passes == 3 else tail[-260:].replace("\n", " | "))
