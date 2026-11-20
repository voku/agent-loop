#!/usr/bin/env python3
"""Grade one finished arm of the pipefail learning-effect experiment.

Runs outside the candidate workspace and never modifies it. Inputs: <out>/manifest.json
and <out>/repo from prepare.py, optionally <out>/transcript.jsonl (Claude Code
stream-json) and <out>/interventions.jsonl (one line per human intervention, written
by the operator). Output: <out>/result.json, also appended to --ledger when given.

Objective measures:
  oracle            hidden historical regression test, injected under a new class name
                    into a copy of the final tree and run --repeat times (all must pass);
  recurrence        the final workflow still feeds `git show` into an early-exiting
                    consumer (`grep -q/-l/-m`, `head`), i.e. the lesson's mistake;
  own_test          the agent's added/changed tests pass on its tree and fail when only
                    the workflow is reverted to the baseline (detects the defect);
  edits             changed paths outside the allowed set (runtime state excluded);
  transcript        tool calls, tokens, turns, duration, errors, lesson re-introduction
                    attempts, Loop use and whether the precedent reached the agent.
Unrun or ungradeable parts are recorded as null with a reason, never as a pass.

Grading executes candidate-written code (the workflow script under the oracle, and the
agent's own tests). Run it only in a disposable environment, never on a workstation
holding credentials.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import shutil
import subprocess  # nosec B404 - fixed argument vectors only, never a shell
import sys
import tempfile
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path

HERE = Path(__file__).resolve().parent
WORKFLOW = ".github/workflows/release-tag.yml"
HIDDEN_CLASS = "HiddenReleaseTagOracleTest"
SHA = re.compile(r"^[0-9a-f]{40}$")
# The same extraction the historical oracle performs; a workflow it cannot parse is ungradeable, not wrong.
ORACLE_SCRIPT = re.compile(r"^        run: \|\R(?:^ {10}.*\R|^\R)*".replace(r"\R", r"\n"), re.MULTILINE)
EDIT_TOOLS = ("Edit", "Write", "MultiEdit", "NotebookEdit")


def executable(name: str) -> str:
    path = shutil.which(name)
    if path is None:
        sys.exit(f"required executable not found: {name}")
    return path


GIT = executable("git")
PHP = executable("php")


def git(repo: Path, *args: str, env: dict[str, str] | None = None) -> str:
    proc = subprocess.run([GIT, "-C", str(repo), *args], capture_output=True, text=True, env={**os.environ, **(env or {})})  # nosec B603
    if proc.returncode != 0:
        sys.exit(f"git {' '.join(args)} failed: {proc.stderr}")
    return proc.stdout


def commit_sha(value: str, label: str) -> str:
    if not SHA.match(value):
        sys.exit(f"{label} must be a full 40-character SHA, got {value!r}")
    return value


def final_tree(repo: Path, scratch: Path) -> str:
    """Snapshot the workspace (tracked + untracked, honouring ignores) without touching its index."""
    env = {"GIT_INDEX_FILE": str(scratch / "grade.index")}
    git(repo, "read-tree", "HEAD", env=env)
    git(repo, "add", "-A", env=env)
    return git(repo, "write-tree", env=env).strip()


def materialise(repo: Path, tree: str, destination: Path) -> None:
    """Write the tree to destination (not `git archive`, which drops export-ignore paths such as tests/)."""
    destination.mkdir(parents=True)
    env = {"GIT_INDEX_FILE": str(destination.parent / f"{destination.name}.index")}
    git(repo, "read-tree", tree, env=env)
    git(repo, f"--work-tree={destination}", "checkout-index", "--force", "--all", env=env)
    vendor = repo / "vendor"
    if vendor.is_dir() and not (destination / "vendor").exists():
        (destination / "vendor").symlink_to(vendor)


def phpunit(tree: Path, phpunit_bin: str, test: str, use_config: bool) -> tuple[int, str]:
    cmd = [PHP, phpunit_bin, "--do-not-cache-result", *([] if use_config else ["--no-configuration"]), "--", test]
    proc = subprocess.run(cmd, cwd=tree, capture_output=True, text=True, timeout=600)  # nosec B603
    return proc.returncode, (proc.stdout + proc.stderr)[-4000:]


def edit_summary(repo: Path, start_tree: str, tree: str, config: dict) -> dict:
    status = [line.split("\t", 1) for line in git(repo, "diff-tree", "-r", "--name-status", start_tree, tree).splitlines()]
    runtime = [path for _, path in status if path.startswith(tuple(config["runtime_state_paths"]))]
    candidate = [(kind, path) for kind, path in status if path not in runtime]
    allowed = config["allowed_edit_paths"]
    numstat = [line.split("\t") for line in git(repo, "diff-tree", "-r", "--numstat", start_tree, tree).splitlines()]
    candidate_paths = [path for _, path in candidate]
    return {
        "changed": candidate_paths,
        "unnecessary": [p for p in candidate_paths if not any(p == a or (a.endswith("/") and p.startswith(a)) for a in allowed)],
        "runtime_state_changed": len(runtime),
        "lines_changed": sum(int(a) + int(d) for a, d, p in numstat if p in candidate_paths and a != "-"),
        "own_tests": [p for kind, p in candidate if p.startswith("tests/") and p.endswith(".php") and kind[0] in "AM"],
    }


def workflow_at(repo: Path, tree: str) -> str:
    present = WORKFLOW in git(repo, "ls-tree", "-r", "--name-only", tree).split()
    return git(repo, "show", f"{tree}:{WORKFLOW}") if present else ""


def recurrence_lines(workflow: str, recurrence: re.Pattern[str]) -> list[str]:
    return [line.strip() for line in workflow.splitlines() if not line.lstrip().startswith("#") and recurrence.search(line)]


def run_oracle(source: Path, case: dict, repo: Path, tree: str, scratch: Path, phpunit_bin: str, repeat: int) -> tuple[Path, dict]:
    """Inject the hidden historical test under a class name the agent cannot collide with, and run it."""
    repaired = commit_sha(case["repaired_sha"], "repaired_sha")
    oracle_source = git(source, "show", f"{repaired}:{case['oracle_path']}")
    oracle_code = re.sub(rf"\bclass\s+{re.escape(case['oracle_class'])}\b", f"class {HIDDEN_CLASS}", oracle_source)
    oracle_tree = scratch / "oracle"
    materialise(repo, tree, oracle_tree)
    (oracle_tree / "tests" / f"{HIDDEN_CLASS}.php").write_text(oracle_code, encoding="utf-8")
    runs = [phpunit(oracle_tree, phpunit_bin, f"tests/{HIDDEN_CLASS}.php", use_config=False) for _ in range(repeat)]
    passes = sum(1 for code, _ in runs if code == 0)
    return oracle_tree, {"passes": passes, "repeat": repeat, "last_log_tail": runs[-1][1][-1500:]}


def check_own_tests(own_tests: list[str], repo: Path, start_tree: str, tree: str, oracle_tree: Path, scratch: Path, phpunit_bin: str, repeat: int) -> dict:
    """The agent's own regression test must catch the defect: green on its tree, red with only the workflow reverted."""
    own: dict = {"files": own_tests, "passes_on_final": None, "fails_on_reverted_workflow": None}
    if not own_tests:
        return own
    uses_config = (oracle_tree / "phpunit.xml").is_file() or (oracle_tree / "phpunit.xml.dist").is_file()
    own["passes_on_final"] = all(phpunit(oracle_tree, phpunit_bin, t, uses_config)[0] == 0 for t in own_tests)
    mutant_tree = scratch / "mutant"
    materialise(repo, tree, mutant_tree)
    (mutant_tree / WORKFLOW).write_text(workflow_at(repo, start_tree), encoding="utf-8")
    failures = sum(1 for _ in range(repeat) if any(phpunit(mutant_tree, phpunit_bin, t, uses_config)[0] != 0 for t in own_tests))
    own["fails_on_reverted_workflow"] = f"{failures}/{repeat}"
    return own


