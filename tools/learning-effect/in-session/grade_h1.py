#!/usr/bin/env python3
"""Grade a holdout H1 run (agent-loop #612 task, lesson: cover the override branch of a collapsed decision).

usage: grade_h1.py <run> <transcript> [tokens tool_uses duration_ms]
Measures, on a snapshot of the agent's final tree:
  hidden     #612's two regression tests (env-override sync targets) overlaid into the tree, 3 runs
  detection  the agent's own added/changed test files, run with and without the override mutant
             (every env var the catalog reads renamed to an unset one, defaults untouched):
             detected = some test in them passes without the mutant and fails with it
  delegated  both sync commands reference ManagedAssetTargetCatalog and no longer define resolveTargetRoot tables
and audits the transcript (foreign paths, network, precedent exposure).
"""
import json, re, shutil, subprocess, sys, tempfile, xml.etree.ElementTree as ET
from pathlib import Path
EXP3 = Path(__file__).resolve().parent
LOOP = Path("/home/user/agent-loop")
FIX = "27a34931"
run, transcript = sys.argv[1], Path(sys.argv[2])
usage = dict(zip(("tokens", "tool_uses", "duration_ms"), map(int, sys.argv[3:6]))) if len(sys.argv) >= 6 else {}
out = EXP3 / "runs" / run
repo = out / "repo"
manifest = json.loads((out / "manifest.json").read_text())
vendor = (EXP3 / "runs" / "H1-C-probe" / "repo" / "vendor")

def snapshot(scratch: Path) -> tuple[Path, str]:
    env = {"GIT_INDEX_FILE": str(scratch / "index"), "PATH": "/usr/local/bin:/usr/bin:/bin"}
    subprocess.run(["git", "-C", str(repo), "read-tree", "HEAD"], env=env, check=True)
    subprocess.run(["git", "-C", str(repo), "add", "-A"], env=env, check=True)
    tree = subprocess.run(["git", "-C", str(repo), "write-tree"], env=env, check=True, capture_output=True, text=True).stdout.strip()
    return env, tree

def materialize(scratch: Path, env, tree: str, name: str, mutant: bool, hidden: bool) -> Path:
    snap = scratch / name
    snap.mkdir()
    subprocess.run(["git", "-C", str(repo), "read-tree", tree], env=env, check=True)
    subprocess.run(["git", "-C", str(repo), f"--work-tree={snap}", "checkout-index", "--force", "--all"], env=env, check=True)
    if mutant:
        cat = snap / "src/Init/ManagedAssetTargetCatalog.php"
        t = cat.read_text()
        t, n = re.subn(r"(PathResolver::fromEnvironment\(\$this->rootPath,\s*')", r"\1AGENT_LOOP_MUTANT_UNSET_", t)
        if n == 0:
            raise SystemExit("mutant found no env lookups in the catalog")
        cat.write_text(t)
    if hidden:
        test = subprocess.run(["git", "-C", str(LOOP), "show", f"{FIX}:tests/InitSyncCommandTest.php"], capture_output=True, text=True, check=True).stdout
        test = re.sub(r"\bfinal class InitSyncCommandTest\b", "final class HiddenOracleTest", test)
        (snap / "tests/HiddenOracleTest.php").write_text(test)
    sv = snap / "vendor"; sv.mkdir()
    for e in vendor.iterdir():
        if e.name in ("composer", "autoload.php"):
            (shutil.copytree if e.is_dir() else shutil.copy2)(e, sv / e.name)
        else:
            (sv / e.name).symlink_to(e.resolve())
    (snap / "run-phpunit.php").write_text("<?php\n$GLOBALS['_composer_autoload_path'] = __DIR__ . '/vendor/autoload.php';\nrequire __DIR__ . '/vendor/phpunit/phpunit/phpunit';\n")
    subprocess.run(["composer", "dump-autoload", "--dev", "--no-scripts", "-q"], cwd=snap, env={"COMPOSER_ALLOW_SUPERUSER": "1", "PATH": env["PATH"], "HOME": "/root"}, capture_output=True)
    return snap

def phpunit(snap: Path, args: list[str]) -> tuple[int, set[str], set[str]]:
    junit = snap / "junit.xml"
    junit.unlink(missing_ok=True)  # each report must come from this invocation
    p = subprocess.run(["php", "run-phpunit.php", "--do-not-cache-result", "--log-junit", str(junit), *args], cwd=snap, capture_output=True, text=True, timeout=1800)
    failed, passed = set(), set()
    if junit.exists():
        for tc in ET.parse(junit).iter("testcase"):
            key = tc.get("class", "") + "::" + tc.get("name", "")
            (failed if (tc.find("failure") is not None or tc.find("error") is not None) else passed).add(key)
    elif p.returncode != 0:
        # PHP died before PHPUnit wrote a report: an execution failure, never a green run.
        failed.add("<execution failure: exit " + str(p.returncode) + ", no report for " + " ".join(args)[:80] + ">")
    return p.returncode, failed, passed

