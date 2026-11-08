#!/usr/bin/env python3
"""One-pair, read-only real-model regression experiment; not a quality benchmark."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import subprocess
from pathlib import Path


TASK = (
    "A user assigned the reports role can no longer read reports. "
    "Investigate this PHP repository at HEAD without modifying anything. "
    "Identify the likely cause, the source evidence, the most relevant introducing "
    "change if substantiated, and the smallest safe correction. "
    "Report uncertainty rather than guessing. Include the full commit SHA if verified. "
    "Keep your answer concise."
)

INITIAL = """<?php

declare(strict_types=1);

final class AccessPolicy
{
    /** @param list<string> $roles */
    public function canRead(array $roles): bool
    {
        return in_array('reports', $roles, true);
    }
}
"""

REGRESSION_TEST = """<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/AccessPolicy.php';

$policy = new AccessPolicy();
if (!$policy->canRead(['reports'])) {
    fwrite(STDERR, "Regression: reports role unexpectedly denied\n");
    exit(1);
}

echo "reports role accepted\n";
"""


def git(repo: Path, *args: str) -> str:
    result = subprocess.run(
        ["git", "-C", str(repo), *args],
        capture_output=True,
        text=True,
        check=True,
    )
    return result.stdout.strip()


def write(path: Path, value: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(value, encoding="utf-8")


def prepare(root: Path, investigator: Path) -> None:
    if root.exists() and any(root.iterdir()):
        raise SystemExit(f"Refusing to overwrite nonempty fixture directory: {root}")
    root.mkdir(parents=True, exist_ok=True)
    source = root / "source"
    source.mkdir()
    git(source, "init", "--initial-branch=main")
    git(source, "config", "user.name", "Regression Experiment")
    git(source, "config", "user.email", "history-ab@example.invalid")

    write(source / "src/LegacyAccessPolicy.php", INITIAL)
    git(source, "add", ".")
    git(source, "commit", "-m", "feat: add reports access policy")
    initial_sha = git(source, "rev-parse", "HEAD")

    git(source, "mv", "src/LegacyAccessPolicy.php", "src/AccessPolicy.php")
    write(source / "tests/probe.php", REGRESSION_TEST)
    git(source, "add", ".")
    git(source, "commit", "-m", "refactor: relocate access policy")
    rename_sha = git(source, "rev-parse", "HEAD")

    old = (source / "src/AccessPolicy.php").read_text(encoding="utf-8")
    if old.count("return in_array(") != 1:
        raise SystemExit("Expected one role predicate before regression")
    write(source / "src/AccessPolicy.php", old.replace("return in_array(", "return !in_array("))
    git(source, "add", ".")
    git(source, "commit", "-m", "maintenance: adjust access policy predicate")
    culprit_sha = git(source, "rev-parse", "HEAD")

    with (source / "src/AccessPolicy.php").open("a", encoding="utf-8") as handle:
        handle.write("\n// Related support docs: roles are managed externally.\n")
    git(source, "add", ".")
    git(source, "commit", "-m", "docs: clarify responsibility for roles")
    head_sha = git(source, "rev-parse", "HEAD")

    for arm in ("baseline", "history"):
        repo = root / arm
        subprocess.run(
            ["git", "clone", "--quiet", "--no-hardlinks", str(source), str(repo)],
            check=True,
        )
        if git(repo, "rev-parse", "HEAD") != head_sha:
            raise SystemExit(f"{arm}: checkout differs from frozen fixture")

    guide = investigator.read_text(encoding="utf-8")
    start = guide.index("For a **regression or bug fix in existing behavior**")
    end = guide.index("Return commit IDs", start)
    history_guidance = guide[start:end].strip()
    for marker in ("git log --follow", "history_incomplete=true", "skip greenfield"):
        if marker not in history_guidance:
            raise SystemExit(f"History guidance changed: missing {marker}")

    write(root / "baseline-prompt.txt", TASK + "\n")
    write(
        root / "history-prompt.txt",
        TASK + "\n\nRead-only investigation guidance for this task:\n"
        + history_guidance + "\n",
    )
    write(
        root / "oracle.json",
        json.dumps(
            {
                "schema_version": "1.0",
                "initial_sha": initial_sha,
                "rename_sha": rename_sha,
                "culprit_sha": culprit_sha,
                "head_sha": head_sha,
                "task": "reports-role-regression-after-rename",
                "gold_path": "src/AccessPolicy.php",
                "baseline_prompt_sha256": hashlib.sha256(TASK.encode()).hexdigest(),
                "history_guidance_sha256": hashlib.sha256(history_guidance.encode()).hexdigest(),
            },
            indent=2,
        ) + "\n",
    )

    # The oracle is only outside the agents' working directories.
    print(f"Prepared two identical read-only regression clones at {head_sha}")



def parse_stream(stream: Path, answer_path: Path, receipt_path: Path) -> None:
    """Reduce Copilot JSONL to public fixture evidence without retaining tool arguments."""
    messages: list[str] = []
    tool_names: list[str] = []
    model_ids: set[str] = set()
    event_count = 0

    for line_number, raw in enumerate(stream.read_text(encoding="utf-8").splitlines(), start=1):
        if not raw.strip():
            continue
        try:
            event = json.loads(raw)
        except json.JSONDecodeError as error:
            raise SystemExit(f"Invalid Copilot JSONL line {line_number}: {error.msg}") from error
        if not isinstance(event, dict) or not isinstance(event.get("type"), str):
            raise SystemExit(f"Invalid Copilot event on line {line_number}")
        event_count += 1
        kind = event["type"]
        data = event.get("data", {})
        if not isinstance(data, dict):
            data = {}
        model = data.get("model") or data.get("modelId")
        if isinstance(model, str) and model:
            model_ids.add(model)
        if kind in ("tool.execution_start", "tool_call"):
            name = data.get("toolName") or event.get("tool")
            if isinstance(name, str) and name:
                tool_names.append(name)
        if kind in ("assistant.message", "agent_message_chunk"):
            content = data.get("content") if kind == "assistant.message" else event.get("text")
            if isinstance(content, str) and content.strip():
                messages.append(content)

    if not messages:
        raise SystemExit("Copilot JSONL has no visible assistant.message/agent_message_chunk content")

    # Keep the last complete answer, or concatenate chunks for older event formats.
    answer = messages[-1] if any(
        '"type":"assistant.message"' in line for line in stream.read_text(encoding="utf-8").splitlines()
    ) else "".join(messages)
    if not answer.strip():
        raise SystemExit("Copilot JSONL did not produce a nonempty final answer")
    write(answer_path, answer.strip() + "\n")
    write(receipt_path, json.dumps({
        "schema_version": "1.0",
        "event_count": event_count,
        "model_ids": sorted(model_ids),
        "tool_calls_observed": len(tool_names),
        "source_tools_observed": sum(
            1 for name in tool_names
            if name.lower() in ("read", "view", "grep", "glob", "shell", "bash")
        ),
        "tool_names": sorted(set(tool_names)),
    }, indent=2) + "\n")


def grade(root: Path, output: Path, model: str, cli_version: str) -> None:
    oracle = json.loads((root / "oracle.json").read_text(encoding="utf-8"))
    results: dict[str, dict[str, object]] = {}
    for arm in ("baseline", "history"):
        answer = (root / f"{arm}-answer.txt").read_text(encoding="utf-8").strip()
        if not answer:
            raise SystemExit(f"{arm}: real model produced no answer")
        repo = root / arm
        if git(repo, "rev-parse", "HEAD") != oracle["head_sha"]:
            raise SystemExit(f"{arm}: fixture head was changed during model run")
        if git(repo, "status", "--porcelain"):
            raise SystemExit(f"{arm}: model modified tracked or untracked source")
        commit_ids = sorted(set(re.findall(r"\b[0-9a-f]{40}\b", answer)))
        telemetry = json.loads((root / f"{arm}-telemetry.json").read_text(encoding="utf-8"))
        results[arm] = {
            "sha256": hashlib.sha256(answer.encode()).hexdigest(),
            "host_telemetry": telemetry,
            "answer_excerpt": answer[:2500],
            "commit_ids": commit_ids,
            "culprit_identified": oracle["culprit_sha"] in commit_ids,
            "relevant_file_identified": (
                "AccessPolicy.php" in answer or "AccessPolicy::canRead" in answer
            ),
            "mentions_role_predicate": "in_array" in answer,
            "reported_missing_repo_tools": bool(re.search(
                r"no shell|no git tool|tools aren|tools are not available|can.t inspect the repository|cannot inspect the repository",
                answer, re.IGNORECASE,
            )),
        }
    baseline = results["baseline"]["culprit_identified"]
    history = results["history"]["culprit_identified"]
    difference = (
        "history_only" if history and not baseline
        else "baseline_only" if baseline and not history
        else "equivalent_on_commit_identification"
    )
    invalid_tools = any(
        result["reported_missing_repo_tools"] or
        result["host_telemetry"]["source_tools_observed"] == 0
        for result in results.values()
    )
    report = {
        "status": "invalid_tools_unavailable" if invalid_tools else "observed",
        "schema_version": "1.0",
        "evidence_class": "real_copilot_cli_one_pair",
        "model_requested": model,
        "cli_version": cli_version,
        "fixture_head_sha": oracle["head_sha"],
        "oracle_culprit_sha": oracle["culprit_sha"],
        "arms": results,
        "observed_commit_identification_difference": difference,
        "limitations": [
            "One small synthetic PHP regression with an unrelated newer commit and rename.",
            "Both agents have the same declared Git/read grants; actual tool access is evaluated separately.",
            "A model answer is not proof that it invoked Git; inspect full host receipts if available.",
            "Exact SHA identification is a narrow outcome, not general bugfix correctness.",
            "A single pair cannot establish causal benefit or reliable cost/performance.",
            "Copilot auto model selection may choose different models per arm; no model-parity claim is made.",
        ],
    }
    write(output, json.dumps(report, indent=2, sort_keys=True) + "\n")
    print(
        "Real-model A/B report saved; "
        f"baseline culprit={baseline}, history culprit={history}, "
        f"comparison={difference}"
    )
    if invalid_tools:
        raise SystemExit("Invalid A/B: at least one agent reported unavailable repository tools")


def main() -> None:
    parser = argparse.ArgumentParser()
    command = parser.add_subparsers(dest="command", required=True)
    prep = command.add_parser("prepare")
    prep.add_argument("--root", type=Path, required=True)
    prep.add_argument("--investigator", type=Path, required=True)
    parse = command.add_parser("parse-stream")
    parse.add_argument("--stream", type=Path, required=True)
    parse.add_argument("--answer", type=Path, required=True)
    parse.add_argument("--receipt", type=Path, required=True)
    report = command.add_parser("grade")
    report.add_argument("--root", type=Path, required=True)
    report.add_argument("--output", type=Path, required=True)
    report.add_argument("--model", type=str, required=True)
    report.add_argument("--cli-version", type=str, required=True)
    args = parser.parse_args()
    if args.command == "prepare":
        prepare(args.root, args.investigator)
    elif args.command == "parse-stream":
        parse_stream(args.stream, args.answer, args.receipt)
    else:
        grade(args.root, args.output, args.model, args.cli_version)


if __name__ == "__main__":
    main()
