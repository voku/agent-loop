#!/usr/bin/env python3
"""Deterministic Anthropic-compatible provider for Claude Code runtime proofs.

The provider is intentionally scenario-driven instead of request-index-driven:
proof prompts carry stable RUNTIME_PROOF_* sentinels, so adding a new scenario
cannot silently renumber or invalidate existing evidence.
"""

from __future__ import annotations

import argparse
import json
import os
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any, Iterable
from urllib.parse import urlparse


MODEL_ID = "claude-sonnet-4-5"


def strings(value: Any) -> Iterable[str]:
    if isinstance(value, str):
        yield value
    elif isinstance(value, list):
        for item in value:
            yield from strings(item)
    elif isinstance(value, dict):
        for item in value.values():
            yield from strings(item)


def has_block_type(value: Any, block_type: str) -> bool:
    if isinstance(value, dict):
        if value.get("type") == block_type:
            return True
        return any(has_block_type(item, block_type) for item in value.values())
    if isinstance(value, list):
        return any(has_block_type(item, block_type) for item in value)
    return False


class Provider:
    def __init__(self, evidence_root: Path, proof_root: Path) -> None:
        self.evidence_root = evidence_root
        self.proof_root = proof_root
        self.counter_path = evidence_root / "provider-request-counter.txt"

    def next_request_index(self) -> int:
        self.counter_path.touch(exist_ok=True)
        with self.counter_path.open("r+", encoding="utf-8") as handle:
            import fcntl

            fcntl.flock(handle.fileno(), fcntl.LOCK_EX)
            raw = handle.read().strip()
            index = int(raw) + 1 if raw else 1
            handle.seek(0)
            handle.truncate()
            handle.write(f"{index}\n")
            handle.flush()
            fcntl.flock(handle.fileno(), fcntl.LOCK_UN)
            return index

    def record_request(self, payload: dict[str, Any]) -> int:
        index = self.next_request_index()
        path = self.evidence_root / f"provider-request-{index}.json"
        path.write_text(
            json.dumps(payload, indent=2, sort_keys=True) + "\n",
            encoding="utf-8",
        )
        return index

    def response_for(self, payload: dict[str, Any], request_index: int) -> tuple[str, dict[str, Any] | str]:
        observed = "\n".join(strings(payload))

        permission_scenarios = {
            "RUNTIME_PROOF_PERMISSION_DENY_GIT_PUSH": "git push origin main",
            "RUNTIME_PROOF_PERMISSION_DENY_GH_PR_CREATE": "gh pr create --title proof --body proof",
            "RUNTIME_PROOF_PERMISSION_DENY_GH_PR_MERGE": "gh pr merge 1 --squash",
        }
        for marker, tool_command in permission_scenarios.items():
            if marker in observed:
                if has_block_type(payload.get("messages", []), "tool_result"):
                    return "text", "PROOF_OK"
                (self.evidence_root / f"requested-{marker.lower()}.txt").write_text(
                    tool_command + "\n",
                    encoding="utf-8",
                )
                return "tool", {
                    "id": f"toolu_permission_{request_index}",
                    "name": "Bash",
                    "input": {"command": tool_command},
                }

        if "RUNTIME_PROOF_PRETOOL_DENY" in observed:
            if has_block_type(payload.get("messages", []), "tool_result"):
                return "text", "PROOF_OK"
            tool_command = str(self.proof_root / "proof-bin" / "git") + " -C . push origin main"
            (self.evidence_root / "requested-alternate-publication-command.txt").write_text(
                tool_command + "\n",
                encoding="utf-8",
            )
            return "tool", {
                "id": f"toolu_pretool_{request_index}",
                "name": "Bash",
                "input": {"command": tool_command},
            }

        if (
            "RUNTIME_PROOF_SUBAGENT_CHILD_" in observed
            and "RUNTIME_PROOF_SUBAGENT_PARENT_" not in observed
        ):
            return "text", "SUBAGENT_PROOF_OK"

        if "RUNTIME_PROOF_SUBAGENT_PARENT_" in observed:
            if has_block_type(payload.get("messages", []), "tool_result"):
                return "text", "PROOF_OK"
            phase = "before" if "RUNTIME_PROOF_SUBAGENT_PARENT_BEFORE" in observed else "after"
            return "tool", {
                "id": f"toolu_agent_{phase}_{request_index}",
                "name": "Agent",
                "input": {
                    "description": "Runtime proof investigator",
                    "prompt": (
                        f"RUNTIME_PROOF_SUBAGENT_CHILD_{phase.upper()} "
                        "Return exactly SUBAGENT_PROOF_OK and do not use tools."
                    ),
                    "run_in_background": False,
                    "subagent_type": "agent-loop-investigator",
                },
            }

        if "RUNTIME_PROOF_SKILL_" in observed:
            if has_block_type(payload.get("messages", []), "tool_result"):
                return "text", "PROOF_OK"
            phase = "before" if "RUNTIME_PROOF_SKILL_BEFORE" in observed else "after"
            return "tool", {
                "id": f"toolu_skill_{phase}_{request_index}",
                "name": "Skill",
                "input": {"skill": "agent-loop-discipline"},
            }

        return "text", "PROOF_OK"


