# Governed Recall lineage and the four prompt controls — 2026-08-14

The open question was whether `run_id` needs to spread further across Learning,
Proposal and Finding artifacts. The answer required a real governed Run rather
than a design argument, so this is what a completed, session-pruned Run actually
joins, and what the reflection helpers actually carry.

## The Run inspected

`run:SELF-SHAPE:844ba804fa4eaa35`, produced by `tools/self-shape-dogfood.sh`
governing a 57-file agent-loop diff, closed `complete`, then pruned with
`session prune --keep-days 0 --status done`.

## What the lineage joins

| Owner | Join recorded in the Run manifest |
| --- | --- |
| Contract | revision 1, `sha256:2ca81057…` of the durable contract source |
| Approval | contract revision 1, actor, **the same contract sha256** |
| Recall | `compilation.SELF-SHAPE.2026-08-14-061539.3b9564da`, bundle and snapshot digests |
| Session | `2026-08-14-self-shape`, state `done` |
| Verification | `run_id`, `contract_revision`, `source_session_id` |
| Learning | `run_id`, decision `findings_recorded`, and the 23 cited `finding_ids` |

Answering the questions directly:

- **Can a generated artifact be tied to the correct Run?** Every *durable*
  artifact can. The verification receipt and the Learning decision both carry
  `run_id` as a field, and the rest join through the manifest.
- **Can it be tied to the exact approved Contract?** Yes, and not merely by
  revision number: approval and contract reference the identical content hash,
  so a silently edited contract cannot masquerade as the approved one.
- **Is a required lineage join missing?** No. The Learning decision record does
  not carry `contract_revision`, but the Run it names does, so adding the field
  would duplicate a join that already resolves. **No `run_id` field was added
  anywhere.**
- **Does Session pruning damage explainability?** No. After pruning, the session
  reference reports `missing` while contract, approval, recall, verification and
  learning stay intact, and the verification receipt still names
  `source_session_id`, so the pruned session remains identifiable.

## The reflection helpers carry no lineage, correctly

`workflow reflect <task> --scope task|project` gates on the task being
`ready_to_close` or `complete`, then renders a context-light prompt to stdout.
The output contains no task id, no `run_id` and no contract reference — because
it is **not an artifact**. Nothing is persisted, so there is nothing to bind.

That is the right shape today. If a reflection result were ever persisted as a
Finding or a proposal, it would need the same joins as any other durable record;
until then, adding identity to a prompt would be governance applied to a string.

Verified directly: running both reflections against the completed Run left
`state`, `next_action` and the Learning decision unchanged.

## The four controls

| Control | How it is invoked | Verified property |
| --- | --- | --- |
| Task reflection | `workflow reflect <task> --scope task` | Surfaces missed work and can return `RETURN_TO_REVIEW`, but the lifecycle state is unchanged after running it. It reports a gap; it does not reopen the task. |
| Future-work reflection | `workflow reflect <task> --scope project` | The prompt explicitly permits "say that nothing worthwhile emerged", and instructs against turning reflection into a review, a learning rule or a backlog. |
| Momentum | `workflow plan --operating-prompt '{"id":"momentum","arguments":{}}'` | Compiled into `recall/<task>/system.md` with authority `approved_session_brief`, use `direct_l1_operating_contract`, and template SHA-256. The template requires re-checking anything whose authority, freshness or scope may have changed. |
| Checkpoint autonomy | `workflow plan --operating-prompt '{"id":"checkpoint-autonomy","arguments":{"anchor_point":"…"}}'` | Same governed path, with `{{anchor_point}}` substituted into the rendered contract. The template stops for human input when approval, missing product intent, irreversible action, risk ownership or a scope change requires it — a self-check never becomes approval. |

None of them is a lifecycle state. Two are a command over a finished task; two
are recipes selected at plan time and compiled into Recall with provenance. The
mechanism that already existed was extended; nothing new was introduced.

Note that `workflow context`'s `Selected guidance:` section is about durable
Learning guidance, not operating prompts — the prompts reach the agent through
the compiled `system.md`, which is where their provenance lives.
