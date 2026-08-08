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

The review covered skills, hooks/runtime, subagent propagation, dedicated agent
roles, review/audit flows, compression/debt/statistics ideas, installation
surfaces, tests, benchmark guidance, and human-facing output structure. The
resulting `agent-loop` behavior is an adaptation for the existing PHP-oriented
`agent-*` architecture, not a bundled copy of any upstream runtime.

The August 2026 recheck found no new Caveman mechanism that invalidated the
existing adaptation. Ponytail had expanded host portability, which reinforced
using one canonical behavior contract with thin client-specific adapters rather
than importing another runtime.

## Adapted mechanisms

### From Caveman

- filler-free human-facing communication became the communication section of
  `agent-loop-discipline`, while keeping normal grammar;
- terse correctness review became `agent-loop-code-review` and the
  `agent-loop-code-reviewer` role;
- Cavecrew's locator/editor/reviewer split became the map-first
  `agent-loop-investigate`, `agent-loop-surgical-edit`, and
  `agent-loop-code-review` skills plus package-owned subagent definitions;
- the locator role uses `agent-map` before bounded real-source reads instead of
  relying on broad grep/explore output;
- role output contracts informed compact receipts while exact evidence remains
  uncompressed;
- the compression goal is handled through selective `agent-map`/recall context
  rather than overwriting durable memory files;
- honest measurement informed `agent-loop-dogfood`; no local token-savings claim
  is emitted without real telemetry.

### From Ponytail

- the minimal implementation ladder and root-cause/caller rule became part of
  `agent-loop-discipline`;
- diff-only complexity review became `agent-loop-simplify-review`;
- repository-wide complexity hunting became `agent-loop-simplify-audit`;
- deliberate simplification debt is recorded in `agent-session` as a decision
  with a known ceiling and observable revisit trigger, then promoted only through
  the normal `agent-learning` review boundary;
- the gain/benchmark honesty rule became an observable-artifact requirement in
  `agent-loop-dogfood`;
- session/subagent propagation informed the package-owned hook and subagent
  installation paths;
- newer multi-host adapters reinforced keeping shared policy in typed PHP and
  making host hook serialization a thin boundary.

### From Attention Control

- human-facing progress leads with a useful result/fact and keeps explicit
  workflow state plus one concrete next action;
- agents perform reads, edits, validation, and reporting that their available
  tools own instead of handing executable work back to the human;
- state is restated only when it materially changes, avoiding both hidden drift
  and per-tool narration;
- accuracy, exact technical strings, security context, and irreversible-action
  clarity override brevity or formatting;
- output-shaping instructions are treated as behavioral guidance, not as an
  enforcement or security boundary.

The generic `RESULT` / `STATE` / `NEXT` progress receipt and
`RESULT` / `EVIDENCE` / `OMITTED` completion receipt are `agent-loop` contracts,
not copies of Attention Control's reader-specific presentation model. Their
fields map directly to persisted workflow state and agent-loop evidence gates.

## Deliberately not ported

`agent-loop` does not download, execute, or depend on these upstream projects at
runtime. It deliberately excludes their:

- remote installers and plugin marketplaces;
- Node.js runtimes;
- hidden mode/flag state and status lines;
- transcript scanners and per-repo savings estimates;
- file-overwriting memory compression runtime;
- client adapters already covered by `agent-loop init` target rendering;
- MCP/plugin surfaces that do not belong to the existing `agent-*` packages;
- reader-identity assumptions or prose rules that do not describe a coding
  workflow invariant.

Caveman's commit-message helper is also not an `agent-loop` responsibility; the
workflow package should not absorb every useful coding-agent convenience merely
because an upstream project contains it.

These notices document provenance only. They are not installation instructions
and are never read or executed by `agent-loop init install-assets`.

The upstream projects remain copyright their respective authors and are
available under their own MIT license files:

- <https://github.com/JuliusBrussee/caveman/blob/14d4f2e21a16b573373ca24698cd6bd3db75bf52/LICENSE>
- <https://github.com/DietrichGebert/ponytail/blob/2ed6c52c9d7e5e56942508591085fd45dea277d3/LICENSE>
- <https://github.com/aaddrick/attention-control/blob/3c8a2a8a38f163aa85ad325812b5ce3ba330ad27/LICENSE>
