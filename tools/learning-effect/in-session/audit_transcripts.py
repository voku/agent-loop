#!/usr/bin/env python3
"""Audit in-session agent transcripts for isolation and skill access.

usage: audit_transcripts.py <runmap.tsv>

runmap.tsv has one line per run: "<run-name>\t<transcript.jsonl>". The workspace is
expected at <runs-dir>/<run-name>/repo, where <runs-dir> is the directory that holds
runmap.tsv plus "/runs" (set RUNS_DIR to override).

For each run it prints one JSON object:
  network            tool calls that use the web tools, curl/wget, or git fetch/clone/pull/push/ls-remote (also after global options such as -C);
  forbidden_paths    paths outside the run's workspace that point at real repositories,
                     candidate lists, other runs or other transcripts (must be empty);
  other_outside      remaining paths outside the workspace (scratch logs etc.), counted;
  skill_access       every tool call that touches .claude/skills/, resources/skills/ or
                     uses the Skill tool (availability is NOT access; access is NOT use).

Reconstructed from the inline audit commands used for holdout H2 and the H2b pilot
(docs/dogfood/2026-11-20-learning-effect-h2-screening.md).
"""
import collections
import json
import os
import re
import sys
from pathlib import Path

runmap = Path(sys.argv[1]).resolve()
runs_dir = Path(os.environ.get("RUNS_DIR", str(runmap.parent / "runs")))
PATH_RE = re.compile(r'(/(?:home|tmp|root|etc|opt|var|dev/shm)[^"\s\\\';]*)')
# git may carry global options (-C <dir>, -c k=v, --git-dir=...) before the subcommand.
NET_RE = re.compile(r'\b(curl|wget)\b|\bgit(?:\s+(?:-C\s+\S+|-c\s+\S+|--[\w-]+(?:=\S+)?))*\s+(fetch|clone|pull|push|ls-remote)\b')
SKILL_RE = re.compile(r'(?:\.claude|resources)/skills/[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)?')
# /tasks/a<16 hex>.output are other agents' transcripts; /tasks/b<id>.output are the agent's
# own background-shell outputs and are not a leak.
FORBIDDEN_RE = re.compile(r'/home/user/(agent-|voku)|candidates|/exp[23]?/|/tasks/a[0-9a-f]{16}\.output')

for line in runmap.read_text().splitlines():
    if not line.strip():
        continue
    name, transcript = line.split("\t")
    workspace = str(runs_dir / name / "repo")
    other = collections.Counter()
    forbidden, net, skills = [], 0, []
    for raw in open(transcript, errors="ignore"):
        try:
            event = json.loads(raw)
        except json.JSONDecodeError:
            continue
        message = event.get("message", {})
        if event.get("type") != "assistant" or not isinstance(message.get("content"), list):
            continue
        for item in message["content"]:
            if item.get("type") != "tool_use":
                continue
            text = json.dumps(item.get("input", {}))
            if item.get("name") in ("WebFetch", "WebSearch") or NET_RE.search(text):
                net += 1
            if item.get("name") == "Skill":
                skills.append(("Skill", text[:120]))
            skills += [(item.get("name"), p) for p in SKILL_RE.findall(text)]
            for path in (os.path.normpath(p) for p in PATH_RE.findall(text)):
                # Classify forbidden paths first: a temp-path or workspace exemption must never hide them.
                in_workspace = path == workspace or path.startswith(workspace + os.sep)  # containment, not string prefix
                other_runs = f"{runs_dir}{os.sep}" in path and not in_workspace
                if FORBIDDEN_RE.search(path) or other_runs:
                    forbidden.append(path[:120])
                elif in_workspace or path == "/dev/null":
                    continue
                else:
                    other[path[:80]] += 1
    print(json.dumps({"run": name, "network": net, "forbidden_paths": forbidden,
                      "other_outside": dict(other.most_common(5)), "skill_access": skills}))
