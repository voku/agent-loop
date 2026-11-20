#!/usr/bin/env python3
"""Holdout H1 workspace (agent-loop PR #612 task, lesson finding 43dc53 / note a5fcbe).

usage: prep_h1.py <name> B|C
Both arms: baseline 34891d35, agent-loop#1091 precedent projection backported, Recall 0.29.4 vendored.
C only:   a LearningNote with the real note a5fcbe's system-written content, scope widened to src/Init/
          (the real scope does not cover this task's files) and the one verification sentence that
          names PR #612 (this task) removed; evidence re-hashed on this baseline.
"""
import hashlib, json, os, subprocess, sys
from pathlib import Path
EXP3 = Path(__file__).resolve().parent
EXP2 = EXP3.parent / "exp2"
LOOP = Path("/home/user/agent-loop")
BASELINE = "34891d35bd6863396a44898211f28b8f08717806"
NOTE = ".agent-loop/learning/notes/active/learning-note.2026-10-17.a5fcbe.json"
FIXED = {"GIT_AUTHOR_NAME": "experiment", "GIT_AUTHOR_EMAIL": "experiment@example.invalid", "GIT_COMMITTER_NAME": "experiment",
         "GIT_COMMITTER_EMAIL": "experiment@example.invalid", "GIT_AUTHOR_DATE": "2026-01-01T00:00:00Z", "GIT_COMMITTER_DATE": "2026-01-01T00:00:00Z"}
name, arm = sys.argv[1], sys.argv[2]
env = {**os.environ, "EXP_VENDOR": str(EXP2 / "vendor-round2")}
r = subprocess.run([sys.executable, str(EXP2 / "prepare_ws.py"), name, BASELINE], env=env, capture_output=True, text=True)
if r.returncode: raise SystemExit(r.stdout + r.stderr)
out = EXP2 / "runs" / name
dest = EXP3 / "runs" / name
dest.parent.mkdir(exist_ok=True)
out.rename(dest)
repo = dest / "repo"
deviations = ["agent-loop#1091 precedent projection backported into the baseline's enter and workflow plan (both arms)",
              "vendored agent-recall-compiler is 0.29.4 (both arms)"]
if arm == "C":
    note = json.loads(subprocess.run(["git", "-C", str(LOOP), "show", f"origin/main:{NOTE}"], capture_output=True, text=True, check=True).stdout)
    note["scope"] = sorted(set(note["scope"]) | {"src/Init/"})
    v = note["content"]["verification"]
    note["content"]["verification"] = v.split(" Corroborating repository evidence:")[0]
    note["content"]["examples"] = [e for e in note["content"].get("examples", []) if "612" not in e]
    assert "612" not in json.dumps(note), "task leak"
    note["repository_evidence"] = [{"source_ref": e["source_ref"], "sha256": hashlib.sha256((repo / e["source_ref"]).read_bytes()).hexdigest()}
                                   for e in note["repository_evidence"]]
    (repo / NOTE).write_text(json.dumps(note, indent=4) + "\n")
    deviations.append("note a5fcbe (written 2026-10-17, after this task) added with scope widened to src/Init/ and the sentence and example naming PR #612 removed; evidence re-hashed")
subprocess.run([sys.executable, str(EXP2 / "backport_1091.py"), str(repo)], check=True, capture_output=True)
subprocess.run(["git", "add", "-A"], cwd=repo, check=True)
subprocess.run(["git", "commit", "-q", "--amend", "--no-verify", "-m", f"baseline agent-loop@{BASELINE}"], cwd=repo, check=True, env={**os.environ, **FIXED})
subprocess.run([sys.executable, str(EXP3.parent / "overlay.py"), str(repo / "vendor"),
                json.dumps({"voku/agent-recall-compiler": ["/home/user/agent-recall-compiler", "0.29.4", "0.29.4"]})], check=True, capture_output=True)
subprocess.run(["composer", "dump-autoload", "--dev", "--no-interaction", "--no-scripts"], cwd=repo, check=True, capture_output=True,
               env={**os.environ, "COMPOSER_ALLOW_SUPERUSER": "1"})
m = json.loads((dest / "manifest.json").read_text())
m.update(arm=arm, start_commit=subprocess.run(["git", "rev-parse", "HEAD"], cwd=repo, capture_output=True, text=True, check=True).stdout.strip())
m["deviations"] += deviations
(dest / "manifest.json").write_text(json.dumps(m, indent=2) + "\n")
print(json.dumps({"name": name, "arm": arm, "start": m["start_commit"]}))
