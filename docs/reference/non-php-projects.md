# TypeScript/JavaScript (non-PHP) projects

`agent-loop` is a PHP tool, but the governed lifecycle does not depend on the language of the project it governs. Since `voku/agent-map` 0.25.0 the repository map can also index TypeScript and JavaScript declarations, and a project can opt in.

## Declare the map languages

Add `map.languages` to `.agent-loop/init.json`:

```json
{
  "version": 1,
  "map": {
    "languages": ["typescript"]
  }
}
```

- The default is `["php"]`. A project that does not declare the setting behaves exactly as before.
- Use `["php", "typescript"]` for a mixed project.
- The allowed values are agent-map's languages. `typescript` covers `.ts .tsx .mts .cts .js .jsx .mjs .cjs`; `.d.ts` files are never indexed.
- An unknown value, an empty list or a non-list is refused: `init status` shows the warning, and `enter`, Run preparation and `map build` stop with the reason instead of indexing the default.

`agent-loop init recommend --format=json` proposes the setting (`map.init_config`), the map paths and the `package.json` validation scripts for a Node project; see [Project-aware init recommendations](project-aware-init.md). It never writes the setting. Add it by hand after review.

## Requirements

The `typescript` map language needs Node.js on `PATH` and the `typescript` npm package. agent-map takes the compiler from `AGENT_MAP_TYPESCRIPT_PATH` when that is set, and otherwise from the project's own `node_modules/typescript` (run `npm ci` first). **Loading the project's copy executes code from the project.** Set `AGENT_MAP_TYPESCRIPT_PATH` to a compiler you trust when you do not trust the project's dependencies.

When TypeScript is declared and Node.js or the compiler is missing, the map cannot be built. `enter` then stops for a Contract whose scope holds TypeScript/JavaScript files, with agent-map's reason and a retry command. This is the same rule as for a PHP map that cannot be built: preparation blocks, it does not continue without map evidence. Work whose scope holds no file of a declared language (for example Markdown only) needs no map and is not affected. To work without TypeScript map evidence, remove `typescript` from `map.languages`.

## What works

- **`enter` / `finish`, Contracts, validation commands, review and Learning.** Validation commands are whatever the Contract names, for example `npm ci --ignore-scripts`, `npm run typecheck` and `npm test`.
- **Map-backed code facts for scoped files.** When an approved Contract's scope holds existing files of a declared language, `enter` builds or rebuilds the map so that it covers them, and Recall and `workflow context` receive their symbols (for example `src/recognition/matcher.ts#matchGesture`).
- **`agent-loop map build`** indexes the declared languages by default. `--languages=php,typescript` overrides the declaration for one build. `map query`, `map file`, `map scope` and the search index read TypeScript symbols like PHP symbols.
- **Staleness.** A changed or deleted indexed file makes the map stale for every language. `enter` never treats a stale map as current.

## How the map is kept current

agent-map's incremental `refresh`, `prepare` and `watch` cover PHP-only maps and refuse a map that indexes another language. Where Loop would refresh, it therefore asks agent-map for a full build with the declared languages:

- A missing map is built from the Contract scope only.
- An existing map is rebuilt from the paths and excludes it recorded, plus the Contract scope, so a map built with `map build --paths=src,test` keeps that coverage.

`agent-loop map refresh` on such a map is refused by agent-map, which names the `build --languages=...` command to run instead. The package Git hook that refreshes the map after `git pull` or a checkout only reacts to PHP changes and only refreshes; it leaves a TypeScript map as it is, and the next `enter` rebuilds it when it is stale.

## What does not work

- **Deterministic `agent-loop edit`.** It is a PHP method contract. It refuses a TypeScript target (`src/file.ts#Class::method`) and refuses to use, refresh or rebuild a map that indexes TypeScript. Edit such files directly within the governed Contract scope.
- **Structured failure diagnostics for non-PHP tools.** `repair` and the validation diagnostics parse PHPStan and PHPUnit output. A failing `tsc`, `eslint` or `node --test` run is recorded as a failed validation, without parsed file and line findings.
- **Call relations.** The TypeScript map holds declarations, `extends` and `implements` only: no callers, callees or checker-resolved types. Rename, removal, move and scaffold plans stay PHP-only.
- **Semantic backend.** PHPStan analyses PHP files only. A TypeScript-only map is always structural.

The index file keeps its name, `.agent-loop/map/php-symbols.json`, also when it holds TypeScript symbols.

## Architecture hints and test-scope discovery

The optional, **developer-side** `itp-context-source-hints` CLI (provided by `voku/itp-context`) can inspect exact PHP paths discovered by Map or named in a Contract:

```shell
vendor/bin/itp-context-source-hints --root=. --file=src/Ui/DashboardView.php
```

It returns bounded, syntax-only `#[Rule]` IDs and source digests without loading the project's enum definitions. The host must open the owning rule definition to verify `verifiedBy` tests, ADR `refs` and `changeGuidance`. This evidence is advisory and **never** promotes a file into approved scope. The CLI is optional: if it is not installed, do not interpret its absence as proof that no architecture rule applies. Loop's production PHP must not call ITP-Context, because it belongs to the isolated development-tool boundary.

TypeScript and JavaScript declarations in agent-map do **not** have an equivalent ITP rule annotation model. Before proposing scope, inspect existing browser, service-worker, test, import/build and deployment integration paths via bounded source search and available Map evidence. Native JavaScript/TypeScript architectural rule metadata is a separate owner decision; do not manufacture test/ADR references or silently widen a Contract.
