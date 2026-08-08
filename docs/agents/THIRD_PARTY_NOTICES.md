# Third-party inspiration

The first-party agent behavior shipped by `voku/agent-loop` was developed after
reviewing these MIT-licensed projects at fixed commits:

- **Caveman**, Julius Brussee:
  `ec83e5bace4c20484d704dea21e12fc4eb94e9aa`, rechecked against
  `14d4f2e21a16b573373ca24698cd6bd3db75bf52`;
- **Ponytail**, Dietrich Gebert:
  `16f29800fd2681bdf24f3eb4ccffe38be3baec6b`, rechecked against
  `2ed6c52c9d7e5e56942508591085fd45dea277d3`;
- **Attention Control**, aaddrick:
  `3c8a2a8a38f163aa85ad325812b5ce3ba330ad27`.

A source review is not an adaptation claim. The reviewed repositories contain
more mechanisms than `agent-loop` should or currently does implement.
`UPSTREAM_CAPABILITY_MATRIX.md` is the explicit row-by-row inventory of what is
already represented, what this repository adapted, what is deferred behind a
missing typed contract, and what is deliberately rejected.

The target is not feature parity. `agent-loop` adapts useful mechanisms into its
existing PHP-oriented architecture: persisted workflow state, bounded context,
exact evidence, narrow roles, reviewed learning, and executable constraints only
where the condition is objectively observable.

## High-level adapted ideas

### From Caveman

- concise human-facing communication without changing exact technical strings;
- narrow locator, surgical-builder, and correctness-review roles;
- deterministic terminal outcomes for narrow role handoff;
- session/subagent reinforcement so global discipline survives delegation and
  host context resets;
- compact projections for human/context efficiency while raw source, diffs,
  tests, errors, and verification evidence remain unchanged.

### From Ponytail

- the minimal implementation ladder and root-cause/caller discipline;
- diff-only and repository-wide complexity review as separate passes;
- deliberate simplifications recorded with a known ceiling and observable
  revisit trigger;
- the smallest meaningful runnable proof for non-trivial changed logic;
- one canonical behavior source with thin host adapters;
- measurement honesty: no per-repository or counterfactual savings claim without
  owned telemetry and a valid baseline.

### From Attention Control

- lead progress with a useful result/fact and one concrete next action;
- perform tool-executable work instead of handing it back to the human;
- preserve workflow state across context loss with a bounded resume hint;
- preserve uncertainty as a fact rather than filling gaps with plausible
  versions, paths, line numbers, results, approvals, or intent;
- state errors and blockers with decisive evidence;
- let safety, irreversible actions, exact strings, and accuracy override brevity.

The generic `RESULT` / `STATE` / `NEXT` progress receipt and
`RESULT` / `EVIDENCE` / `OMITTED` completion receipt are `agent-loop` contracts,
not copies of Attention Control's reader-specific presentation model. Their
fields map to persisted workflow state and `agent-loop` evidence gates.

## Deliberately not ported

`agent-loop` does not download, execute, or depend on these upstream projects at
runtime. It deliberately excludes mechanisms whose scope or trade-offs do not
fit the workflow contract, including:

- remote installers and plugin marketplaces;
- Node.js runtimes as a dependency of the package-owned guidance path;
- hidden persona/intensity mode flags and status lines;
- transcript scanners and ungrounded token/code savings claims;
- file-overwriting model compression of durable memory/evidence;
- model/provider overrides owned by the host;
- client adapters already covered by `agent-loop init` target rendering;
- reader-identity assumptions or fixed prose limits that are presentation
  preferences rather than coding-workflow invariants;
- convenience features such as a commit-message generator that do not belong to
  workflow governance.

Some useful ideas are intentionally **deferred**, not rejected. For example, a
cross-session simplification-debt ledger needs a typed `agent-session` decision
query before the umbrella package can provide it without scraping a focused
package's storage layout. These distinctions are maintained in
`UPSTREAM_CAPABILITY_MATRIX.md`.

## Recheck rule

Do not summarize a future upstream comparison as "nothing relevant changed"
merely because the pinned commit diff is small. A recheck must first compare the
current upstream mechanism set against the capability matrix, including `DEFER`
rows and previous rejection reasons. Reading a changed file is evidence of the
source review, not evidence that `agent-loop` already contains its essence.

These notices document provenance only. They are not installation instructions
and are never read or executed by `agent-loop init install-assets`.

The upstream projects remain copyright their respective authors and are
available under their own MIT license files:

- <https://github.com/JuliusBrussee/caveman/blob/14d4f2e21a16b573373ca24698cd6bd3db75bf52/LICENSE>
- <https://github.com/DietrichGebert/ponytail/blob/2ed6c52c9d7e5e56942508591085fd45dea277d3/LICENSE>
- <https://github.com/aaddrick/attention-control/blob/3c8a2a8a38f163aa85ad325812b5ce3ba330ad27/LICENSE>
