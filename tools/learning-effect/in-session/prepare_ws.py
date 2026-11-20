#!/usr/bin/env python3
"""Build an isolated agent-loop workspace at a vulnerable baseline for the round-2 screening.

usage: prepare_ws.py <name> <baseline-sha> [--precedent <note-id> <finding-id> <proposal-id>]
The workspace is a fresh single-commit repository (no history, no remote) under
exp2/runs/<name>/repo. vendor/ is a private copy of agent-loop's installed runtime
dependencies plus PHPUnit, with the autoloader regenerated for the baseline offline.
"""
import hashlib
import json
import os
import shutil
import subprocess
import sys
from pathlib import Path

EXP2 = Path("/tmp/claude-0/-home-user/aed1987a-fdd5-534b-b32b-a28811e530e8/scratchpad/exp2").resolve()
SHARED = Path("/tmp/claude-0/-home-user/aed1987a-fdd5-534b-b32b-a28811e530e8/scratchpad/exp/shared-vendor")
LOOP = Path("/home/user/agent-loop")
FIXED = {k: v for k, v in {
    "GIT_AUTHOR_NAME": "experiment", "GIT_AUTHOR_EMAIL": "experiment@example.invalid",
    "GIT_COMMITTER_NAME": "experiment", "GIT_COMMITTER_EMAIL": "experiment@example.invalid",
    "GIT_AUTHOR_DATE": "2026-01-01T00:00:00Z", "GIT_COMMITTER_DATE": "2026-01-01T00:00:00Z"}.items()}
PHPUNIT_PACKAGES = ["phpunit", "sebastian", "theseer", "phar-io", "myclabs", "staabm"]


def run(cmd, cwd=None, env=None, check=True, data=None):
    proc = subprocess.run(cmd, cwd=cwd, env={**os.environ, **(env or {})}, capture_output=True, input=data)
    if check and proc.returncode != 0:
        raise SystemExit(f"{cmd} failed: {proc.stdout.decode()}{proc.stderr.decode()}")
    return proc.stdout.decode()


name, baseline = sys.argv[1], sys.argv[2]
out = EXP2 / "runs" / name
repo = out / "repo"
if out.exists():
    raise SystemExit(f"{out} exists")
repo.mkdir(parents=True)

index = out / "build.index"
env = {"GIT_INDEX_FILE": str(index)}
run(["git", "-C", str(LOOP), "read-tree", baseline], env=env)
run(["git", "-C", str(LOOP), f"--work-tree={repo}", "checkout-index", "--force", "--all"], env=env)
index.unlink()

# Leave no lesson behind: the baseline store predates the lesson, but double check.
store = repo / ".agent-loop/learning"
precedent = sys.argv[sys.argv.index("--precedent") + 1:] if "--precedent" in sys.argv else []
if precedent:
    note_id, finding_id, proposal_id = precedent
    for status, rel in (("note", f"notes/active/{note_id}.json"), ("finding", f"findings/consolidated/{finding_id}.json"), ("proposal", f"proposals/rejected/{proposal_id}.json")):
        target = store / rel
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(run(["git", "-C", str(LOOP), "show", f"origin/main:.agent-loop/learning/{rel}"]))
    note_path = store / f"notes/active/{note_id}.json"
    note = json.loads(note_path.read_text())
    for evidence in note.get("repository_evidence", []):
        source = repo / evidence["source_ref"]
        if source.is_file():
            evidence["sha256"] = hashlib.sha256(source.read_bytes()).hexdigest()
    note["repository_evidence"] = [e for e in note.get("repository_evidence", []) if (repo / e["source_ref"]).is_file()]
    note_path.write_text(json.dumps(note, indent=2) + "\n")

run(["git", "init", "--quiet", "-b", "main"], cwd=repo)
(repo / ".git/info/exclude").write_text("/vendor\n")
run(["git", "add", "-A"], cwd=repo)
run(["git", "commit", "-q", "--no-verify", "-m", f"baseline agent-loop@{baseline}"], cwd=repo, env=FIXED)

# Private vendor: installed runtime packages + PHPUnit, autoloader regenerated for this baseline.
shutil.copytree(Path(os.environ.get("EXP_VENDOR", str(LOOP / "vendor"))), repo / "vendor", symlinks=True)
for vendor_name in PHPUNIT_PACKAGES:
    source = SHARED / vendor_name
    if source.is_dir() and not (repo / "vendor" / vendor_name).exists():
        shutil.copytree(source, repo / "vendor" / vendor_name, symlinks=True)
# Register the copied PHPUnit packages so dump-autoload includes them.
installed_path = repo / "vendor/composer/installed.json"
installed = json.loads(installed_path.read_text())
present = {p["name"] for p in installed["packages"]}
shared_installed = json.loads((SHARED / "composer/installed.json").read_text())
for package in shared_installed["packages"]:
    if package["name"].split("/")[0] in PHPUNIT_PACKAGES and package["name"] not in present:
        installed["packages"].append(package)
        installed.setdefault("dev-package-names", []).append(package["name"])
installed["dev"] = True
installed_path.write_text(json.dumps(installed, indent=4))
bin_dir = repo / "vendor/bin"
bin_dir.mkdir(exist_ok=True)
(bin_dir / "phpunit").unlink(missing_ok=True)
(bin_dir / "phpunit").symlink_to("../phpunit/phpunit/phpunit")
run(["composer", "dump-autoload", "--dev", "--no-interaction", "--no-scripts"], cwd=repo, env={"COMPOSER_ALLOW_SUPERUSER": "1", "COMPOSER_NO_AUDIT": "1"})

manifest = {
    "name": name, "baseline_sha": baseline,
    "start_commit": run(["git", "rev-parse", "HEAD"], cwd=repo).strip(),
    "precedent": precedent,
    "deviations": ["vendor = agent-loop's current installed runtime packages + PHPUnit (lock drift vs baseline), autoload regenerated offline"]
    + (["precedent note re-grounded on this baseline's own source files"] if precedent else []),
}
(out / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n")
print(json.dumps(manifest))
