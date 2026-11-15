# Local task autonomy

An approved Task Contract authorizes work inside its stated scope. Local task autonomy adds an explicit, expiring opt-in for the host to keep progressing through ordinary implementation and validation work without asking the operator to re-approve each step.

The grant is owned by `agent-loop` and bound to one task, the exact approved Contract revision and its canonical SHA-256 digest. `enter` includes the grant state in the host context. Owner-side eligibility checks reload the current Contract and reject expired, revoked, mismatched, or stale grants. Mutating operations also require each repository-relative target path to be inside the approved scope.

## Issue and inspect

```sh
agent-loop workflow autonomy TASK-1 issue --by "Lars Moelleken" --until "2026-11-15T23:00:00Z"
agent-loop workflow autonomy TASK-1 status --format=json
```

Issuance requires a currently human-approved Contract and is limited to 24 hours. `--by` records the local operator attribution; it is not cryptographic identity proof. This command is intended for a trusted local host where access to the terminal is within the operator's trust boundary. The default remains interactive when there is no active grant.

Only one unexpired grant can exist per task. Revoke it before issuing a replacement:

```sh
agent-loop workflow autonomy TASK-1 revoke --by "Lars Moelleken"
```

Contract revision changes invalidate an existing grant. Re-approve the new Contract and issue a new grant explicitly.

## Authority that remains separate

A task grant does not approve a Contract, resolve Attention, accept risk, acknowledge an independent review, promote Learning, or authorize merge or deployment. Those continue through their existing owner APIs and human gates. A grant is not a substitute for configured tests, review, or current-candidate evidence. Expiry, revocation, stale Contract state, exhausted retry budgets, and out-of-scope work stop continuation.
