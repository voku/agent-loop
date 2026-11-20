#!/usr/bin/env python3
"""H2 screening workspace: baseline at <sha>, the named lesson Finding(s) removed from the store (arm B).

usage: prep_h2.py <name> <baseline-sha> <finding-id> [<finding-id> ...]
"""
import json, os, subprocess, sys
from pathlib import Path
H2 = Path(__file__).resolve().parent
EXP2 = H2.parent / "exp2"
FIXED = {"GIT_AUTHOR_NAME": "experiment", "GIT_AUTHOR_EMAIL": "experiment@example.invalid", "GIT_COMMITTER_NAME": "experiment",
         "GIT_COMMITTER_EMAIL": "experiment@example.invalid", "GIT_AUTHOR_DATE": "2026-01-01T00:00:00Z", "GIT_COMMITTER_DATE": "2026-01-01T00:00:00Z"}
name, baseline, findings = sys.argv[1], sys.argv[2], sys.argv[3:]
env = {**os.environ, "EXP_VENDOR": str(EXP2 / "vendor-round2")}
r = subprocess.run([sys.executable, str(EXP2 / "prepare_ws.py"), name, baseline], env=env, capture_output=True, text=True)
if r.returncode: raise SystemExit(r.stdout + r.stderr)
(EXP2 / "runs" / name).rename(H2 / "runs" / name)
dest = H2 / "runs" / name; repo = dest / "repo"
removed = []
for f in findings:
    hits = list((repo / ".agent-loop/learning").rglob(f"*{f}*"))
    for h in hits: h.unlink(); removed.append(str(h.relative_to(repo)))
    if not hits: raise SystemExit(f"lesson {f} not found in baseline store")
changelog = repo / "CHANGELOG.md"
if changelog.is_file():
    lines = changelog.read_text().splitlines(keepends=True)
    kept = [l for l in lines if not any(f in l for f in findings)]
    if len(kept) != len(lines):
        changelog.write_text("".join(kept)); removed.append(f"CHANGELOG.md: {len(lines) - len(kept)} line(s) naming the lesson id")
leak = subprocess.run(["grep", "-rl", "--exclude-dir=vendor", "--exclude-dir=.git", *[x for f in findings for x in ("-e", f)], "."], cwd=repo, capture_output=True, text=True).stdout
if leak.strip(): raise SystemExit("lesson id still referenced:\n" + leak)
subprocess.run(["git", "add", "-A"], cwd=repo, check=True)
subprocess.run(["git", "commit", "-q", "--amend", "--no-verify", "-m", f"baseline agent-loop@{baseline}"], cwd=repo, check=True, env={**os.environ, **FIXED})
m = json.loads((dest / "manifest.json").read_text())
m.update(arm="B", start_commit=subprocess.run(["git", "rev-parse", "HEAD"], cwd=repo, capture_output=True, text=True, check=True).stdout.strip())
m["deviations"].append("lesson record(s) removed from the store (arm B): " + ", ".join(removed))
(dest / "manifest.json").write_text(json.dumps(m, indent=2) + "\n")
print(json.dumps({"name": name, "start": m["start_commit"], "removed": removed}))