class Handler(BaseHTTPRequestHandler):
    provider: Provider

    def log_message(self, format: str, *args: Any) -> None:
        return

    def _record_path(self, method: str) -> None:
        with (self.provider.evidence_root / "provider-paths.log").open(
            "a", encoding="utf-8"
        ) as handle:
            handle.write(f"{method} {self.path}\n")

    def _json(self, status: int, payload: dict[str, Any]) -> None:
        body = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self) -> None:
        self._record_path("GET")
        path = urlparse(self.path).path
        if path.rstrip("/") == "/v1/models":
            self._json(
                200,
                {
                    "data": [
                        {
                            "id": MODEL_ID,
                            "type": "model",
                            "display_name": "proof-model",
                            "created_at": "2026-01-01T00:00:00Z",
                        }
                    ],
                    "has_more": False,
                    "first_id": MODEL_ID,
                    "last_id": MODEL_ID,
                },
            )
            return
        if path.startswith("/v1/models/"):
            model_id = path.removeprefix("/v1/models/")
            self._json(
                200,
                {
                    "id": model_id,
                    "type": "model",
                    "display_name": "proof-model",
                    "created_at": "2026-01-01T00:00:00Z",
                },
            )
            return
        self._json(
            404,
            {"error": {"type": "not_found_error", "message": self.path}},
        )

    def do_POST(self) -> None:
        self._record_path("POST")
        path = urlparse(self.path).path
        length = int(self.headers.get("Content-Length", "0"))
        raw = self.rfile.read(length)
        try:
            payload = json.loads(raw.decode("utf-8"))
        except json.JSONDecodeError:
            self._json(
                400,
                {"error": {"type": "invalid_request_error", "message": "invalid json"}},
            )
            return

        if path.endswith("/count_tokens"):
            self._json(200, {"input_tokens": 1})
            return

        if not path.endswith("/messages"):
            self._json(
                404,
                {"error": {"type": "not_found_error", "message": self.path}},
            )
            return

        if not isinstance(payload, dict):
            self._json(
                400,
                {"error": {"type": "invalid_request_error", "message": "object required"}},
            )
            return

        request_index = self.provider.record_request(payload)
        response_kind, response = self.provider.response_for(payload, request_index)

        if payload.get("stream"):
            if response_kind == "tool":
                assert isinstance(response, dict)
                events = [
                    (
                        "message_start",
                        {
                            "type": "message_start",
                            "message": {
                                "id": f"msg_proof_{request_index}",
                                "type": "message",
                                "role": "assistant",
                                "model": MODEL_ID,
                                "content": [],
                                "stop_reason": None,
                                "stop_sequence": None,
                                "usage": {"input_tokens": 1, "output_tokens": 0},
                            },
                        },
                    ),
                    (
                        "content_block_start",
                        {
                            "type": "content_block_start",
                            "index": 0,
                            "content_block": {
                                "type": "tool_use",
                                "id": response["id"],
                                "name": response["name"],
                                "input": {},
                            },
                        },
                    ),
                    (
                        "content_block_delta",
                        {
                            "type": "content_block_delta",
                            "index": 0,
                            "delta": {
                                "type": "input_json_delta",
                                "partial_json": json.dumps(response["input"]),
                            },
                        },
                    ),
                    ("content_block_stop", {"type": "content_block_stop", "index": 0}),
                    (
                        "message_delta",
                        {
                            "type": "message_delta",
                            "delta": {"stop_reason": "tool_use", "stop_sequence": None},
                            "usage": {"output_tokens": 1},
                        },
                    ),
                    ("message_stop", {"type": "message_stop"}),
                ]
            else:
                assert isinstance(response, str)
                events = [
                    (
                        "message_start",
                        {
                            "type": "message_start",
                            "message": {
                                "id": f"msg_proof_{request_index}",
                                "type": "message",
                                "role": "assistant",
                                "model": MODEL_ID,
                                "content": [],
                                "stop_reason": None,
                                "stop_sequence": None,
                                "usage": {"input_tokens": 1, "output_tokens": 0},
                            },
                        },
                    ),
                    (
                        "content_block_start",
                        {
                            "type": "content_block_start",
                            "index": 0,
                            "content_block": {"type": "text", "text": ""},
                        },
                    ),
                    (
                        "content_block_delta",
                        {
                            "type": "content_block_delta",
                            "index": 0,
                            "delta": {"type": "text_delta", "text": response},
                        },
                    ),
                    ("content_block_stop", {"type": "content_block_stop", "index": 0}),
                    (
                        "message_delta",
                        {
                            "type": "message_delta",
                            "delta": {"stop_reason": "end_turn", "stop_sequence": None},
                            "usage": {"output_tokens": 1},
                        },
                    ),
                    ("message_stop", {"type": "message_stop"}),
                ]

            body = "".join(
                f"event: {event}\ndata: {json.dumps(data)}\n\n"
                for event, data in events
            ).encode("utf-8")
            self.send_response(200)
            self.send_header("Content-Type", "text/event-stream")
            self.send_header("Cache-Control", "no-cache")
            self.send_header("Connection", "close")
            self.end_headers()
            self.wfile.write(body)
            self.wfile.flush()
            self.close_connection = True
            return

        if response_kind == "tool":
            assert isinstance(response, dict)
            self._json(
                200,
                {
                    "id": f"msg_proof_{request_index}",
                    "type": "message",
                    "role": "assistant",
                    "model": MODEL_ID,
                    "content": [
                        {
                            "type": "tool_use",
                            "id": response["id"],
                            "name": response["name"],
                            "input": response["input"],
                        }
                    ],
                    "stop_reason": "tool_use",
                    "usage": {"input_tokens": 1, "output_tokens": 1},
                },
            )
            return

        assert isinstance(response, str)
        self._json(
            200,
            {
                "id": f"msg_proof_{request_index}",
                "type": "message",
                "role": "assistant",
                "model": MODEL_ID,
                "content": [{"type": "text", "text": response}],
                "stop_reason": "end_turn",
                "usage": {"input_tokens": 1, "output_tokens": 1},
            },
        )


def serve(evidence_root: Path, proof_root: Path, port_path: Path) -> None:
    evidence_root.mkdir(parents=True, exist_ok=True)
    provider = Provider(evidence_root, proof_root)
    Handler.provider = provider
    server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
    port_path.write_text(f"{server.server_port}\n", encoding="utf-8")
    server.serve_forever()


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--evidence-root", type=Path, required=True)
    parser.add_argument("--proof-root", type=Path, required=True)
    parser.add_argument("--port-path", type=Path, required=True)
    args = parser.parse_args()
    serve(args.evidence_root, args.proof_root, args.port_path)


if __name__ == "__main__":
    main()
