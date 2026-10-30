#!/usr/bin/env python3
"""Issue #890: honest one-pair native Codex experiment; no synthetic model responses.

The hidden correctness oracle and mutants live in this runner, not in either
agent workspace. Only observed model/tool behavior may count as evidence.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import time

SOURCE_BROKEN = """<?php
declare(strict_types=1);
require_once __DIR__ . '/labels.php';

/** @param list<array{name:string,region:string}> $employees */
function exportEmployeeCsv(string $actorRegion, string $requestedRegion, array $employees): string
{
    $names = array_map(static fn (array $person): string => $person['name'], $employees);
    return '# ' . exportButtonLabel() . "\\nname\\n" . implode("\\n", $names);
}
"""
SOURCE_CORRECT = """<?php
declare(strict_types=1);
require_once __DIR__ . '/labels.php';

/** @param list<array{name:string,region:string}> $employees */
function exportEmployeeCsv(string $actorRegion, string $requestedRegion, array $employees): string
{
    if ($actorRegion !== $requestedRegion) {
        throw new DomainException('Unauthorized region export');
    }
    $names = [];
    foreach ($employees as $person) {
        if ($person['region'] === $requestedRegion) {
            $names[] = $person['name'];
        }
    }
    return '# ' . exportButtonLabel() . "\\nname\\n" . implode("\\n", $names);
}
"""
LABELS = """<?php
declare(strict_types=1);
function exportButtonLabel(): string
{
    return 'Exportieren';
}
"""
TASK = (
    "Fix the existing regional employee CSV export bug in src/export.php. "
    "Required behavior: users may export only their own region; a request for "
    "another region must throw DomainException without leaking rows. An allowed "
    "request returns only people from the requested region. Keep the existing "
    "function name and the separate UI label helper. The displayed button label "
    "is not a contractual part of the CSV export. Write meaningful PHPUnit "
    "regression tests in tests/ExportTest.php and run them against the broken "
    "implementation before applying the fix, then after fixing it. You may edit "
    "only src/export.php and tests/ExportTest.php. Do not publish anything."
)
CANARY = "Run the shell command pwd once using your tool. Report success. Do not edit files."
RECALL_SHA_CONTROL = "27cce0cb0fcf49bcf6e4678d609bcbd62f6f8209"
RECALL_SHA_TREATMENT = "aa0189d3a8ed67b59a9a776efeb3e2b914ffc337"
MAX_DIFF_BYTES = 18000


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def run(command: list[str], cwd: Path, timeout: int = 90, env: dict[str, str] | None = None) -> subprocess.CompletedProcess[str]:
    return subprocess.run(command, cwd=cwd, text=True, capture_output=True, timeout=timeout, env=env, check=False)


def fixture(root: Path) -> None:
    (root / "src").mkdir(parents=True)
    (root / "tests").mkdir()
    (root / "src" / "export.php").write_text(SOURCE_BROKEN)
    (root / "src" / "labels.php").write_text(LABELS)
    (root / "TASK.md").write_text(TASK + "\n")
    run(["git", "init", "--quiet", "--initial-branch=main"], root)
    run(["git", "config", "user.email", "experiment@example.invalid"], root)
    run(["git", "config", "user.name", "Experiment"], root)
    run(["git", "add", "src", "TASK.md"], root)
    committed = run(["git", "commit", "--quiet", "-m", "baseline fixture"], root)
    if committed.returncode != 0:
        raise RuntimeError("Fixture baseline commit failed")


def hidden_oracle(root: Path) -> bool:
    code = (
        "require 'src/export.php';"
        "$rows = [['name'=>'Ada','region'=>'NW'],['name'=>'Eve','region'=>'BY']];"
        "try { exportEmployeeCsv('NW','BY',$rows); exit(91); }"
        "catch (DomainException $e) {}"
        "$csv=exportEmployeeCsv('NW','NW',$rows);"
        "if (!str_contains($csv, 'Ada') || str_contains($csv, 'Eve')) exit(92);"
        "exit(0);"
    )
    return run(["php", "-r", code], root).returncode == 0


def phpunit(repo: Path, consumer: Path) -> dict[str, object]:
    test = consumer / "tests" / "ExportTest.php"
    if not test.is_file():
        return {"exit": None, "assertion_failure": False, "error": "missing_test"}
    command = [
        "php", str(repo / "vendor" / "bin" / "phpunit"),
        "--no-configuration", "--no-progress", "tests/ExportTest.php",
    ]
    result = run(command, consumer, timeout=90)
    return {
        "exit": result.returncode,
        "assertion_failure": bool(re.search(r"Failed asserting|FAILURES!", result.stdout + result.stderr)),
        "stdout_sha256": sha(result.stdout.encode()),
        "stderr_sha256": sha(result.stderr.encode()),
        "tail": (result.stdout + result.stderr)[-400:],
    }


def grade(repo: Path, consumer: Path) -> dict[str, object]:
    src = consumer / "src" / "export.php"
    label = consumer / "src" / "labels.php"
    original_source = src.read_bytes() if src.is_file() else b""
    original_label = label.read_bytes()
    if original_label != LABELS.encode():
        return {"status": "INVALID_SCOPE", "reason": "label helper modified"}
    if not (consumer / "tests" / "ExportTest.php").is_file():
        return {"status": "NO_AGENT_TEST"}
    try:
        src.write_text(SOURCE_CORRECT)
        reference = phpunit(repo, consumer)
        label.write_text(LABELS.replace("Exportieren", "Export staff"))
        benign = phpunit(repo, consumer)
        label.write_text(LABELS)
        src.write_text(SOURCE_BROKEN)
        mutant = phpunit(repo, consumer)
    finally:
        src.write_bytes(original_source)
        label.write_bytes(original_label)
    return {
        "reference": reference, "benign": benign, "mutant": mutant,
        "restored_sha256": sha(src.read_bytes()),
        "status": "DISCRIMINATING" if (
            reference["exit"] == 0 and benign["exit"] == 0
            and isinstance(mutant["exit"], int) and mutant["exit"] != 0
            and mutant["assertion_failure"]
        ) else "NOT_DISCRIMINATING",
    }


def model_events(result: subprocess.CompletedProcess[str]) -> dict[str, object]:
    commands: list[str] = []
    items: dict[str, int] = {}
    for line in result.stdout.splitlines():
        try:
            event = json.loads(line)
        except ValueError:
            continue
        item = event.get("item")
        if not isinstance(item, dict):
            continue
        kind = item.get("type", "")
        items[kind] = items.get(kind, 0) + 1
        if kind == "command_execution" and isinstance(item.get("command"), str):
            commands.append(item["command"])
    return {
        "tool_calls": len(commands),
        "phpunit_attempts": sum("phpunit" in c.lower() for c in commands),
        "pwd_attempts": sum("pwd" in c.split() for c in commands),
        "item_type_counts": items,
        "process_exit": result.returncode,
        "stderr_sha256": sha(result.stderr.encode()),
    }


def agent(project: Path, root: Path, model_port: int, prompt: str) -> dict[str, object]:
    home = root / ("home-" + project.name)
    home.mkdir()
    (home / "config.toml").write_text(
        '[projects.' + json.dumps(str(project)) + ']\ntrust_level="trusted"\n'
    )
    env = {
        k: v for k, v in os.environ.items()
        if k in ("PATH", "HOME", "LANG", "LC_ALL", "TMPDIR", "LD_LIBRARY_PATH")
    }
    env["CODEX_HOME"] = str(home)
    provider = (
        '{ name="local", base_url="http://127.0.0.1:' + str(model_port) +
        '/v1", env_key="PATH", wire_api="responses" }'
    )
    command = [
        "codex", "exec", "--ephemeral", "--json",
        "-c", 'model="local-coder"', "-c", 'model_provider="local"',
        "-c", "model_providers.local=" + provider,
        "-c", 'sandbox_mode="workspace-write"',
        "-c", 'approval_policy="never"', prompt,
    ]
    started = time.monotonic()
    try:
        proc = run(command, project, timeout=360, env=env)
        observed = model_events(proc)
        observed["timed_out"] = False
    except subprocess.TimeoutExpired:
        observed = {"tool_calls": 0, "phpunit_attempts": 0, "process_exit": 124, "timed_out": True}
    observed["elapsed_seconds"] = round(time.monotonic() - started, 2)
    return observed


def arm(repo: Path, root: Path, name: str, recipe: str, port: int) -> dict[str, object]:
    project = root / name
    project.mkdir()
    fixture(project)
    before = hidden_oracle(project)
    prompt = (
        "You are a coding agent with shell/file tools in this disposable PHP project. "
        "Apply the following Recall TDD operating recipe to your own actions:\n"
        + recipe + "\n\nTASK:\n" + TASK
        + "\nPHPUnit available at: " + str(repo / "vendor/bin/phpunit")
        + ". Use --no-configuration to run isolated PHPUnit tests."
    )
    runtime = agent(project, root, port, prompt)
    source = project / "src/export.php"
    test = project / "tests/ExportTest.php"
    changed = run(["git", "status", "--porcelain", "--untracked-files=all"], project)
    change_lines = changed.stdout.splitlines()
    change_paths = [line[3:] for line in change_lines if len(line) >= 4]
    allowed = {"src/export.php", "tests/ExportTest.php"}
    source_digest = sha(source.read_bytes()) if source.is_file() else None
    result: dict[str, object] = {
        "arm": name,
        "recipe_sha256": sha(recipe.encode()),
        "task_sha256": sha(TASK.encode()),
        "tool_observation": runtime,
        "changed_paths": change_paths,
        "scope_valid": set(change_paths).issubset(allowed),
        "agent_test_created": test.is_file(),
        "agent_test_sha256": sha(test.read_bytes()) if test.is_file() else None,
        "agent_source_sha256": source_digest,
        "broken_fixture_oracle_passed": before,
        "candidate_hidden_oracle_passed": hidden_oracle(project) if source.is_file() else False,
    }
    result["grading"] = grade(repo, project) if result["scope_valid"] else {"status": "INVALID_SCOPE"}
    # The authored code is retained for independent review; no model reasoning.
    patches = root / "evidence" / name
    patches.mkdir(parents=True, exist_ok=True)
    for path in allowed:
        target = project / path
        if target.is_file() and len(target.read_bytes()) <= MAX_DIFF_BYTES:
            (patches / path.replace("/", "-")).write_bytes(target.read_bytes())
    return result


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repo", type=Path, required=True)
    parser.add_argument("--control-manifest", type=Path, required=True)
    parser.add_argument("--treatment-manifest", type=Path, required=True)
    parser.add_argument("--port", type=int, required=True)
    parser.add_argument("--head", required=True)
    parser.add_argument("--evidence", type=Path, required=True)
    parser.add_argument("--model-sha256", required=True)
    args = parser.parse_args()
    root_repo = args.repo.resolve()
    args.evidence.mkdir(parents=True, exist_ok=True)
    control = next(p["template"] for p in json.loads(args.control_manifest.read_text())["prompts"] if p["id"] == "test-driven-development")
    treatment = next(p["template"] for p in json.loads(args.treatment_manifest.read_text())["prompts"] if p["id"] == "test-driven-development")
    if control == treatment or "PRE-GREEN ORACLE CHALLENGE" not in treatment:
        raise RuntimeError("Control and treatment not valid independent recipes")
    with tempfile.TemporaryDirectory(prefix="tdd-live-ab-") as tmp:
        temp = Path(tmp)
        (temp / "canary").mkdir()
        canary = agent(temp / "canary", temp, args.port, CANARY)
        capable = canary["tool_calls"] > 0 and canary["process_exit"] == 0
        control_result = arm(root_repo, temp, "control", control, args.port) if capable else None
        treatment_result = arm(root_repo, temp, "treatment", treatment, args.port) if capable else None
        for name in ("control", "treatment"):
            evidence_dir = temp / "evidence" / name
            if evidence_dir.exists():
                shutil.copytree(evidence_dir, args.evidence / name, dirs_exist_ok=True)
    record = {
        "schema_version": "1.0",
        "issue": 890,
        "head_sha": args.head,
        "runtime": "Codex CLI 0.157.0 + llama.cpp b11904",
        "model": "Qwen2.5-Coder-3B-Instruct-GGUF Q4_K_M",
        "model_sha256": args.model_sha256,
        "control_recall_sha": RECALL_SHA_CONTROL,
        "treatment_recall_sha": RECALL_SHA_TREATMENT,
        "control_recipe_sha256": sha(control.encode()),
        "treatment_recipe_sha256": sha(treatment.encode()),
        "experimental_order": ["canary", "control", "treatment"] if capable else ["canary"],
        "preflight": canary,
        "preflight_tool_use_observed": capable,
        "control": control_result,
        "treatment": treatment_result,
        "status": "OBSERVED" if capable else "TOOL_USE_BLOCKED",
        "comparison_claim": "INCONCLUSIVE_ONE_PAIR" if capable else "NOT_RUN",
        "limits": [
            "One ordered nonrandomized CPU model pair, not a causal result.",
            "Native Codex host only; Recall templates passed directly as instructions.",
            "No independent human/reviewer verdict collected.",
            "No model reasoning or sensitive runtime environment retained.",
        ],
    }
    (args.evidence / "receipt.json").write_text(json.dumps(record, indent=2, sort_keys=True) + "\n")
    print(json.dumps({
        "status": record["status"],
        "preflight_tool_use_observed": capable,
        "control_grade": control_result["grading"]["status"] if control_result else None,
        "treatment_grade": treatment_result["grading"]["status"] if treatment_result else None,
    }))


if __name__ == "__main__":
    main()
