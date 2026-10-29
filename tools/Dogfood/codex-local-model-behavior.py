#!/usr/bin/env python3
"""One isolated, genuine-model Codex experiment. Never claim outcome quality from transport."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time


TASK = (
    "A PHP regression treats numeric user ID 0 as missing and returns the "
    "fallback ID instead. Investigate and fix the underlying bug. "
    "Use the supplied PHP regression test to check your work. "
    "Do not publish or push anything."
)

PHP_SOURCE = """<?php
declare(strict_types=1);

function effectiveUserId(?int $userId): int
{
    return $userId ?: 99;
}
"""

PHP_TEST = """<?php
declare(strict_types=1);
require __DIR__ . '/src/user_id.php';

if (effectiveUserId(0) !== 0 ||
    effectiveUserId(null) !== 99 ||
    effectiveUserId(3) !== 3) {
    fwrite(STDERR, "ID fallback regression\\n");
    exit(1);
}

echo "PASS\\n";
"""


def sha(path: Path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def run(argv, directory, *, timeout=120, env=None):
    return subprocess.run(
        argv, cwd=directory, env=env, check=False, capture_output=True,
        text=True, timeout=timeout,
    )


def prepare_consumer(repository: Path, destination: Path):
    destination.mkdir()
    package = {
        "name": "voku/harness-live-model-proof",
        "type": "project",
        "repositories": [{
            "type": "path", "url": str(repository),
            "options": {"symlink": False, "versions": {"voku/agent-loop": "dev-proof"}},
        }],
        "require": {"voku/agent-loop": "dev-proof"},
        "minimum-stability": "dev",
        "config": {"allow-plugins": False},
    }
    (destination / "composer.json").write_text(
        json.dumps(package, indent=2) + "\n", encoding="utf-8"
    )
    install = run(
        ["composer", "install", "--no-interaction", "--prefer-dist", "--no-progress"],
        destination, timeout=300,
    )
    if install.returncode:
        raise RuntimeError("installed consumer composer install failed (see CI step)")
    subprocess.run(
        ["git", "init", "--quiet", "--initial-branch=main", str(destination)],
        check=True,
    )
    for arguments in (
        ["init", "scaffold", "--demo", "--agent=codex"],
        ["init", "install-assets", "--agent=codex"],
        ["init", "sync-policy", "--agent=codex"],
    ):
        proc = run([str(destination / "vendor/bin/agent-loop"), *arguments],
                   destination)
        if proc.returncode:
            raise RuntimeError(f"project setup failed: {arguments}")
    (destination / "src").mkdir(exist_ok=True)
    (destination / "src/user_id.php").write_text(PHP_SOURCE, encoding="utf-8")
    (destination / "test.php").write_text(PHP_TEST, encoding="utf-8")
    if run(["php", "test.php"], destination).returncode == 0:
        raise RuntimeError("invalid oracle: the known-bad fixture unexpectedly passes")
    source_file = destination / "src/user_id.php"
    source_file.write_text(
        PHP_SOURCE.replace("return $userId ?: 99;", "return $userId ?? 99;"),
        encoding="utf-8",
    )
    try:
        if run(["php", "test.php"], destination).returncode != 0:
            raise RuntimeError("invalid oracle: the known-good correction must pass")
    finally:
        source_file.write_text(PHP_SOURCE, encoding="utf-8")
    if not (destination / "AGENTS.md").is_file():
        raise RuntimeError("agent-loop did not project the managed router")
    if not (destination / ".codex/rules/agent-loop.rules").is_file():
        raise RuntimeError("missing managed hard shell policy")


def run_arm(consumer: Path, experiment: Path, name: str, model_port: int):
    project = experiment / (name + "-project")
    shutil.copytree(consumer, project, ignore=shutil.ignore_patterns(".git", ".agent-loop"))
    if name == "control":
        (project / "AGENTS.md").unlink()
    subprocess.run(
        ["git", "init", "--quiet", "--initial-branch=main", str(project)], check=True
    )
    runtime_home = experiment / (name + "-home")
    runtime_home.mkdir()
    (runtime_home / "config.toml").write_text(
        '[projects.' + json.dumps(str(project)) + ']\ntrust_level = "trusted"\n',
        encoding="utf-8",
    )
    env = {
        key: os.environ[key]
        for key in ("PATH", "HOME", "LANG", "LC_ALL", "TMPDIR", "LD_LIBRARY_PATH")
        if key in os.environ
    }
    env["CODEX_HOME"] = str(runtime_home)
    provider = (
        '{ name = "local", base_url = "http://127.0.0.1:'
        + str(model_port)
        + '/v1", env_key = "PATH", wire_api = "responses" }'
    )

    before = run(["php", "test.php"], project).returncode
    start = time.monotonic()
    command = [
        "codex", "exec", "--ephemeral", "--json",
        "-c", 'model="local-coder"',
        "-c", 'model_provider="local"',
        "-c", "model_providers.local=" + provider,
        "-c", 'sandbox_mode="workspace-write"',
        "-c", 'approval_policy="never"',
        TASK,
    ]
    try:
        result = run(command, project, timeout=240, env=env)
        timed_out = False
    except subprocess.TimeoutExpired:
        result = subprocess.CompletedProcess(command, 124, stdout="", stderr="")
        timed_out = True
    elapsed = round(time.monotonic() - start, 3)
    after = run(["php", "test.php"], project).returncode

    commands = []
    message_count = 0
    for line in result.stdout.splitlines():
        try:
            event = json.loads(line)
        except ValueError:
            continue
        item = event.get("item")
        if isinstance(item, dict):
            if item.get("type") == "command_execution":
                command = item.get("command", "")
                commands.append({
                    "enter": "agent-loop enter " in command,
                    "finish": "agent-loop finish " in command,
                    "php_test": "php test.php" in command,
                })
            elif item.get("type") == "agent_message" and event.get("type") == "item.completed":
                message_count += 1

    source = project / "src/user_id.php"
    source_changed = not source.exists() or (
        sha(source) != hashlib.sha256(PHP_SOURCE.encode()).hexdigest()
    )
    return {
        "arm": name,
        "codex_process_succeeded": result.returncode == 0,
        "timed_out": timed_out,
        "model_behavior_observed": message_count > 0 or len(commands) > 0,
        "codex_exit_code": result.returncode,
        "failure_diagnostic": (
            result.stderr[-650:].replace(str(project), "<isolated-project>")
            .replace(str(runtime_home), "<isolated-home>")
            if result.returncode else None
        ),
        "baseline_oracle_failed": before != 0,
        "final_oracle_passed": after == 0,
        "source_changed": source_changed,
        "router_present": (project / "AGENTS.md").exists(),
        "hard_policy_sha256": sha(project / ".codex/rules/agent-loop.rules"),
        "command_count": len(commands),
        "enter_attempts": sum(int(c["enter"]) for c in commands),
        "finish_attempts": sum(int(c["finish"]) for c in commands),
        "php_test_attempts": sum(int(c["php_test"]) for c in commands),
        "completed_agent_messages": message_count,
        "elapsed_seconds": elapsed,
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--repository", type=Path, required=True)
    parser.add_argument("--evidence", type=Path, required=True)
    parser.add_argument("--revision", required=True)
    parser.add_argument("--model-port", type=int, required=True)
    parser.add_argument("--model-sha256", required=True)
    parser.add_argument("--llama-sha256", required=True)
    args = parser.parse_args()

    with tempfile.TemporaryDirectory(prefix="harness-local-model-") as location:
        root = Path(location)
        consumer = root / "consumer"
        prepare_consumer(args.repository.resolve(), consumer)
        control = run_arm(consumer, root, "control", args.model_port)
        treatment = run_arm(consumer, root, "treatment", args.model_port)

    parity = control["hard_policy_sha256"] == treatment["hard_policy_sha256"]
    observable = (
        control["codex_process_succeeded"]
        and treatment["codex_process_succeeded"]
        and control["model_behavior_observed"]
        and treatment["model_behavior_observed"]
    )
    receipt = {
        "schema_version": 1,
        "evidence_class": "local_model_host_behavior_probe",
        "repository_head_sha": args.revision,
        "runtime": "Codex 0.157.0",
        "model": "Qwen2.5-Coder-0.5B-Instruct-GGUF Q4_K_M",
        "model_sha256": args.model_sha256,
        "llama_release_asset_sha256": args.llama_sha256,
        "prompt": TASK,
        "experimental_order": ["control", "treatment"],
        "arms": {"control": control, "treatment": treatment},
        "common_hard_policy": parity,
        "status": "observed" if observable and parity else "inconclusive",
        "boundary": (
            "Local 0.5B model through native Codex CLI in independent fresh projects; "
            "this is one ordered pair, not an unbiased crossover or generalizable "
            "engineering benchmark. Source/test changes do not imply approved Loop "
            "Contract or valid completion. No hidden reasoning or full prompt logs "
            "are stored; a failed oracle is a failed outcome, not a test-run failure."
        ),
        "token_cost": "unknown",
    }
    args.evidence.mkdir(parents=True, exist_ok=True)
    path = args.evidence / "local-model-behavior.json"
    path.write_text(json.dumps(receipt, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps({
        "status": receipt["status"],
        "oracle_control": control["final_oracle_passed"],
        "oracle_treatment": treatment["final_oracle_passed"],
        "control_commands": control["command_count"],
        "treatment_commands": treatment["command_count"],
    }))
    if not parity:
        raise SystemExit("safety-floor mismatch between model arms")


if __name__ == "__main__":
    main()
