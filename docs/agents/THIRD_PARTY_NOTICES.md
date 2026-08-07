# Third-party inspiration

The first-party agent discipline shipped by `voku/agent-loop` was developed
after reviewing these MIT-licensed projects:

- **Caveman**, Julius Brussee, reviewed at commit
  `ec83e5bace4c20484d704dea21e12fc4eb94e9aa`
- **Ponytail**, Dietrich Gebert, reviewed at commit
  `16f29800fd2681bdf24f3eb4ccffe38be3baec6b`

The review informed two ideas:

1. remove filler from human-facing agent communication without removing exact
   technical facts;
2. stop implementation at the first verified solution that satisfies the task.

`agent-loop` does not download, execute, or depend on either project at runtime.
It ships its own PHP-oriented skills, PHP hooks, tests, and dogfood gate. The
implementation deliberately excludes their installers, JavaScript runtime,
mode files, status lines, transcript statistics, marketplaces, MCP servers, and
client-specific plugin adapters.

These notices document provenance only. They are not installation instructions
and are never read or executed by `agent-loop init install-assets`.

The upstream projects remain copyright their respective authors and are
available under their own MIT license files:

- <https://github.com/JuliusBrussee/caveman/blob/ec83e5bace4c20484d704dea21e12fc4eb94e9aa/LICENSE>
- <https://github.com/DietrichGebert/ponytail/blob/16f29800fd2681bdf24f3eb4ccffe38be3baec6b/LICENSE>
