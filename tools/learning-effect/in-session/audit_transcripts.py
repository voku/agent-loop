#!/usr/bin/env python3
r"""Audit in-session agent transcripts for isolation and skill access.

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
  owner_tool_calls   counts of tool calls that run agent-map, try to read a Recall artifact under
                     .agent-loop/recall/, run the Recall compiler, or run agent-loop, next to
                     plain_search (Grep/Glob or rg/grep/find/git grep). A count shows what the
                     host called or tried to read, not whether it succeeded or helped.

owner_tool_calls is a HIT FINDER, not a measurement. Its numbers are candidates that point at
calls to inspect; they are not evidence that something was or was not used. What each key is:

  map_cli, recall_compile, loop_cli   Bash only. A call counts at command position, after
      heredoc bodies and quoted literal text are removed, so mentions (repo names in loops,
      search queries, commit messages, --goal prose) do not count. recall_compile counts only
      the `compile` subcommand; Loop compiles Recall in-process during enter/approve, so 0 is
      expected and does not mean Recall was absent. Usable as candidates, with the misses below.
      Loop's own subcommands `agent-loop map ...` and `agent-loop recall ...` count ONLY as
      loop_cli, never as map_cli or recall_compile. Hosts are told to use Map through Loop
      (agent-loop-investigate and other skills), so for any Map or Recall claim, classify the
      loop_cli calls by subcommand too. Loop also calls Map and Recall in-process, so map_cli 0
      does not mean Map was unused.
  recall_artifact_read   Read tool: a Read call whose file_path contains .agent-loop/recall/
      followed, with no whitespace in between, by .md or .json (substring match, so .jsonl,
      .mdx and paths outside the run's workspace also match). Counted per call from the tool_use alone, so failed, repeated
      and partial Reads all count. It shows only that a Read was attempted in this transcript:
      not that all Recall files were read, not that a Read succeeded, and not that the content
      was understood or used.
      Bash: text-wide match of a reader word anywhere earlier on the line, NOT usable for
      claims. On a real transcript 5 of 6 Bash-based reads were accidental matches and about 14
      real reads counted 0 (python3 -c across a newline, path in a variable, grep/awk reads,
      reads inside heredocs). Grep/Glob on a Recall path count as plain_search, not as reads.
  plain_search   Grep/Glob tools, plus a text-wide Bash match (heredocs stripped, quotes NOT):
      quoted prose can count. Not usable for claims.

Calls made by a sub-agent are not in the parent transcript; each transcript is audited on
its own.

Known shapes where the command keys miss a real call or over-count a mention:
MISSES (a real call counts 0); [seen] = found in a real transcript:
  php bin/agent-map $c $I                    subcommand in a variable [seen: 9 runs, 2026-11-18]
  L=vendor/bin/agent-loop && $L enter X      program in a variable [seen, 2026-11-19]
  php bin/agent-loop --version               option before the subcommand [seen]
  php -d memory_limit=1G vendor/bin/agent-map search x   php with options (the git-hook form)
  sudo|command|nohup|stdbuf|watch|/usr/bin/time bin/agent-loop enter X   unlisted wrapper
  php8.3 bin/agent-loop enter X              versioned php binary
  if bin/agent-loop enter X; then ...        also while/until/elif in front of the call
  case $x in a) bin/agent-loop enter X;; esac  call inside a case arm
  echo $'it\'s'; bin/agent-loop enter X      $'...' quoting confuses the scanner
  bash -c 'bin/agent-loop status X'          also sh -c, ssh host '...'
  "$ROOT/bin/agent-loop" enter X             quoted or ${VAR}/ executable path
  agent-loop enter X                         found on PATH, no bin/ (also composer exec)
  bin/agent-loop \<newline> enter X          line continuation inside the call
  PowerShell or any non-Bash tool            map/compile/loop keys inspect only Bash
OVER-COUNTS (a mention counts 1):
  grep -rn foo \<newline> bin/agent-loop src   continuation: an argument looks like a call

To use the command keys in a result:
  1. List the tool_use inputs (not tool results or prose) that contain the word agent-map,
     agent-loop or agent-recall-compiler; ignore matches that are only .agent-loop/ paths,
     /home/user/agent-* repository paths or voku/agent-* package names. Compare that list with
     the counted calls; every uncounted entry that really ran is a miss (this covers every
     shape listed above, including variables, wrappers and PATH calls).
  2. Hand-check every counted call, since over-counts exist.
  3. If 1 or 2 cannot be done completely, mark the count incomplete.
For "was Recall received", start from Recall's own artifacts (selection-report.json says what
was provided). Loop's stage prompts carry the Recall briefing inline, so Recall can be received
with no Read call at all: a Read count of 0 does not mean Recall was absent. Read-tool hits and
a hand-classified sample of Bash calls are supporting evidence only. Sub-agent transcripts and
other tools are separate evidence sources. Do not quote Bash-based recall_artifact_read.
The robustness probes show bad input does not crash or hang the scanner; they do not make it
a full shell parser.

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
# A call counts only at command position: line start, after ; & | && || ( $( { !, or after the
# shell keywords do/then/else, optionally behind time/nice/timeout/env/xargs/exec (with their
# options), VAR=value and php. A repo name in a loop, a search query or quoted text is a mention,
# not a call (39 loose matches vs 10 real runs on a real transcript).
CMD_START = (r'(?:^|[;&|({!]|\$\(|\b(?:do|then|else)\b)\s*'
             r'(?:(?:time|nice|timeout|env|xargs|exec)(?:\s+-\S+(?:\s+\d+)?)*(?:\s+\d+\S*)?\s+|\w+=\S+\s+)*'
             r'(?:php\s+)?')
PATH_PREFIX = r'(?:[\w.~$-]*/)*'
COMMAND_RES = {
    "map_cli": re.compile(CMD_START + PATH_PREFIX + r'agent-map\s+[a-z]', re.M),
    "recall_compile": re.compile(CMD_START + PATH_PREFIX + r'agent-recall-compiler\s+compile', re.M),
    "loop_cli": re.compile(CMD_START + PATH_PREFIX + r'bin/agent-loop\s+[a-z]', re.M),
}
# Heredoc bodies are code or text the agent writes, not commands it runs. Only the body goes;
# the rest of the opening line (e.g. "&& bin/agent-loop finish X") stays. <<- allows tab-indented
# terminators.
HEREDOC_RE = re.compile(r"(<<-?\s*(['\"]?)(\w+)\2[^\n]*\n).*?\n\t*\3\b", re.S)
# Command calls only. Quoted text (commit messages, --goal/--summary prose, echo arguments) is not
# a command; $(...) and `...` are, even inside double quotes, and open a new quoting context there,
# as in bash. A small scanner tracks that, since a regex cannot follow nested quotes.
def without_heredoc_bodies(command):
    """Drop heredoc bodies (code or text the agent writes); keep the rest of the opening line."""
    return HEREDOC_RE.sub(lambda m: m.group(1), command)


def executable_text(command):
    """The parts of a Bash command that can run as a command, for COMMAND_RES matching only."""
    text = without_heredoc_bodies(command)
    out, stack, i, n = [], ["code"], 0, len(text)
    while i < n:
        c, ctx = text[i], stack[-1]
        if ctx == "single":
            if c == "'":
                stack.pop()
                out.append(" ")
            i += 1
            continue
        if c == "\\":
            if ctx != "double":
                out.append(text[i:i + 2])
            i += 2
            continue
        if ctx == "double":
            if c == '"':
                stack.pop()
                out.append(" ")
                i += 1
            elif text.startswith("$(", i):
                stack.append("subst")
                out.append(" $(")
                i += 2
            elif c == "`":
                stack.append("tick")
                out.append(" $(")
                i += 1
            else:
                i += 1  # quoted literal text is not a command
            continue
        # code-like contexts: code, ( group, $( substitution, backtick substitution
        if c == "'":
            stack.append("single")
        elif c == '"':
            stack.append("double")
        elif c == "#" and (i == 0 or text[i - 1] in " \t\n;&|("):
            while i < n and text[i] != "\n":
                i += 1
            continue
        elif text.startswith("$(", i):
            stack.append("subst")
            out.append("$(")
            i += 2
            continue
        elif c == "`":
            if ctx == "tick":
                stack.pop()
                out.append(")")
            else:
                stack.append("tick")
                out.append("$(")
        elif c == "(":
            stack.append("group")
            out.append(c)
        elif c == ")" and ctx in ("subst", "group"):
            stack.pop()
            out.append(c)
        else:
            out.append(c)
        i += 1
    return "".join(out)


SEARCH_RE = re.compile(r'(?:^|[\s;&|("])(?:rg|grep|find|git\s+grep)\s', re.M)
RECALL_ARTIFACT_RE = re.compile(r'\.agent-loop/recall/\S*\.(?:md|json)')
FILE_READER_RE = re.compile(r'(?:^|[\s;&|(])(?:cat|head|tail|less|sed|jq|python3?)\s[^\n]*\.agent-loop/recall/\S*\.(?:md|json)', re.M)
OWNER_TOOL_KEYS = [*COMMAND_RES, "recall_artifact_read", "plain_search"]


def owner_tool_calls(name, tool_input):
    """Counter keys one tool call adds, judged by what the tool does, not by text it carries."""
    keys = []
    if name == "Bash":
        raw = str(tool_input.get("command", ""))
        runnable = executable_text(raw)
        keys += [key for key, pattern in COMMAND_RES.items() if pattern.search(runnable)]
        body = without_heredoc_bodies(raw)  # search and Recall-read rules: heredoc strip only
        if SEARCH_RE.search(body):
            keys.append("plain_search")
        if FILE_READER_RE.search(body):
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