def tool_uses(event: dict) -> list[dict]:
    message = event.get("message") if isinstance(event.get("message"), dict) else {}
    return [b for b in (message.get("content") or []) if isinstance(b, dict) and b.get("type") == "tool_use"]


def tool_text(block: dict) -> str:
    payload = block.get("input", {})
    return "\n".join(str(v) for v in payload.values()) if isinstance(payload, dict) else json.dumps(payload)


def writes_construct(block: dict, recurrence: re.Pattern[str]) -> bool:
    return block.get("name") in EDIT_TOOLS and bool(recurrence_lines(tool_text(block), recurrence))


def transcript_events(path: Path) -> list[dict]:
    events = []
    for line in path.read_text(encoding="utf-8").splitlines():
        try:
            event = json.loads(line)
        except json.JSONDecodeError:
            continue
        if isinstance(event, dict):
            events.append(event)
    return events


def transcript_metrics(path: Path, recurrence: re.Pattern[str], lesson_markers: list[str]) -> dict | None:
    if not path.is_file():
        return None
    events = transcript_events(path)
    blocks = [block for event in events for block in tool_uses(event)]
    tools = Counter(str(block.get("name", "?")) for block in blocks)
    result = next((e for e in reversed(events) if e.get("type") == "result"), {})
    usage = result.get("usage") or {}
    return {
        "tool_calls": len(blocks),
        "tool_calls_by_name": dict(sorted(tools.items())),
        "num_turns": result.get("num_turns"),
        "duration_ms": result.get("duration_ms"),
        "is_error": result.get("is_error"),
        "result_subtype": result.get("subtype"),
        "input_tokens": usage.get("input_tokens"),
        "output_tokens": usage.get("output_tokens"),
        "cache_creation_input_tokens": usage.get("cache_creation_input_tokens"),
        "cache_read_input_tokens": usage.get("cache_read_input_tokens"),
        "total_cost_usd": result.get("total_cost_usd"),
        "permission_denials": len(result.get("permission_denials") or []),
        "lesson_construct_written": sum(1 for block in blocks if writes_construct(block, recurrence)),
        "loop_commands": sum(1 for b in blocks if b.get("name") == "Bash" and re.search(r"agent-loop\s+(enter|finish|status)", tool_text(b))),
        "precedent_exposed": any(e.get("type") in ("user", "system") and any(m in json.dumps(e) for m in lesson_markers) for e in events),
        "result_event_present": bool(result),
    }


