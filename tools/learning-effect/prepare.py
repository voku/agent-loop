#!/usr/bin/env python3
"""Prepare one isolated starting state for the pipefail learning-effect experiment.

Arms (see docs/dogfood/2026-11-19-learning-effect-pipefail.md):
  A  native host, repository as it was at the vulnerable baseline;
  B  A + Loop governance (isolated tools/agent-loop install, host assets) and a
     learning store copied from voku/agent-loop with every artifact of the lesson removed;
  C  B + exactly the lesson's LearningNote and its source Finding.

The workspace is a fresh single-commit repository written from the exact baseline tree,
with no remote and no history, so the historical repair cannot be read from it. The
baseline commit is created with fixed identity and dates, so it has the same SHA in every
arm. Everything about the run is recorded in <out>/manifest.json, outside the workspace.

Every executable is resolved to an absolute path once, every revision is validated as a
full commit SHA before it reaches git, and no command runs through a shell.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import shutil
import subprocess  # nosec B404 - fixed argument vectors only, never a shell
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path

HERE = Path(__file__).resolve().parent
SHA = re.compile(r"^[0-9a-f]{40}$")
FIXED_GIT_ENV = {
    "GIT_AUTHOR_NAME": "experiment",
    "GIT_AUTHOR_EMAIL": "experiment@example.invalid",
    "GIT_AUTHOR_DATE": "2026-01-01T00:00:00Z",
    "GIT_COMMITTER_NAME": "experiment",
    "GIT_COMMITTER_EMAIL": "experiment@example.invalid",
    "GIT_COMMITTER_DATE": "2026-01-01T00:00:00Z",
}


def executable(name: str) -> str:
    path = shutil.which(name)
    if path is None:
        sys.exit(f"required executable not found: {name}")
    return path


def commit_sha(value: str, label: str) -> str:
    """Accept only a full lowercase commit SHA, so no value can reach git as an option."""
    if not SHA.match(value):
        sys.exit(f"{label} must be a full 40-character commit SHA, got {value!r}")
    return value


def run(cmd: list[str], cwd: Path | None = None, env: dict[str, str] | None = None, log: Path | None = None, stdin: bytes | None = None) -> str:
    proc = subprocess.run(cmd, cwd=cwd, env={**os.environ, **(env or {})}, capture_output=True, input=stdin)  # nosec B603
    stdout = proc.stdout.decode("utf-8", errors="replace")
    stderr = proc.stderr.decode("utf-8", errors="replace")
    if log is not None:
        with log.open("a", encoding="utf-8") as handle:
            handle.write(f"$ {' '.join(cmd)}\n[exit {proc.returncode}]\n{stdout}{stderr}\n")
    if proc.returncode != 0:
        sys.exit(f"command failed ({proc.returncode}): {' '.join(cmd)}\n{stdout}{stderr}")
    return stdout


class Git:
    def __init__(self) -> None:
        self.binary = executable("git")

    def __call__(self, repo: Path, *args: str, env: dict[str, str] | None = None, stdin: bytes | None = None) -> str:
        return run([self.binary, "-C", str(repo), *args], env=env, stdin=stdin)

    def extract(self, source: Path, revision: str, destination: Path, *paths: str) -> None:
        """Write the exact tree (or paths of it) to destination.

        Not `git archive`: it honours export-ignore attributes and would drop tests/ and
        .github/ from the very repositories this experiment needs them in.
        """
        commit_sha(revision, "revision")
        with tempfile.TemporaryDirectory() as scratch:
            env = {"GIT_INDEX_FILE": str(Path(scratch) / "index")}
            self(source, "read-tree", revision, env=env)
            names = self(source, "ls-tree", "-r", "-z", "--name-only", revision, "--", *paths)
            if paths and not names:
                sys.exit(f"{revision} has no {' '.join(paths)}")
            self(source, f"--work-tree={destination}", "checkout-index", "--force", "-z", "--stdin", env=env, stdin=names.encode("utf-8"))

    def commit(self, repo: Path, message: str) -> str:
        self(repo, "add", "-A")
        self(repo, "commit", "--quiet", "--no-verify", "--allow-empty", "-m", message, env=FIXED_GIT_ENV)
        return self(repo, "rev-parse", "HEAD").strip()


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


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--case", required=True)
    parser.add_argument("--arm", required=True, choices=["A", "B", "C"])
    parser.add_argument("--out", required=True, type=Path, help="new directory; the workspace is <out>/repo")
    parser.add_argument("--source", required=True, type=Path, help="local clone of the case repository (stays outside the workspace)")
    parser.add_argument("--learning-source", type=Path, help="voku/agent-loop clone providing the learning store (arms B, C)")
    parser.add_argument("--learning-sha", default="5bc9f6900f1baae047a4c7ef8039aea2ce88f2fb")
    parser.add_argument("--agent-loop-constraint", default="0.20.97", help="exact released voku/agent-loop version (arms B, C)")
    parser.add_argument("--agent-loop-path", type=Path, help="LOCAL VALIDATION ONLY: install agent-loop from this checkout; marks the run non-release")
    parser.add_argument("--host", default="claude", choices=["claude", "codex"], help="host passed to `agent-loop init install-assets --agent`")
    parser.add_argument("--skip-composer", action="store_true", help="LOCAL VALIDATION ONLY: skip all Composer installs")
    args = parser.parse_args()
    commit_sha(args.learning_sha, "--learning-sha")
    if not re.match(r"^\d+\.\d+\.\d+$", args.agent_loop_constraint):
        sys.exit("--agent-loop-constraint must be an exact released version such as 0.20.97")
    if args.arm != "A" and args.learning_source is None:
        sys.exit("arms B and C need --learning-source")
    return args


def install_governance(args: argparse.Namespace, repo: Path, log: Path) -> str | None:
    """Install released Loop into tools/agent-loop and project the host assets. Returns the resolved version."""
    tool_dir = repo / "tools" / "agent-loop"
    tool_dir.mkdir(parents=True, exist_ok=True)
    tool_composer: dict = {"require": {"voku/agent-loop": args.agent_loop_constraint}, "config": {"allow-plugins": False}}
    if args.agent_loop_path is not None:
        tool_composer["repositories"] = [{"type": "path", "url": str(args.agent_loop_path.resolve()), "options": {"symlink": False}}]
        tool_composer["require"]["voku/agent-loop"] = "*@dev"
    (tool_dir / "composer.json").write_text(json.dumps(tool_composer, indent=2) + "\n", encoding="utf-8")
    with (repo / ".git" / "info" / "exclude").open("a", encoding="utf-8") as exclude:
        exclude.write("/tools/agent-loop/vendor/\n")
    if args.skip_composer:
        return None
    run([executable("composer"), "install", "--no-interaction", "--no-progress"], cwd=tool_dir, log=log)
    lock = json.loads((tool_dir / "composer.lock").read_text(encoding="utf-8"))
    run([str(tool_dir / "vendor" / "bin" / "agent-loop"), "init", "install-assets", f"--agent={args.host}"], cwd=repo, log=log)
    return next((p["version"] for p in lock["packages"] if p["name"] == "voku/agent-loop"), None)


def seed_learning_store(git: Git, args: argparse.Namespace, config: dict, repo: Path) -> dict:
    """Copy agent-loop's store, remove every lesson artifact, and in arm C restore exactly the precedent."""
    learning_source = args.learning_source.resolve()
    git.extract(learning_source, args.learning_sha, repo, ".agent-loop/learning")
    store = repo / ".agent-loop" / "learning"
    pattern = re.compile(config["leak_pattern"], re.IGNORECASE)
    removed = [Path(p).relative_to(repo).as_posix() for p in matching_files(store, pattern)]
    for relative in removed:
        (repo / relative).unlink()
    if args.arm == "C":
        git.extract(learning_source, args.learning_sha, repo, *config["precedent_files"])

    remaining = [Path(p).relative_to(repo).as_posix() for p in matching_files(store, pattern)]
    expected = sorted(config["precedent_files"]) if args.arm == "C" else []
    if sorted(remaining) != expected:
        sys.exit(f"learning store leak check failed: {remaining} != {expected}")
    return {
        "source": "voku/agent-loop",
        "source_sha": args.learning_sha,
        "files": sum(1 for p in store.rglob("*") if p.is_file()),
        "removed_lesson_artifacts": removed,
        "precedent_present": args.arm == "C",
        "store_sha256": {
            p.relative_to(repo).as_posix(): hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(store.rglob("*")) if p.is_file()
        },
    }


