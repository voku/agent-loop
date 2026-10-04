#!/usr/bin/env python3
"""Analyze deterministic Claude Code runtime-proof requests.

Evidence is classified by stable prompt sentinels, never by global request number.
The analyzer fails closed when discovery or invocation/selection cannot be observed.
"""

from __future__ import annotations

import argparse
import glob
import json
from pathlib import Path
from typing import Any, Iterable


def strings(value: Any) -> Iterable[str]:
    if isinstance(value, str):
        yield value
    elif isinstance(value, list):
        for item in value:
            yield from strings(item)
    elif isinstance(value, dict):
        for item in value.values():
            yield from strings(item)


def observed_text(payload: dict[str, Any]) -> str:
    return "\n".join(strings(payload))


def request_records(evidence_root: Path) -> list[tuple[Path, dict[str, Any], str]]:
    records = []
    for raw_path in glob.glob(str(evidence_root / "provider-request-*.json")):
        path = Path(raw_path)
        with path.open("r", encoding="utf-8") as handle:
            payload = json.load(handle)
        if not isinstance(payload, dict):
            raise SystemExit(f"{path.name}: provider request must be an object")
        records.append((path, payload, observed_text(payload)))

    def request_number(record: tuple[Path, dict[str, Any], str]) -> int:
        stem = record[0].stem
        return int(stem.removeprefix("provider-request-"))

    return sorted(records, key=request_number)


def exactly_one(records: list[tuple[Path, dict[str, Any], str]], marker: str) -> tuple[Path, dict[str, Any], str]:
    matches = [record for record in records if marker in record[2]]
    if len(matches) != 1:
        raise SystemExit(f"{marker}: expected exactly one provider request, observed {len(matches)}")
    return matches[0]


def phase_requests(
    records: list[tuple[Path, dict[str, Any], str]],
    marker: str,
) -> list[tuple[Path, dict[str, Any], str]]:
    matches = [record for record in records if marker in record[2]]
    if not matches:
        raise SystemExit(f"{marker}: expected provider requests, observed none")
    return matches


def analyze(evidence_root: Path) -> dict[str, Any]:
    records = request_records(evidence_root)
    observations: dict[str, Any] = {
        "request_count": len(records),
        "instruction_consumption": {},
        "skill_runtime": {},
        "subagent_runtime": {},
    }

    for phase in ("before", "after"):
        instruction_marker = f"RUNTIME_PROOF_INSTRUCTION_{phase.upper()}"
        instruction = exactly_one(records, instruction_marker)
        router_count = instruction[2].count("## agent-loop workflow router")
        if router_count != 1:
            raise SystemExit(
                f"{phase}: managed router expected exactly once, observed {router_count}"
            )
        observations["instruction_consumption"][phase] = {
            "managed_router_count": router_count,
            "request_file": instruction[0].name,
        }

        skill_marker = f"RUNTIME_PROOF_SKILL_{phase.upper()}"
        skill_requests = phase_requests(records, skill_marker)
        discovery = skill_requests[0]
        if "- agent-loop-discipline:" not in discovery[2]:
            raise SystemExit(
                f"{phase}: projected agent-loop-discipline skill was not advertised to Claude"
            )
        invoked = [
            record
            for record in skill_requests[1:]
            if "# Agent Loop Discipline" in record[2]
            and "persisted workflow state beats conversational state" in record[2]
        ]
        if len(invoked) != 1:
            raise SystemExit(
                f"{phase}: expected exactly one follow-up request with loaded agent-loop-discipline content, observed {len(invoked)}"
            )
        observations["skill_runtime"][phase] = {
            "discovery_request": discovery[0].name,
            "invocation_request": invoked[0][0].name,
            "skill": "agent-loop-discipline",
            "status": "observed",
        }

        parent_marker = f"RUNTIME_PROOF_SUBAGENT_PARENT_{phase.upper()}"
        child_marker = f"RUNTIME_PROOF_SUBAGENT_CHILD_{phase.upper()}"
        parent_requests = phase_requests(records, parent_marker)
        discovery = parent_requests[0]
        if "- agent-loop-investigator:" not in discovery[2]:
            raise SystemExit(
                f"{phase}: projected agent-loop-investigator subagent was not advertised to Claude"
            )

        child_requests = phase_requests(records, child_marker)
        selected = [
            record
            for record in child_requests
            if "Locate. Verify. Report. Stop." in record[2]
            and "Map output is navigation only." in record[2]
            and "Read-only. Do not edit, design, or propose a fix." in record[2]
        ]
        if len(selected) != 1:
            raise SystemExit(
                f"{phase}: expected exactly one child request with selected agent-loop-investigator instructions, observed {len(selected)}"
            )
        observations["subagent_runtime"][phase] = {
            "discovery_request": discovery[0].name,
            "selection_request": selected[0][0].name,
            "subagent": "agent-loop-investigator",
            "status": "observed",
        }

    if observations["instruction_consumption"]["before"]["managed_router_count"] != observations["instruction_consumption"]["after"]["managed_router_count"]:
        raise SystemExit("managed router count changed across reinstall")

    return observations


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--evidence-root", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()

    observations = analyze(args.evidence_root)
    args.output.write_text(
        json.dumps(observations, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )


if __name__ == "__main__":
    main()
