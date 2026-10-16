#!/usr/bin/env python3
"""Prove explicit diagnostic tool-output consumption by an installed Codex CLI.

Disposable E0 transport experiment. A deterministic local fake model issues the
command; no real language model chooses a tool and no task-quality claim follows.
The repository's governed validation, workflow state and provider credentials
are never touched.
"""

from __future__ import annotations

import hashlib
import http.server
import json
import os
from pathlib import Path
import subprocess
import tempfile
import threading
from typing import Any


def digest(data: bytes) -> str:
    return "sha256:" + hashlib.sha256(data).hexdigest()


def check(command: list[str], *, cwd: Path, env: dict[str, str] | None = None) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(command, cwd=cwd, env=env, capture_output=True, text=True, timeout=90, check=False)
    if result.returncode != 0:
        raise RuntimeError(f"Command failed ({result.returncode}): {command[0]}: {result.stderr[-1200:]}")
    return result


def source_fixture(repo: Path, workspace: Path) -> dict[str, Any]:
    fixture = workspace / "deliberately-broken.php"
    fixture.write_text(
        "<?php\n"
        "declare(strict_types=1);\n"
        "function deliberatelyBroken(): int\n"
        "{\n"
        "    return 'wrong type';\n"
        "}\n",
        encoding="utf-8",
    )
    command = [
        str(repo / "vendor/bin/phpstan"),
        "analyse",
        "--no-progress",
        "--error-format=raw",
        "--level=8",
        "--memory-limit=512M",
        str(fixture),
    ]
    observed = subprocess.run(command, cwd=repo, capture_output=True, text=True, timeout=90, check=False)
    source = (observed.stdout + observed.stderr).encode("utf-8")
    if observed.returncode == 0 or b"should return int" not in source:
        raise RuntimeError(
            "The intentionally invalid PHPStan fixture did not yield the expected diagnostic: "
            + source.decode("utf-8", errors="replace")[-1200:]
        )
    original = workspace / "diagnostic.log"
    original.write_bytes(source)
    return {
        "producer": "PHPStan --error-format=raw, separate non-authoritative diagnostic run",
        "producer_exit_code": observed.returncode,
        "source_sha256": digest(source),
        "source_bytes": len(source),
        "source_file": fixture.name,
        "required_marker": "should return int",
    }


def make_handler(command: str, call_id: str, captured: list[dict[str, Any]]) -> type[http.server.BaseHTTPRequestHandler]:
    class Handler(http.server.BaseHTTPRequestHandler):
        requests = 0

        def log_message(self, _format: str, *_args: Any) -> None:
            pass

        def do_POST(self) -> None:
            length = int(self.headers.get("Content-Length", "0"))
            if length > 2_000_000:
                self.send_error(413)
                return
            payload = json.loads(self.rfile.read(length))
            captured.append(payload)
            Handler.requests += 1
            response_id = f"resp-diagnostic-{Handler.requests}"
            events: list[dict[str, Any]] = [
                {"type": "response.created", "response": {"id": response_id}},
            ]
            if Handler.requests == 1:
                events.append({
                    "type": "response.output_item.done",
                    "item": {
                        "type": "function_call",
                        "call_id": call_id,
                        "name": "exec_command",
                        "arguments": json.dumps({"cmd": command}),
                    },
                })
            events.append({
                "type": "response.completed",
                "response": {
                    "id": response_id,
                    "usage": {
                        "input_tokens": 0,
                        "input_tokens_details": None,
                        "output_tokens": 0,
                        "output_tokens_details": None,
                        "total_tokens": 0,
                    },
                },
            })
            body = "".join(
                f"event: {event['type']}\ndata: {json.dumps(event)}\n\n" for event in events
            ).encode("utf-8")
            self.send_response(200)
            self.send_header("Content-Type", "text/event-stream")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

    return Handler