def lesson_term_mentions(repo: Path, files: list[str], pattern: re.Pattern[str]) -> list[str]:
    return [
        f for f in files
        if not f.startswith(".agent-loop/learning/") and pattern.search((repo / f).read_text(encoding="utf-8", errors="replace"))
    ]


def main() -> None:
    args = parse_args()
    config = json.loads((HERE / "cases.json").read_text(encoding="utf-8"))
    case = next((c for c in config["cases"] if c["id"] == args.case), None)
    if case is None:
        sys.exit(f"unknown case {args.case}")
    baseline_sha = commit_sha(case["baseline_sha"], "baseline_sha")

    out = args.out.resolve()
    repo = out / "repo"
    if out.exists():
        sys.exit(f"{out} already exists; every run starts from a new directory")
    repo.mkdir(parents=True)
    log = out / "prepare.log"
    git = Git()

    source = args.source.resolve()
    if git(source, "rev-parse", "--verify", f"{baseline_sha}^{{commit}}").strip() != baseline_sha:
        sys.exit("baseline commit missing from --source")
    git.extract(source, baseline_sha, repo)
    git(repo, "init", "--quiet", "-b", "main")
    baseline_commit = git.commit(repo, f"baseline {case['repository']}@{baseline_sha}")
    if not args.skip_composer:
        run([executable("composer"), "install", "--no-interaction", "--no-progress"], cwd=repo, log=log)

    manifest: dict = {
        "experiment": config["experiment"],
        "case": case["id"],
        "arm": args.arm,
        "repository": case["repository"],
        "baseline_sha": baseline_sha,
        "baseline_commit": baseline_commit,
        "created_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "composer_skipped": args.skip_composer,
        "governance": None,
        "learning_store": None,
    }
    start_commit = baseline_commit
    if args.arm in ("B", "C"):
        resolved = install_governance(args, repo, log)
        manifest["learning_store"] = seed_learning_store(git, args, config, repo)
        start_commit = git.commit(repo, f"governance arm {args.arm}")
        governance_files = git(repo, "diff", "--name-only", baseline_commit, start_commit).split()
        manifest["governance"] = {
            "agent_loop_constraint": args.agent_loop_constraint,
            "agent_loop_resolved": resolved,
            "non_release": args.agent_loop_path is not None or args.skip_composer,
            "host": args.host,
            "assets_committed": len(governance_files),
            "assets_mentioning_lesson_terms": lesson_term_mentions(repo, governance_files, re.compile(config["leak_pattern"], re.IGNORECASE)),
        }

    manifest["start_commit"] = start_commit
    manifest["start_tree"] = git(repo, "rev-parse", f"{start_commit}^{{tree}}").strip()
    task = (HERE / "task.md").read_text(encoding="utf-8").format(
        version=case["version"], target=baseline_commit, changelog_heading=case["changelog_heading"],
    )
    (out / "task.md").write_text(task, encoding="utf-8")
    manifest["task_sha256"] = hashlib.sha256(task.encode("utf-8")).hexdigest()
    (out / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({k: manifest[k] for k in ("case", "arm", "baseline_commit", "start_commit", "task_sha256")}))


if __name__ == "__main__":
    main()
