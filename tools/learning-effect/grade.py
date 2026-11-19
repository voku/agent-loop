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
"""

from __future__ import annotations

import argparse
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path

HERE = Path(__file__).resolve().parent
WORKFLOW = ".github/workflows/release-tag.yml"


def git(repo: Path, *args: str, env: dict[str, str] | None = None) -> str:
    proc = subprocess.run(["git", "-C", str(repo), *args], capture_output=True, text=True, env={**os.environ, **(env or {})})
    if proc.returncode != 0:
        sys.exit(f"git {' '.join(args)} failed: {proc.stderr}")
    return proc.stdout


def final_tree(repo: Path, scratch: Path) -> str:
    """Snapshot the workspace (tracked + untracked, honouring ignores) without touching its index."""
    env = {"GIT_INDEX_FILE": str(scratch / "grade.index")}
    git(repo, "read-tree", "HEAD", env=env)
    git(repo, "add", "-A", env=env)
    return git(repo, "write-tree", env=env).strip()


def materialise(repo: Path, tree: str, destination: Path) -> None:
    """Write the tree to destination (not `git archive`, which drops export-ignore paths such as tests/)."""
    destination.mkdir(parents=True)
    env = {**os.environ, "GIT_INDEX_FILE": str(destination.parent / f"{destination.name}.index")}
    subprocess.run(["git", "-C", str(repo), "read-tree", tree], env=env, check=True, capture_output=True)
    subprocess.run(["git", "-C", str(repo), f"--work-tree={destination}", "checkout-index", "--force", "--all"], env=env, check=True, capture_output=True)
    vendor = repo / "vendor"
    if vendor.is_dir() and not (destination / "vendor").exists():
        (destination / "vendor").symlink_to(vendor)


def phpunit(tree: Path, phpunit_bin: str, test: str, use_config: bool) -> tuple[int, str]:
    cmd = ["php", phpunit_bin, "--do-not-cache-result"]
    if not use_config:
        cmd.append("--no-configuration")
    cmd.append(test)
    proc = subprocess.run(cmd, cwd=tree, capture_output=True, text=True, timeout=600)
    return proc.returncode, (proc.stdout + proc.stderr)[-4000:]


def transcript_metrics(path: Path, recurrence: re.Pattern[str], lesson_markers: list[str]) -> dict | None:
    if not path.is_file():
        return None
    tools: Counter[str] = Counter()
    result_event = None
    reintroductions = 0
    loop_commands = 0
    exposed = False
    for line in path.read_text(encoding="utf-8").splitlines():
        try:
            event = json.loads(line)
        except json.JSONDecodeError:
            continue
        raw = json.dumps(event)
        if event.get("type") in ("user", "system") and any(m in raw for m in lesson_markers):
            exposed = True
        if event.get("type") == "result":
            result_event = event
        message = event.get("message") if isinstance(event.get("message"), dict) else {}
        for block in message.get("content") or []:
            if not isinstance(block, dict) or block.get("type") != "tool_use":
                continue
            tools[block.get("name", "?")] += 1
            payload = json.dumps(block.get("input", {}))
            decoded = json.loads(payload)
            text = "\n".join(str(v) for v in decoded.values()) if isinstance(decoded, dict) else payload
            if block.get("name") in ("Edit", "Write", "MultiEdit", "NotebookEdit") and any(
                not line.lstrip().startswith("#") and recurrence.search(line) for line in text.splitlines()
            ):
                reintroductions += 1
            if block.get("name") == "Bash" and re.search(r"agent-loop\s+(enter|finish|status)", text):
                loop_commands += 1
    usage = (result_event or {}).get("usage") or {}
    return {
        "tool_calls": sum(tools.values()),
        "tool_calls_by_name": dict(sorted(tools.items())),
        "num_turns": (result_event or {}).get("num_turns"),
        "duration_ms": (result_event or {}).get("duration_ms"),
        "is_error": (result_event or {}).get("is_error"),
        "result_subtype": (result_event or {}).get("subtype"),
        "input_tokens": usage.get("input_tokens"),
        "output_tokens": usage.get("output_tokens"),
        "cache_creation_input_tokens": usage.get("cache_creation_input_tokens"),
        "cache_read_input_tokens": usage.get("cache_read_input_tokens"),
        "total_cost_usd": (result_event or {}).get("total_cost_usd"),
        "permission_denials": len((result_event or {}).get("permission_denials") or []),
        "lesson_construct_written": reintroductions,
        "loop_commands": loop_commands,
        "precedent_exposed": exposed,
        "result_event_present": result_event is not None,
    }


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--out", required=True, type=Path)
    parser.add_argument("--source", required=True, type=Path, help="clone of the case repository holding the repaired commit (oracle source)")
    parser.add_argument("--phpunit", help="PHPUnit entry point; default <out>/repo/vendor/bin/phpunit")
    parser.add_argument("--repeat", type=int, default=3)
    parser.add_argument("--ledger", type=Path, help="append the result as one JSON line")
    args = parser.parse_args()

    config = json.loads((HERE / "cases.json").read_text(encoding="utf-8"))
    out = args.out.resolve()
    repo = out / "repo"
    manifest = json.loads((out / "manifest.json").read_text(encoding="utf-8"))
    case = next(c for c in config["cases"] if c["id"] == manifest["case"])
    recurrence = re.compile(config["recurrence_pattern"])
    phpunit_bin = args.phpunit or str(repo / "vendor" / "bin" / "phpunit")

    scratch = Path(tempfile.mkdtemp(prefix="learning-effect-grade-"))
    try:
        tree = final_tree(repo, scratch)
        start_tree = manifest["start_tree"]
        status = [line.split("\t", 1) for line in git(repo, "diff-tree", "-r", "--name-status", start_tree, tree).splitlines()]
        changed = [path for _, path in status]
        runtime = [p for p in changed if any(p.startswith(prefix) for prefix in config["runtime_state_paths"])]
        candidate = [p for p in changed if p not in runtime]
        unnecessary = [p for p in candidate if not any(p == a or (a.endswith("/") and p.startswith(a)) for a in config["allowed_edit_paths"])]
        numstat = [line.split("\t") for line in git(repo, "diff-tree", "-r", "--numstat", start_tree, tree).splitlines()]
        lines_changed = sum(int(a) + int(d) for a, d, p in numstat if p in candidate and a != "-")

        workflow_text = git(repo, "show", f"{tree}:{WORKFLOW}") if WORKFLOW in git(repo, "ls-tree", "-r", "--name-only", tree).split() else ""
        recurrence_lines = [line.strip() for line in workflow_text.splitlines() if not line.lstrip().startswith("#") and recurrence.search(line)]

        # Hidden oracle: the historical repaired-side test under a class name the agent cannot collide with.
        oracle_source = subprocess.run(
            ["git", "-C", str(args.source.resolve()), "show", f"{case['repaired_sha']}:{case['oracle_path']}"],
            capture_output=True, text=True, check=True,
        ).stdout
        hidden_class = "HiddenReleaseTagOracleTest"
        oracle_code = re.sub(rf"\bclass\s+{case['oracle_class']}\b", f"class {hidden_class}", oracle_source)
        oracle_tree = scratch / "oracle"
        materialise(repo, tree, oracle_tree)
        (oracle_tree / "tests" / f"{hidden_class}.php").write_text(oracle_code, encoding="utf-8")
        oracle_runs = [phpunit(oracle_tree, phpunit_bin, f"tests/{hidden_class}.php", use_config=False) for _ in range(args.repeat)]
        oracle_passes = sum(1 for code, _ in oracle_runs if code == 0)
        extraction_failed = any("has no executable tag script" in log or "preg_match" in log for _, log in oracle_runs)

        # The agent's own regression test must catch the defect: green on its tree, red with only the workflow reverted.
        own_tests = [p for p, (s, _) in zip(changed, status) if p.startswith("tests/") and p.endswith(".php") and s[0] in "AM"]
        own: dict = {"files": own_tests, "passes_on_final": None, "fails_on_reverted_workflow": None}
        if own_tests:
            uses_config = (oracle_tree / "phpunit.xml").is_file() or (oracle_tree / "phpunit.xml.dist").is_file()
            final_codes = [phpunit(oracle_tree, phpunit_bin, t, uses_config)[0] for t in own_tests]
            mutant_tree = scratch / "mutant"
            materialise(repo, tree, mutant_tree)
            (mutant_tree / WORKFLOW).write_text(git(repo, "show", f"{start_tree}:{WORKFLOW}"), encoding="utf-8")
            mutant_failures = 0
            for _ in range(args.repeat):
                codes = [phpunit(mutant_tree, phpunit_bin, t, uses_config)[0] for t in own_tests]
                mutant_failures += any(code != 0 for code in codes)
            own["passes_on_final"] = all(code == 0 for code in final_codes)
            own["fails_on_reverted_workflow"] = f"{mutant_failures}/{args.repeat}"

        interventions_path = out / "interventions.jsonl"
        interventions = len([l for l in interventions_path.read_text(encoding="utf-8").splitlines() if l.strip()]) if interventions_path.is_file() else None

        result = {
            "experiment": manifest["experiment"],
            "case": manifest["case"],
            "arm": manifest["arm"],
            "graded_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
            "start_commit": manifest["start_commit"],
            "final_tree": tree,
            "correct": oracle_passes == args.repeat and not extraction_failed,
            "oracle": {
                "passes": f"{oracle_passes}/{args.repeat}",
                "ungradeable": extraction_failed,
                "last_log_tail": oracle_runs[-1][1][-1500:],
            },
            "repeated_mistake": bool(recurrence_lines),
            "recurrence_lines": recurrence_lines,
            "own_test": own,
            "edits": {
                "changed": candidate,
                "unnecessary": unnecessary,
                "runtime_state_changed": len(runtime),
                "lines_changed": lines_changed,
            },
            "human_interventions": interventions,
            "transcript": transcript_metrics(out / "transcript.jsonl", recurrence, [config["lesson"]["learning_note"], config["lesson"]["source_finding"]]),
            "manifest": {k: manifest.get(k) for k in ("baseline_commit", "task_sha256", "governance", "composer_skipped")},
            "run": json.loads((out / "run.json").read_text(encoding="utf-8")) if (out / "run.json").is_file() else None,
        }
        (out / "result.json").write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")
        if args.ledger:
            with args.ledger.open("a", encoding="utf-8") as ledger:
                ledger.write(json.dumps(result, separators=(",", ":")) + "\n")
        summary = {k: result[k] for k in ("case", "arm", "correct", "repeated_mistake")}
        summary["oracle"] = result["oracle"]["passes"]
        summary["unnecessary_edits"] = len(unnecessary)
        print(json.dumps(summary))
    finally:
        shutil.rmtree(scratch, ignore_errors=True)


if __name__ == "__main__":
    main()
