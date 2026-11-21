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
  owner_tool_calls   counts of tool calls that run agent-map, read a Recall artifact under
                     .agent-loop/recall/, run the Recall compiler, or run agent-loop, next to
                     plain_search (Grep/Glob or rg/grep/find/git grep). A count shows what the
                     host called or read, not whether it helped.

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
# Matched against the decoded Bash `command` (multiline-safe) or the Read path only:
# text an agent writes (Write/Edit/NotebookEdit content) is never evidence of tool use.
COMMAND_RES = {
    "map_cli": re.compile(r'(?:^|[\s/;&|("])agent-map\s+[a-z]', re.M),
    "recall_compile": re.compile(r'agent-recall-compiler\s+compile'),
    "loop_cli": re.compile(r'bin/agent-loop\s+[a-z]'),
}
SEARCH_RE = re.compile(r'(?:^|[\s;&|("])(?:rg|grep|find|git\s+grep)\s', re.M)
RECALL_ARTIFACT_RE = re.compile(r'\.agent-loop/recall/\S*\.(?:md|json)')
FILE_READER_RE = re.compile(r'(?:^|[\s;&|(])(?:cat|head|tail|less|sed|jq|python3?)\s[^\n]*\.agent-loop/recall/\S*\.(?:md|json)', re.M)
OWNER_TOOL_KEYS = [*COMMAND_RES, "recall_artifact_read", "plain_search"]


def owner_tool_calls(name, tool_input):
    """Counter keys one tool call adds, judged by what the tool does, not by text it carries."""
    keys = []
    if name == "Bash":
        command = str(tool_input.get("command", ""))
        keys += [key for key, pattern in COMMAND_RES.items() if pattern.search(command)]
        if SEARCH_RE.search(command):
            keys.append("plain_search")
        if FILE_READER_RE.search(command):
            keys.append("recall_artifact_read")
    elif name == "Read" and RECALL_ARTIFACT_RE.search(str(tool_input.get("file_path", ""))):
        keys.append("recall_artifact_read")
    elif name in ("Grep", "Glob"):
        keys.append("plain_search")
    return keys

for line in runmap.read_text().splitlines():
    if not line.strip():
        continue
    name, transcript = line.split("\t")
    workspace = str(runs_dir / name / "repo")
    other = collections.Counter()
    forbidden, net, skills = [], 0, []
    owner_calls = collections.Counter({key: 0 for key in OWNER_TOOL_KEYS})
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
            for key in owner_tool_calls(item.get("name"), item.get("input", {})):
                owner_calls[key] += 1
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
                      "other_outside": dict(other.most_common(5)), "skill_access": skills,
                      "owner_tool_calls": dict(owner_calls)}))