def run_codex_arm(workspace: Path, arm: str, command: str, source_marker: str) -> dict[str, Any]:
    call_id = f"call-diagnostic-{arm}"
    captured: list[dict[str, Any]] = []
    server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), make_handler(command, call_id, captured))
    server.timeout = 5
    worker = threading.Thread(target=server.serve_forever, daemon=True)
    worker.start()
    try:
        home = workspace / f"codex-home-{arm}"
        home.mkdir()
        (home / "config.toml").write_text(
            f'[projects."{workspace}"]\ntrust_level = "trusted"\n',
            encoding="utf-8",
        )
        env = dict(os.environ)
        for key in ("OPENAI_API_KEY", "CODEX_ACCESS_TOKEN", "ANTHROPIC_API_KEY"):
            env.pop(key, None)
        env["CODEX_HOME"] = str(home)
        result = check([
            "codex", "exec", "--ephemeral", "--json",
            "-c", 'model="proof-model"',
            "-c", 'model_provider="mock"',
            "-c", (
                'model_providers.mock={ name = "mock", '
                f'base_url = "http://127.0.0.1:{server.server_port}/v1", '
                'env_key = "PATH", wire_api = "responses" }'
            ),
            "Execute the diagnostic read command requested by the provider.",
        ], cwd=workspace, env=env)
    finally:
        server.shutdown()
        server.server_close()
        worker.join(timeout=5)

    responses = [
        item
        for request in captured
        for item in request.get("input", [])
        if isinstance(item, dict)
        and item.get("type") == "function_call_output"
        and item.get("call_id") == call_id
    ]
    if len(responses) != 1:
        raise RuntimeError(f"{arm}: expected exactly one observed exec_command output; got {len(responses)}")
    output = json.dumps(responses[0], sort_keys=True, ensure_ascii=False)
    if source_marker not in output:
        raise RuntimeError(
            f"{arm}: PHPStan diagnostic marker missing from actual Codex tool output; "
            f"observed envelope tail={output[-1100:]!r}"
        )
    return {
        "command": command,
        "host": check(["codex", "--version"], cwd=workspace).stdout.strip(),
        "host_tool_output_observed": True,
        "mock_provider_requests": len(captured),
        "tool_output_envelope_sha256": digest(output.encode("utf-8")),
        "codex_event_stream_sha256": digest(result.stdout.encode("utf-8")),
        "host_proof_type": "real_codex_cli_with_scripted_local_mock_provider",
    }


def main() -> None:
    repo = Path(os.environ.get("GITHUB_WORKSPACE", ".")).resolve()
    report_path = repo / "build/context-selection-host-proof.json"
    report_path.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="diagnostic-host-proof-") as temp:
        workspace = Path(temp)
        check(["git", "init", "--initial-branch=main"], cwd=workspace)
        observed = source_fixture(repo, workspace)
        raw = run_codex_arm(workspace, "raw", "cat diagnostic.log", observed["required_marker"])
        native = run_codex_arm(
            workspace, "opt-in", "rg -n -F 'should return int' diagnostic.log",
            observed["required_marker"],
        )
        report = {
            "schema_version": "1.0",
            "repository_head_sha": os.environ.get("PROOF_HEAD_SHA") or os.environ.get("GITHUB_SHA", "local"),
            "dependency_graph_sha256": digest((repo / "composer.lock").read_bytes()),
            "classification": "OPTIONAL_HUMAN_DIAGNOSTIC",
            "source": observed,
            "raw": raw,
            "explicit_native_opt_in": native,
            "governed_validation_untouched": True,
            "completion_envelope_untouched": True,
            "does_not_prove": [
                "autonomous_model_tool_selection",
                "query_aware_trim_1_quality",
                "token_or_task_success_savings",
                "safe_generic_host_tool_interception",
            ],
        }
    report_path.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(f"CODEX_DIAGNOSTIC_OPT_IN_OBSERVED: {report_path.relative_to(repo)}")
    print(f"source={report['source']['source_sha256']} host={raw['host']}")
    print("Both a raw diagnostic read and an explicit native filter were consumed by installed Codex.")


if __name__ == "__main__":
    main()
