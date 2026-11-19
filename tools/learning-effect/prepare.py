#!/usr/bin/env python3
"""Prepare one isolated starting state for the pipefail learning-effect experiment.

Arms (see docs/dogfood/2026-11-19-learning-effect-pipefail.md):
  A  native host, repository as it was at the vulnerable baseline;
  B  A + Loop governance (isolated tools/agent-loop install, host assets) and a
     learning store copied from voku/agent-loop with every artifact of the lesson removed;
  C  B + exactly the lesson's LearningNote and its source Finding.

The workspace is a fresh single-commit repository built from `git archive`, with no
remote and no history, so the historical repair cannot be read from it. The baseline
commit is created with fixed identity and dates, so it has the same SHA in every arm.
Everything about the run is recorded in <out>/manifest.json, outside the workspace.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path

HERE = Path(__file__).resolve().parent
FIXED_GIT_ENV = {
    "GIT_AUTHOR_NAME": "experiment",
    "GIT_AUTHOR_EMAIL": "experiment@example.invalid",
    "GIT_AUTHOR_DATE": "2026-01-01T00:00:00Z",
    "GIT_COMMITTER_NAME": "experiment",
    "GIT_COMMITTER_EMAIL": "experiment@example.invalid",
    "GIT_COMMITTER_DATE": "2026-01-01T00:00:00Z",
}


def run(cmd: list[str], cwd: Path | None = None, env: dict[str, str] | None = None, log: Path | None = None) -> str:
    merged = {**os.environ, **(env or {})}
    proc = subprocess.run(cmd, cwd=cwd, env=merged, capture_output=True, text=True)
    if log is not None:
        with log.open("a", encoding="utf-8") as handle:
            handle.write(f"$ {' '.join(cmd)}\n[exit {proc.returncode}]\n{proc.stdout}{proc.stderr}\n")
    if proc.returncode != 0:
        sys.exit(f"command failed ({proc.returncode}): {' '.join(cmd)}\n{proc.stdout}{proc.stderr}")
    return proc.stdout


def extract(source: Path, revision: str, destination: Path, *paths: str) -> None:
    """Write the exact tree (or paths of it) to destination.

    Not `git archive`: it honours export-ignore attributes and would drop tests/ and
    .github/ from the very repositories this experiment needs them in.
    """
    with tempfile.TemporaryDirectory() as scratch:
        env = {**os.environ, "GIT_INDEX_FILE": str(Path(scratch) / "index")}
        subprocess.run(["git", "-C", str(source), "read-tree", revision], env=env, check=True, capture_output=True)
        names = subprocess.run(
            ["git", "-C", str(source), "ls-tree", "-r", "-z", "--name-only", revision, "--", *paths],
            check=True, capture_output=True,
        ).stdout
        if paths and not names:
            sys.exit(f"{revision} has no {' '.join(paths)}")
        subprocess.run(
            ["git", "-C", str(source), f"--work-tree={destination}", "checkout-index", "--force", "-z", "--stdin"],
            input=names, env=env, check=True, capture_output=True,
        )


def commit(repo: Path, message: str) -> str:
    run(["git", "add", "-A"], cwd=repo)
    run(["git", "commit", "--quiet", "--no-verify", "--allow-empty", "-m", message], cwd=repo, env=FIXED_GIT_ENV)
    return run(["git", "rev-parse", "HEAD"], cwd=repo).strip()


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def matching_files(root: Path, pattern: re.Pattern[str]) -> list[str]:
    hits = []
    for path in sorted(p for p in root.rglob("*") if p.is_file()):
        try:
            text = path.read_text(encoding="utf-8")
        except (UnicodeDecodeError, OSError):
            continue
        if pattern.search(text):
            hits.append(path.as_posix())
    return hits


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--case", required=True)
    parser.add_argument("--arm", required=True, choices=["A", "B", "C"])
    parser.add_argument("--out", required=True, type=Path, help="new directory; the workspace is <out>/repo")
    parser.add_argument("--source", required=True, type=Path, help="local clone of the case repository (stays outside the workspace)")
    parser.add_argument("--learning-source", type=Path, help="voku/agent-loop clone providing the learning store (arms B, C)")
    parser.add_argument("--learning-sha", default="5bc9f6900f1baae047a4c7ef8039aea2ce88f2fb")
    parser.add_argument("--agent-loop-constraint", default="0.20.97", help="exact released voku/agent-loop version (arms B, C)")
    parser.add_argument("--agent-loop-path", type=Path, help="LOCAL VALIDATION ONLY: install agent-loop from this checkout; marks the run non-release")
    parser.add_argument("--host", default="claude", help="host passed to `agent-loop init install-assets --agent`")
    parser.add_argument("--skip-composer", action="store_true", help="LOCAL VALIDATION ONLY: skip all Composer installs")
    args = parser.parse_args()

    config = json.loads((HERE / "cases.json").read_text(encoding="utf-8"))
    case = next((c for c in config["cases"] if c["id"] == args.case), None)
    if case is None:
        sys.exit(f"unknown case {args.case}")
    if args.arm != "A" and args.learning_source is None:
        sys.exit("arms B and C need --learning-source")

    out = args.out.resolve()
    repo = out / "repo"
    if out.exists():
        sys.exit(f"{out} already exists; every run starts from a new directory")
    repo.mkdir(parents=True)
    log = out / "prepare.log"

    source = args.source.resolve()
    if run(["git", "-C", str(source), "rev-parse", f"{case['baseline_sha']}^{{commit}}"]).strip() != case["baseline_sha"]:
        sys.exit("baseline commit missing from --source")

    extract(source, case["baseline_sha"], repo)
    run(["git", "init", "--quiet", "-b", "main"], cwd=repo)
    baseline_commit = commit(repo, f"baseline {case['repository']}@{case['baseline_sha']}")

    if not args.skip_composer:
        run(["composer", "install", "--no-interaction", "--no-progress"], cwd=repo, log=log)

    manifest: dict = {
        "experiment": config["experiment"],
        "case": case["id"],
        "arm": args.arm,
        "repository": case["repository"],
        "baseline_sha": case["baseline_sha"],
        "baseline_commit": baseline_commit,
        "created_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "composer_skipped": args.skip_composer,
        "governance": None,
        "learning_store": None,
    }

    if args.arm in ("B", "C"):
        tool_dir = repo / "tools" / "agent-loop"
        tool_dir.mkdir(parents=True, exist_ok=True)
        tool_composer: dict = {"require": {"voku/agent-loop": args.agent_loop_constraint}, "config": {"allow-plugins": False}}
        if args.agent_loop_path is not None:
            tool_composer["repositories"] = [{"type": "path", "url": str(args.agent_loop_path.resolve()), "options": {"symlink": False}}]
            tool_composer["require"]["voku/agent-loop"] = "*@dev"
        (tool_dir / "composer.json").write_text(json.dumps(tool_composer, indent=2) + "\n", encoding="utf-8")
        with (repo / ".git" / "info" / "exclude").open("a", encoding="utf-8") as exclude:
            exclude.write("/tools/agent-loop/vendor/\n")

        resolved = None
        if not args.skip_composer:
            run(["composer", "install", "--no-interaction", "--no-progress"], cwd=tool_dir, log=log)
            lock = json.loads((tool_dir / "composer.lock").read_text(encoding="utf-8"))
            resolved = next((p["version"] for p in lock["packages"] if p["name"] == "voku/agent-loop"), None)
            run(["tools/agent-loop/vendor/bin/agent-loop", "init", "install-assets", f"--agent={args.host}"], cwd=repo, log=log)

        learning_source = args.learning_source.resolve()
        extract(learning_source, args.learning_sha, repo, ".agent-loop/learning")
        store = repo / ".agent-loop" / "learning"
        pattern = re.compile(config["leak_pattern"], re.IGNORECASE)
        removed = [Path(p).relative_to(repo).as_posix() for p in matching_files(store, pattern)]
        for relative in removed:
            (repo / relative).unlink()
        if args.arm == "C":
            extract(learning_source, args.learning_sha, repo, *config["precedent_files"])

        remaining = [Path(p).relative_to(repo).as_posix() for p in matching_files(store, pattern)]
        expected = sorted(config["precedent_files"]) if args.arm == "C" else []
        if sorted(remaining) != expected:
            sys.exit(f"learning store leak check failed: {remaining} != {expected}")

        start_commit = commit(repo, f"governance arm {args.arm}")
        governance_files = run(["git", "diff", "--name-only", baseline_commit, start_commit], cwd=repo).split()
        shared_mentions = [
            f for f in governance_files
            if not f.startswith(".agent-loop/learning/") and pattern.search((repo / f).read_text(encoding="utf-8", errors="replace"))
        ]
        manifest["governance"] = {
            "agent_loop_constraint": args.agent_loop_constraint,
            "agent_loop_resolved": resolved,
            "non_release": args.agent_loop_path is not None or args.skip_composer,
            "host": args.host,
            "assets_committed": len(governance_files),
            "assets_mentioning_lesson_terms": shared_mentions,
        }
        manifest["learning_store"] = {
            "source": "voku/agent-loop",
            "source_sha": args.learning_sha,
            "files": sum(1 for p in store.rglob("*") if p.is_file()),
            "removed_lesson_artifacts": removed,
            "precedent_present": args.arm == "C",
            "store_sha256": {
                p.relative_to(repo).as_posix(): sha256(p) for p in sorted(store.rglob("*")) if p.is_file()
            } if args.arm == "C" else None,
        }
    else:
        start_commit = baseline_commit

    manifest["start_commit"] = start_commit
    manifest["start_tree"] = run(["git", "rev-parse", f"{start_commit}^{{tree}}"], cwd=repo).strip()

    task = (HERE / "task.md").read_text(encoding="utf-8").format(
        version=case["version"], target=baseline_commit, changelog_heading=case["changelog_heading"],
    )
    (out / "task.md").write_text(task, encoding="utf-8")
    manifest["task_sha256"] = hashlib.sha256(task.encode("utf-8")).hexdigest()
    (out / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({k: manifest[k] for k in ("case", "arm", "baseline_commit", "start_commit", "task_sha256")}))


if __name__ == "__main__":
    main()
