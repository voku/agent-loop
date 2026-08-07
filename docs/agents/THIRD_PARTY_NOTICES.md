# Third-party inspiration

The first-party agent behavior shipped by `voku/agent-loop` was developed after
reviewing these MIT-licensed projects at fixed commits:

- **Caveman**, Julius Brussee:
  `ec83e5bace4c20484d704dea21e12fc4eb94e9aa`
- **Ponytail**, Dietrich Gebert:
  `16f29800fd2681bdf24f3eb4ccffe38be3baec6b`

The review covered their skills, hooks/runtime, subagent propagation, dedicated
agent roles, review/audit flows, compression/debt/statistics ideas, installation
surfaces, tests, and benchmark guidance. The resulting `agent-loop` behavior is
an adaptation for the existing PHP-oriented `agent-*` architecture, not a bundled
copy of either runtime.

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
  installation paths.

## Deliberately not ported

`agent-loop` does not download, execute, or depend on either upstream project at
runtime. It deliberately excludes their:

- remote installers and plugin marketplaces;
- Node.js runtime;
- hidden mode/flag state and status lines;
- transcript scanners and per-repo savings estimates;
- file-overwriting memory compression runtime;
- client adapters already covered by `agent-loop init` target rendering;
- MCP/plugin surfaces that do not belong to the existing `agent-*` packages.

Caveman's commit-message helper is also not an `agent-loop` responsibility; the
workflow package should not absorb every useful coding-agent convenience merely
because the upstream project contains it.

These notices document provenance only. They are not installation instructions
and are never read or executed by `agent-loop init install-assets`.

The upstream projects remain copyright their respective authors and are
available under their own MIT license files:

- <https://github.com/JuliusBrussee/caveman/blob/ec83e5bace4c20484d704dea21e12fc4eb94e9aa/LICENSE>
- <https://github.com/DietrichGebert/ponytail/blob/16f29800fd2681bdf24f3eb4ccffe38be3baec6b/LICENSE>
