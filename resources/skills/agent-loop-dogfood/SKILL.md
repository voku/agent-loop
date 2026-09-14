---
name: agent-loop-dogfood
description: Evaluate agent guidance against real agent-* tasks with clean, comparable runs and observable artifact metrics instead of invented token savings.
---

# Agent Loop Dogfood

**Trigger Anchor:** Guidance, hook, recall, or map navigation changes -> run comparative dogfood against real tasks with identical baselines, measure observable artifacts, reject invented metrics.

## Dogfood Modes

| Mode | What It Proves | Result Evidence | What It Does NOT Prove |
|---|---|---|---|
| **Installer** | Assets project correctly into host (`.codex/`, `.claude/`, etc.) | File presence in projected directory | Does not prove agent consumed or followed them |
| **Deterministic Lifecycle** | Mechanics of Loop/Recall/Session/Learning compile & output properly | Generated artifacts (`system.md`, `facts.json`) | Does not prove model altered its runtime behavior |
| **Agent-Host** | Behavioral impact of skills, prompts, or hooks on real agent output | Clean session trajectory, observed actions | Does not prove portability across unrelated hosts |

## Core Method

1. **Pre-Session Projection:** Project candidate skills/hooks *before* starting the session. Never inject mid-session and claim prior causality.
2. **Identical Baselines:** Keep task phrasing, repo commit, model version, and verification commands identical between baseline and candidate.
3. **Isolate Changes:** Mutate one guidance mechanism at a time. If a run fails, record the failure in `docs/dogfood/` before adjusting.
4. **Environment Recovery:** If a fresh host lacks dependencies/remotes, observe if the agent safely recovers reversible bootstrap before flagging a workflow blocker.
5. **Real-Issue Protocol:** Test against real issues (`docs/dogfood/real-issue-acceptance.md`), using project-native test gates as correctness authority.

## Observable Metrics

Record only verifiable values; reject counterfactual claims or unmonitored "token savings":
- Files & lines read before finding target code.
- Files changed and lines added/deleted.
- Tool calls and search commands executed.
- Unrequested code, configuration, or dependencies added.
- Validation commands executed and exit codes.
- Actual generated Recall briefing consumed (yes/no/not-observable).
- Local Git hooks executed (yes/no/not-observable).

### Bad vs Good Dogfood Evaluation

### Bad
```text
# Subjective / unverified claim
"The new skill made the agent 40% faster and saved ~500 reasoning tokens through better architectural clarity."
```

### Good
```text
# Observable artifact evidence
- Baseline: 14 file reads (8 irrelevant), 3 test runs, 42 lines changed across 3 files.
- Candidate: 2 map queries (`map scope`), 1 file read, 1 test run, 6 lines changed in 1 file.
- Correctness: `composer ci` exit 0 on both.
```

## Acceptance Gate

Merge candidate guidance only when:
1. Correctness, security, validation, and evidence integrity do not regress.
2. Zero unrequested behavior, abstractions, or configuration switches are introduced.
3. At least one context or attention metric improves on non-trivial tasks.
4. Trivial tasks gain no mandatory ceremony or latency overhead.
5. Tool utility ledger explicitly states whether each tool helped, abstained, or created noise.
