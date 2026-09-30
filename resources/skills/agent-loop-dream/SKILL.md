---
name: agent-loop-dream
description: Run the Dream maintenance pass over existing Learning guidance, triage its warnings and candidate decisions, and hand every decision to a named human without changing active guidance.
---

# Agent Loop Dream

**Trigger Anchor:** Periodic guidance maintenance is due (after several close-outs, after a package bump, or the user says "dream") -> run the read-only review, triage it, and hand each decision to a named human. Dream never approves, applies, retires, deletes, or rewrites guidance.

Dream is the maintenance step of the Promotion Loop (`Dream -> Proposal -> Human Approval -> Constraint/Skill -> Enforcement -> Retirement`). Capturing findings and closing a Run stay in `agent-loop-learning-boundary`; changing a skill, doc, or memory file stays with the owner of that file.

## Run

1. **Precondition:** `vendor/bin/agent-loop learn validate` passes and the Learning root has no half-written concurrent work (`git status` on the root). A failing validate is invalid local data or a package defect: read the failing package source path to decide, and never weaken local data to get green.
2. **Review (read-only):** `make agent_learning_dream` when the host includes `resources/make/agent-loop.mk`, otherwise `vendor/bin/agent-loop learn dream --report .agent-loop/dream/latest.json --dry-run`. Add `--format=json` (`ARGS=--format=json`) for machine-readable output. The Make target reads `AGENT_LEARNING_ROOT` and `AGENT_DREAM_REPORT`; an empty root means auto-discovery.
3. **Report the numbers verbatim:** evaluated guidance, warnings, review decisions, suppressed unchanged decisions, outcome coverage. Only suppressed decisions is a healthy no-op, not proof that guidance is good.

Flag semantics (from the Learning CLI): candidates are written only with `--write-candidates` and never together with `--dry-run` (`--dry-run` wins); `--report PATH` writes the deterministic JSON report; a bare `learn dream` renders the review and writes neither. Embedding hosts call `WorkflowDreamService` (`preview()` writes nothing, `writeCandidates()` writes candidate Proposals only) with the same contract.

## Triage

| Signal | Action |
| --- | --- |
| `evidence_reference_unresolvable` | Repair the path, or turn a reference that is genuinely outside this repository into `manual_verification` keeping the claim in its summary. Never delete the observation. |
| `outcome_unknown` | Append-only history; do not rewrite it. Reduce recurrence at Recall close-out instead. |
| Review decisions > 0 | Candidates, not decisions. Present each with its provenance and the guidance it targets. |
| `CONFLICT` / `REPLACEMENT_CANDIDATE` | Only explicit lineage or exact duplicate wording produces these; different prose is never guessed to conflict. A conflict record uses `NO_DURABLE_LEARNING`, so a human can acknowledge or reject it without a mutation. |

## Write candidates (only on an explicit human ask)

`make agent_learning_dream_write_candidates` (or `learn dream --write-candidates`) writes review records only. Run it once step 1 shows no concurrent diff in the Learning root. Route each record through `learn proposal-approve|reject|acknowledge` with a named human (`--by`) and, for reject/acknowledge, a real reason. Never approve a candidate because it exists, and never bulk-acknowledge to empty the queue.

## History projections

`learn history-status` is read-only. Before `learn history-rebuild`, inspect the result with `--dry-run`; rebuild once and only when the source history is stable, not inside a dirty shared root. Projections never replace the immutable evidence.

## Close-out

Report compactly: the command run, the report path, the verbatim counts, which warnings were repaired versus left, and which decisions still need a human. Say plainly when candidates were not written or projections were not rebuilt. The report is regenerable working state; do not commit it.
