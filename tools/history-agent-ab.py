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
        results[arm] = {
            "sha256": hashlib.sha256(answer.encode()).hexdigest(),
            "answer_excerpt": answer[:2500],
            "commit_ids": commit_ids,
            "culprit_identified": oracle["culprit_sha"] in commit_ids,
            "relevant_file_identified": "AccessPolicy.php" in answer,
            "mentions_role_predicate": "in_array" in answer,
        }
    baseline = results["baseline"]["culprit_identified"]
    history = results["history"]["culprit_identified"]
    difference = (
        "history_only" if history and not baseline
        else "baseline_only" if baseline and not history
        else "equivalent_on_commit_identification"
    )
    report = {
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
            "Both agents had equal Git/read access; only injected investigator guidance differs.",
            "A model answer is not proof that it invoked Git; inspect full host receipts if available.",
            "Exact SHA identification is a narrow outcome, not general bugfix correctness.",
            "A single pair cannot establish causal benefit or reliable cost/performance.",
        ],
    }
    write(output, json.dumps(report, indent=2, sort_keys=True) + "\n")
    print(
        "Real-model A/B report saved; "
        f"baseline culprit={baseline}, history culprit={history}, "
        f"comparison={difference}"
    )


def main() -> None:
    parser = argparse.ArgumentParser()
    command = parser.add_subparsers(dest="command", required=True)
    prep = command.add_parser("prepare")
    prep.add_argument("--root", type=Path, required=True)
    prep.add_argument("--investigator", type=Path, required=True)
    report = command.add_parser("grade")
    report.add_argument("--root", type=Path, required=True)
    report.add_argument("--output", type=Path, required=True)
    report.add_argument("--model", type=str, required=True)
    report.add_argument("--cli-version", type=str, required=True)
    args = parser.parse_args()
    if args.command == "prepare":
        prepare(args.root, args.investigator)
    else:
        grade(args.root, args.output, args.model, args.cli_version)


if __name__ == "__main__":
    main()
