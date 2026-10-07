---
name: agent-loop-dream
description: Run the Dream maintenance pass over existing Learning guidance, triage its warnings and candidate decisions, and hand every decision to a named human without changing active guidance.
---

# Agent Loop Dream

**Trigger Anchor:** Periodic guidance maintenance is due (after several close-outs, after a package bump, or the user says "dream") -> run the read-only review, triage it, and hand each decision to a named human. Dream never approves, applies, retires, deletes, or rewrites guidance.

Dream is the maintenance step of the Promotion Loop (`Dream -> Proposal -> Human Approval -> Constraint/Skill -> Enforcement -> Retirement`). Capturing findings and closing a Run stay in `agent-loop-learning-boundary`; changing a skill, doc, or memory file stays with the owner of that file.

## Automatic preview

The SessionStart hook runs the read-only preview itself when it is due (no previous automatic run, the Learning inputs changed by content, or the last run is older than seven days) and shows the numbers under `## Agent Loop Dream`. Treat that section as a finished Review step only when the preview succeeded: report its numbers, and do not rerun Dream just to see them. The section carries no outcome coverage, and on a failure ("the automatic Dream preview failed") it carries no numbers at all; in either case run the manual Review below to get what is missing. It is an observation, never a decision, and it writes nothing into the Learning root. Everything below still applies to candidates, triage and human handoff. `AGENT_LOOP_DREAM_AUTORUN=0` switches the automatic preview off.

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

## Review the pending queue

Dream also owes a view of proposals that are already waiting (candidate or approved), not only newly written candidates. Use `learn proposal-queue --probe <repo guidance file>` (repeat `--probe` per guidance file the repository keeps, for example its memory index; add `--format=json` for machine output). It is read-only and states facts only: allowed transitions, lineage (`corrects`, `corrected_by`, `supersedes`), other proposals on the same target, unresolved paths, and how much of the proposed wording already exists in the target or probe files.

Present one table row per proposal: `id | status action | target | signals | take | reason`.

- The **take** is yours: approve, reject, acknowledge or apply, with a one-line reason. It is advice for a named human, never a decision, and the tool does not produce it.
- Ground every take in a signal. "Already covered" needs an exact or high wording match in a repository file; a memory of the guidance elsewhere is not evidence. "Superseded" needs `corrects` or `corrected_by`. Without a signal, label the take unverified.
- An approved proposal whose wording is already exact in its target was applied by hand: confirm, then record it with `proposal-mark-applied` instead of writing the guidance again.
- Run a transition only after the human names the decision, with `--by` and, for reject and acknowledge, a real reason. One proposal per decision; never batch to empty the queue.

## Write candidates (only on an explicit human ask)

`make agent_learning_dream_write_candidates` (or `learn dream --write-candidates`) writes review records only. Run it once step 1 shows no concurrent diff in the Learning root. Route each record through exactly one of `learn proposal-approve`, `learn proposal-reject` or `learn proposal-acknowledge`, with a named human (`--by`) and, for reject and acknowledge, a real reason. Never approve a candidate because it exists, and never bulk-acknowledge to empty the queue.

## History projections

`learn history-status` is read-only. Before `learn history-rebuild`, inspect the result with `--dry-run`; rebuild once and only when the source history is stable, not inside a dirty shared root. Projections never replace the immutable evidence.

## Close-out

Report compactly: the command run, the report path, the verbatim counts, which warnings were repaired versus left, and which decisions still need a human. Say plainly when candidates were not written or projections were not rebuilt. The report is regenerable working state; do not commit it.