with tempfile.TemporaryDirectory() as scratch:
    scratch = Path(scratch)
    env, tree = snapshot(scratch)
    changed = subprocess.run(["git", "-C", str(repo), "diff-tree", "-r", "--name-status", manifest["start_commit"] + "^{tree}", tree], capture_output=True, text=True, check=True).stdout.splitlines()
    changed = [l.split("\t", 1) for l in changed if "\t" in l]
    changed_files = [p for s, p in changed if not p.startswith(".agent-loop/")]
    tests = [p for s, p in changed if p.startswith("tests/") and p.endswith("Test.php") and s != "D"]

    plain = materialize(scratch, env, tree, "plain", False, True)
    hidden_pass = 0
    for _ in range(3):
        rc, f, _p = phpunit(plain, ["--filter", "testSyncSkillsUsesCatalogEnvironmentTarget|testSyncSubagentsUsesCatalogEnvironmentTarget", "tests/HiddenOracleTest.php"])
        hidden_pass += rc == 0
    own_plain_failed, own_plain_passed = set(), set()
    for t in tests:
        _rc, f, p = phpunit(plain, [t]); own_plain_failed |= f; own_plain_passed |= p
    mut = materialize(scratch, env, tree, "mutant", True, False)
    own_mut_failed = set()
    for t in tests:
        _rc, f, _p = phpunit(mut, [t]); own_mut_failed |= f
    killers = sorted(own_mut_failed & own_plain_passed)

    sources = {n: (plain / f"src/Init/{n}.php").read_text() for n in ("InitSyncSkillsCommand", "InitSyncSubagentsCommand")}
    delegated = all("ManagedAssetTargetCatalog" in s and "_SKILLS_DIR'" not in s and "_AGENTS_DIR'" not in s for s in sources.values())

outside, network, exposed = [], [], False
for raw in transcript.read_text(errors="replace").splitlines():
    try:
        event = json.loads(raw)
    except json.JSONDecodeError:
        continue
    if event.get("type") == "user" and "a5fcbe" in json.dumps(event):
        exposed = True
    message = event.get("message") if isinstance(event.get("message"), dict) else {}
    for block in message.get("content") or []:
        if not isinstance(block, dict) or block.get("type") != "tool_use":
            continue
        payload = block.get("input") or {}
        text = " ".join(str(v) for v in payload.values()) if isinstance(payload, dict) else str(payload)
        for path in re.findall(r"(/(?:home|tmp|root)/[^\s'\"`;|&)]*)", text):
            if not path.startswith(str(out)):
                outside.append(path[:160])
        if block.get("name") == "Bash" and re.search(r"\b(curl|wget|git\s+(fetch|clone|pull|ls-remote)|composer\s+(install|update|require))\b", text):
            network.append(text[:160])

result = {
    "run": run, "arm": manifest.get("arm"), "changed": changed_files, "own_tests": tests,
    "hidden_612_tests": f"{hidden_pass}/3", "behaviour_preserved_on_override": hidden_pass == 3,
    "own_tests_green": not own_plain_failed, "own_tests_failing_plain": sorted(own_plain_failed),
    "mutant_killed_by_own_tests": bool(killers), "killing_tests": killers, "delegated_to_catalog": delegated,
    "precedent_in_context": exposed, "foreign_reads": sorted({p for p in outside if p.startswith("/home/") or ("/runs/" in p and not p.startswith(str(out)))}),
    "outside_paths": sorted(set(outside))[:12], "network": network[:5], "usage": usage,
}
result_path = out / "result.json"
merged = json.loads(result_path.read_text()) if result_path.exists() else {}
merged.update(result)  # keep keys written by other graders (behavioural_oracle, realistic_oracle)
result_path.write_text(json.dumps(merged, indent=2) + "\n")
print(json.dumps({k: result[k] for k in ("run", "arm", "hidden_612_tests", "own_tests", "own_tests_green", "mutant_killed_by_own_tests", "delegated_to_catalog", "precedent_in_context")} | {"killers": len(killers), "foreign": len(result["foreign_reads"]), "network": len(network)}))