def count_lines(path: Path) -> int | None:
    return len([line for line in path.read_text(encoding="utf-8").splitlines() if line.strip()]) if path.is_file() else None


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--out", required=True, type=Path)
    parser.add_argument("--source", required=True, type=Path, help="clone of the case repository holding the repaired commit (oracle source)")
    parser.add_argument("--phpunit", type=Path, help="PHPUnit entry point; default <out>/repo/vendor/bin/phpunit")
    parser.add_argument("--repeat", type=int, default=3)
    parser.add_argument("--ledger", type=Path, help="append the result as one JSON line")
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    config = json.loads((HERE / "cases.json").read_text(encoding="utf-8"))
    out = args.out.resolve()
    repo = out / "repo"
    manifest = json.loads((out / "manifest.json").read_text(encoding="utf-8"))
    case = next(c for c in config["cases"] if c["id"] == manifest["case"])
    recurrence = re.compile(config["recurrence_pattern"])
    phpunit_bin = (args.phpunit or repo / "vendor" / "bin" / "phpunit").resolve()
    if not phpunit_bin.is_file():
        sys.exit(f"PHPUnit entry point not found: {phpunit_bin}")
    start_tree = commit_sha(manifest["start_tree"], "start_tree")

    scratch = Path(tempfile.mkdtemp(prefix="learning-effect-grade-"))
    try:
        tree = final_tree(repo, scratch)
        edits = edit_summary(repo, start_tree, tree, config)
        workflow = workflow_at(repo, tree)
        ungradeable = ORACLE_SCRIPT.search(workflow) is None
        oracle_tree, oracle = run_oracle(args.source.resolve(), case, repo, tree, scratch, str(phpunit_bin), args.repeat)
        recurring = recurrence_lines(workflow, recurrence)
        result = {
            "experiment": manifest["experiment"],
            "case": manifest["case"],
            "arm": manifest["arm"],
            "graded_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
            "start_commit": manifest["start_commit"],
            "final_tree": tree,
            "correct": oracle["passes"] == args.repeat and not ungradeable,
            "oracle": {"passes": f"{oracle['passes']}/{args.repeat}", "ungradeable": ungradeable, "last_log_tail": oracle["last_log_tail"]},
            "repeated_mistake": bool(recurring),
            "recurrence_lines": recurring,
            "own_test": check_own_tests(edits.pop("own_tests"), repo, start_tree, tree, oracle_tree, scratch, str(phpunit_bin), args.repeat),
            "edits": edits,
            "human_interventions": count_lines(out / "interventions.jsonl"),
            "transcript": transcript_metrics(out / "transcript.jsonl", recurrence, [config["lesson"]["learning_note"], config["lesson"]["source_finding"]]),
            "manifest": {k: manifest.get(k) for k in ("baseline_commit", "task_sha256", "governance", "composer_skipped")},
            "run": json.loads((out / "run.json").read_text(encoding="utf-8")) if (out / "run.json").is_file() else None,
        }
        (out / "result.json").write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")
        if args.ledger:
            with args.ledger.open("a", encoding="utf-8") as ledger:
                ledger.write(json.dumps(result, separators=(",", ":")) + "\n")
        print(json.dumps({
            "case": result["case"], "arm": result["arm"], "correct": result["correct"], "repeated_mistake": result["repeated_mistake"],
            "oracle": result["oracle"]["passes"], "unnecessary_edits": len(edits["unnecessary"]),
        }))
    finally:
        shutil.rmtree(scratch, ignore_errors=True)


if __name__ == "__main__":
    main()
