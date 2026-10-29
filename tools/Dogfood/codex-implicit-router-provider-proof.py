#!/usr/bin/env python3
"""Observe Codex request transport; do not infer model compliance or task quality."""

import argparse
import hashlib
import http.server
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import threading


ROUTER_MARKER = "## agent-loop workflow router"
TASK_PROMPT = (
    "Investigate how a PHP project should identify the owner of a potential "
    "null-handling bug. Report what evidence you would inspect first. "
    "Do not change files."
)


def digest(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def all_strings(value):
    if isinstance(value, str):
        yield value
    elif isinstance(value, list):
        for item in value:
            yield from all_strings(item)
    elif isinstance(value, dict):
        for item in value.values():
            yield from all_strings(item)


class RequestCollector:
    def __init__(self):
        self.requests = []
        collector = self

        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass

            def do_POST(self):
                length = int(self.headers.get("Content-Length", "0"))
                payload = json.loads(self.rfile.read(length))
                collector.requests.append(payload)

                response_id = f"resp-implicit-router-{len(collector.requests)}"
                events = [
                    {"type": "response.created", "response": {"id": response_id}},
                    {
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
                    },
                ]
                body = "".join(
                    f"event: {event['type']}\ndata: {json.dumps(event)}\n\n"
                    for event in events
                ).encode()
                self.send_response(200)
                self.send_header("Content-Type", "text/event-stream")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

        self.server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)

    def __enter__(self):
        self.thread.start()
        return self

    def __exit__(self, *_args):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(timeout=5)

    @property
    def port(self):
        return self.server.server_address[1]


def run_arm(source: Path, scratch: Path, arm: str):
    project = scratch / f"{arm}-project"
    home = scratch / f"{arm}-codex-home"
    shutil.copytree(
        source,
        project,
        ignore=shutil.ignore_patterns(".git", ".agent-loop", "vendor"),
    )
    if arm == "baseline":
        (project / "AGENTS.md").unlink()
    home.mkdir()
    config = '[projects.' + json.dumps(str(project)) + ']\ntrust_level = "trusted"\n'
    (home / "config.toml").write_text(config, encoding="utf-8")
    subprocess.run(
        ["git", "init", "--initial-branch=main", "--quiet", str(project)],
        check=True,
        capture_output=True,
        text=True,
    )

    env = os.environ.copy()
    env.pop("OPENAI_API_KEY", None)
    env.pop("CODEX_ACCESS_TOKEN", None)
    env["CODEX_HOME"] = str(home)
    with RequestCollector() as collector:
        provider = (
            '{ name = "mock", base_url = "http://127.0.0.1:'
            + str(collector.port)
            + '/v1", env_key = "PATH", wire_api = "responses" }'
        )
        proc = subprocess.run(
            [
                "codex", "exec", "--ephemeral", "--json",
                "-c", 'model="proof-model"',
                "-c", 'model_provider="mock"',
                "-c", "model_providers.mock=" + provider,
                TASK_PROMPT,
            ],
            cwd=project,
            env=env,
            text=True,
            capture_output=True,
            timeout=75,
            check=False,
        )

    request_count = len(collector.requests)
    marker_count = sum(
        "\n".join(all_strings(payload)).count(ROUTER_MARKER)
        for payload in collector.requests
    )
    policy = project / ".codex" / "rules" / "agent-loop.rules"
    if not policy.is_file():
        raise RuntimeError(f"{arm}: projected hard policy missing")
    return {
        "arm": arm,
        "codex_exit_code": proc.returncode,
        "provider_request_count": request_count,
        "router_marker_count": marker_count,
        "managed_policy_sha256": digest(policy),
        "managed_router_sha256": digest(project / "AGENTS.md")
        if arm == "treatment" else None,
        "stderr_excerpt_if_failed": proc.stderr[-1500:] if proc.returncode else None,
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--source-root", type=Path, required=True)
    parser.add_argument("--evidence-root", type=Path, required=True)
    parser.add_argument("--repository-head", required=True)
    parser.add_argument("--codex-version", required=True)
    args = parser.parse_args()

    if not (args.source_root / "AGENTS.md").is_file():
        raise SystemExit("Managed AGENTS.md was not projected")
    if ROUTER_MARKER not in (args.source_root / "AGENTS.md").read_text(
        encoding="utf-8"
    ):
        raise SystemExit("Source router marker missing")

    with tempfile.TemporaryDirectory(prefix="codex-implicit-router-") as temporary:
        scratch = Path(temporary)
        baseline = run_arm(args.source_root, scratch, "baseline")
        treatment = run_arm(args.source_root, scratch, "treatment")

    valid = (
        baseline["codex_exit_code"] == 0
        and treatment["codex_exit_code"] == 0
        and baseline["provider_request_count"] == 1
        and treatment["provider_request_count"] == 1
        and baseline["router_marker_count"] == 0
        and treatment["router_marker_count"] == 1
        and baseline["managed_policy_sha256"] == treatment["managed_policy_sha256"]
        and treatment["managed_router_sha256"] == digest(args.source_root / "AGENTS.md")
    )
    receipt = {
        "evidence_class": "host_runtime_provider_request",
        "status": "observed" if valid else "refuted",
        "repository_head": args.repository_head,
        "host": args.codex_version,
        "task_prompt": TASK_PROMPT,
        "arms": {"baseline": baseline, "treatment": treatment},
        "comparison": "Same pinned host, task, projected assets and hard shell policy; "
        "only root managed AGENTS.md router was removed from baseline.",
        "boundary": "Observes managed router in ordinary Codex provider request "
        "without explicit skill selection. The local provider returns a fixed "
        "empty completion: no inference about model reasoning, workflow command "
        "choice, engineering correctness or developer attention is permitted.",
        "runtime_token_cost": "unknown (local scripted provider usage is not real cost)",
    }
    args.evidence_root.mkdir(parents=True, exist_ok=True)
    output = args.evidence_root / "implicit-router-provider-proof.json"
    output.write_text(json.dumps(receipt, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps({"status": receipt["status"], "receipt": str(output)}))
    if not valid:
        raise SystemExit("Codex implicit router provider transport was not established")


if __name__ == "__main__":
    main()
