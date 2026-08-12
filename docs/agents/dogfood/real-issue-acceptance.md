# Real-issue acceptance

Proving that a capability exists is not proving that it helped somebody fix a
real problem. Self-shape and release-set dogfood keep lifecycle, packaging and
deterministic invariants honest, but a synthetic task tends to confirm exactly
the behavior it was written beside.

Real-issue acceptance answers the harsher question:

> Given a real issue *before the fix is known*, did the installed workflow find
> the useful project context, construct a project-specific execution contract,
> expose missing evidence, and reach a verified fix without inventing work?

This document defines the acceptance model. It does not define a second
lifecycle: every artifact below is written by the governed workflow that
already exists in `docs/agents/skills/agent-loop-workflow/SKILL.md`.

## Three evidence planes

A run has three different kinds of evidence, and they must stay distinguishable.

| Plane | Question it answers | Tool | Installed as |
|---|---|---|---|
| Structure | what code exists, where it is, what calls it | `voku/agent-map` | runtime dependency of `agent-loop` |
| Intent | why the selected code is constrained: architecture rules, owners, refs, proof metadata | `voku/itp-context` | external tool |
| Candidate quality | whether the candidate introduced explainable low-quality patterns | `voku/slop-scan` | external tool |

The planes complement each other and none of them substitutes for another:

- `agent-map` reports structural facts. A structural fact is not an intent.
- `itp-context` reports declared architecture intent. **A rule is guidance
  evidence, not proof that current source complies with it.**
- `slop-scan` is a deterministic heuristic scanner over the candidate. It is
  deliberately downstream of implementation, and **a heuristic finding is not
  automatically a correctness failure.**

`itp-context` is designed for a small number of high-signal annotations. Coating
a repository in attributes to raise a coverage number produces noise, not
context.

## Installation boundary

Neither external tool is a dependency of `voku/agent-loop`, and a dogfood run
must not make it one. They are invoked by the workflow, not welded into it.

They also cannot share one Composer project today. The conflict is real:

```text
agent-loop -> agent-map 0.7.0  -> voku/simple-php-code-parser ^0.22
              slop-scan 0.1.4  -> voku/simple-php-code-parser ^0.21.0
```

Do not relax either constraint to make one tool project resolve. The correct
shape is one isolated tool project per tool in the repository under test:

```text
tools/
├── itp-context/
│   ├── composer.json     # voku/itp-context
│   └── composer.lock     # pinned: which version produced this run's evidence
└── slop-scan/
    ├── composer.json     # voku/slop-scan alone, or a pinned slop-scan PHAR
    └── composer.lock
```

This repository installs both that way, so the pattern is executed rather than
described. A repository that needs `agent-loop` itself as development tooling
adds `tools/agent-loop/` beside them, and `itp-context` may share that project
because their constraints do not conflict.

Commit the lock files and ignore the vendor directories: the lock is the
evidence of which tool version produced a finding.

Both tools require PHP 8.3+. Isolation is what keeps that requirement off a
library that still advertises PHP 7 support, and keeps agent tooling out of the
dependency tree of the package's own consumers. Keep the tool projects out of
release archives:

```gitattributes
/tools/agent-loop export-ignore
/tools/slop-scan export-ignore
```

See `docs/compact-layout.md` for the same boundary applied to workflow state.

Binaries therefore live in the tool project, not in the repository's own
`vendor/`. A repository that already depends on one of these packages directly
uses its own `vendor/bin/` instead. Ask `init tools` for the path that exists
rather than assuming one.

`voku/slop-scan` 0.1.4 has one wrinkle here: its bin resolves the autoloader as
`__DIR__ . '/../vendor/autoload.php'`, which exists only in a standalone
checkout. Installed as a Composer dependency it exits with "Composer autoload
file not found", ignoring the location Composer already published in
`$GLOBALS['_composer_autoload_path']`. The upstream README documents the PHAR,
which is why the Composer path went unnoticed. `tools/slop-scan/slop-scan.php`
is a three-line runner around that, and should be deleted once the bin honors
the global.

## Candidate pre-screen

Before installing anything, record the answer to each hard condition:

```text
REAL_ISSUE
NO_EXISTING_IMPLEMENTATION
CURRENTLY_REPRODUCIBLE
ACTIONABLE_DELTA_REMAINS
```

When the run replays a historical issue to evaluate discovery, also record:

```text
FIX_NOT_ALREADY_FULLY_DISCLOSED
```

Any hard condition that fails stops the run there. A stopped pre-screen is a
valid result and costs nothing; a run that continues past a failed condition
produces evidence about a problem that was not the stated problem.

## Freeze

Persist before any production mutation:

- issue URL/number and the issue body or a faithful digest;
- base commit SHA;
- dependency and tool versions, including the two external tools;
- runtime versions;
- the project gates that are expected to pass;
- the baseline reproduction.

For an acceptance replay, check out the exact Git commit and inject that
checkout as a Composer `path` repository, then archive the generated
`composer.lock`. `dev-branch#SHA` is not immutable evidence: Composer resolves
metadata against moving branch state even when the source SHA is pinned.

## Tool capability discovery

`init tools` probes all three planes and caches the result:

```bash
vendor/bin/agent-loop init tools
```

It reports whether an `agent-map` index exists, and where `itp-context` and
`slop-scan` are installed — a project-local installation (`vendor/bin` or an
isolated `tools/` project) wins over an ambient PATH build, because a pinned
tool is the version the repository meant. Invoke each tool at the reported
path; do not assume one.

Availability is not the whole state. Record both:

| Tool | States | Answered by |
|---|---|---|
| `agent-map` | `AVAILABLE` / `DEGRADED` / `UNAVAILABLE` | `init tools` |
| `itp-context` | `AVAILABLE` / `UNAVAILABLE` | `init tools` |
| `itp-context` | `CONFIGURED` / `NOT_CONFIGURED` / `INVALID` | running `itp-context-validate` / `-export` |
| `slop-scan` | `AVAILABLE` / `DEGRADED` / `UNAVAILABLE` | `init tools` |

An installed binary says nothing about whether the repository declared anything
for it to read. Only running the tool answers that.

`NOT_CONFIGURED` is a legitimate result. A repository that has never declared
architecture rules has no `itp-context` evidence, and the run records exactly
that. Do not add annotations to a consumer repository so the report can claim
the tool was used. That measures the annotation, not the tool.

One tool being absent does not degrade another. An absent external tool is
reported as information, not as a warning about a broken setup.

## Where the planes attach

The run uses the existing governed phases. Nothing below adds a state.

```text
PLAN -> APPROVE -> CONTEXT -> CONTRACT -> IMPLEMENT -> VALIDATE -> REVIEW -> LEARN -> VERIFY -> CLOSE
                      |          |            |           |          |         |
                 agent-map   provenance   regression   project   slop-scan   tool
                itp-context   retained      first       gates      delta    ledger
```

### CONTEXT: structure before intent

Run `agent-map` from the problem statement only. Feeding it any knowledge of the
eventual fix invalidates the discovery result for this run.

Record:

- symbols found;
- candidate files and their source fingerprints;
- relevant tests;
- irrelevant hits;
- missing context.

Then use `itp-context` when the repository already has it configured:

```bash
tools/agent-loop/vendor/bin/itp-context-validate 'Project\Context\ArchitectureRules'
tools/agent-loop/vendor/bin/itp-context-export var/itp-context src --exclude=vendor --exclude=tests
tools/agent-loop/vendor/bin/itp-context-query var/itp-context --text='<issue concepts>'
```

The package ships `itp-context-summarize`, `itp-context-validate`,
`itp-context-generate`, `itp-context-export` and `itp-context-query`. Export
writes one markdown file per annotated symbol with `rule_ids`, `owners`, `refs`
and `verified_by` frontmatter; query selects by rule id, owner, text and the
other exported fields.

Record separately from the structural evidence:

- selected rule IDs;
- annotated symbols, owners, refs, `verified_by`;
- rules that were useful;
- rules that were irrelevant;
- architecture context that was missing.

### CONTRACT: keep provenance

Recall builds the execution contract from the issue, the structural evidence,
the architecture evidence when present, and project-native docs/config. Do not
flatten those into one undifferentiated context blob. The persisted L1 keeps the
existing five-part shape, and its `Context` section keeps the two sources apart:

```markdown
## Goal
## Context
  - repository facts
  - architecture constraints
## Constraints
## Verification
## Done When
```

Package presence still does not invent a command, and a `verified_by` reference
is a claim to check, not a passed check.

### IMPLEMENT: regression first

For a deterministic defect: reproduce, write the regression test, and prove it
fails on the frozen base. No failing test means no production fix.

Then make the smallest demonstrated change. An architecture rule may constrain
the implementation; a bugfix does not casually rewrite the rule that constrains
it.

### VALIDATE: project gates are the correctness authority

Run the repository's own gates first — tests, PHPStan at the configured level,
lint/style, Composer validation, package-specific checks. Everything after this
step is review evidence, not a replacement for it.

### REVIEW: the slop-scan delta

Scan the frozen base before mutation and the candidate afterwards, with the same
configuration, and prefer a machine-readable format because the output is
evidence rather than prose:

```bash
tools/slop-scan/vendor/bin/slop-scan.php scan . --json --ignore 'vendor/**'
```

Report the comparison, not a single number:

- new findings;
- resolved findings;
- unchanged findings;
- changed fingerprints;
- findings inside the changed production region.

Separating changed fingerprints from new findings is not bookkeeping. An
occurrence fingerprint in 0.1.4 covers the line number, so inserting lines
above an untouched block retires its fingerprint and mints a new one. A raw
new-versus-resolved count therefore over-reports on any diff that grows a file.
Match new against resolved by rule, path and the source line's content before
concluding anything: an identical line that moved is not a finding.

Two rules govern what that comparison may do:

> Existing repository slop is not a failure of the candidate. The agent owns the
> delta.

> A heuristic finding is not automatically a correctness failure.

`CLOSE` may be blocked by a `slop-scan` finding only when project policy makes
that rule a gate, or when review confirms the new finding is a real defect that
violates the Contract. There is no invented score threshold; `slop <= 4.7`
would be fake precision, not quality.

### REVIEW: two separate passes

Run them as two passes, because they fail differently:

1. **Correctness** — how could this change still be functionally wrong? Use the
   tests, the source, the Contract, and any architecture rules that apply.
2. **Simplification/slop** — what did the agent add that can be deleted? Did
   `slop-scan` identify a real regression? Did a small task grow an abstraction
   it does not need?

### LEARN: the tool usefulness ledger

Every run evaluates the tools, not only the fix:

```text
agent-map:    useful hits / misses / irrelevant hits
itp-context:  applicable rules / useful rules / missing expected intent / irrelevant context
slop-scan:    useful candidate findings / false or noisy findings / missed review concern
```

Presence is not usefulness. None of these inferences are allowed:

```text
itp-context was installed  -> useful
slop-scan returned zero    -> clean code
agent-map returned a file  -> good context
```

Only a repeatable observed failure becomes a Finding, through the normal
learning boundary in `docs/workflow/learning-boundary.md`. One good-looking run
promotes nothing.

### CLOSE

`CLOSE` keeps its existing authority: the approved Contract, project
verification, review, and the learning decision. The external tools contribute
evidence to those gates. They do not become new sources of lifecycle authority.

## Report success condition

A real-issue dogfood report is incomplete unless it states, per tool, whether it
materially helped, abstained, missed required context, or produced noise.

> Installing and invoking a tool is not evidence that it improved the run.

A miss is a finding even when every unit test for the tool is green, and the
benchmark is not repaired after seeing the oracle: when the pre-fix map result
missed the region the verified fix actually needed, that result is recorded, not
re-run with better keywords.

## Product boundary

"Should the workflow know about these tools" and "should this package depend on
them" are different questions, and they have different answers.

| Level | Answer | Where |
|---|---|---|
| Discovery — report whether the tool is installed, and where | **yes** | `init tools`, beside `rg`, `git` and `docker`, which this package also does not install |
| Guidance — tell an agent when the plane is worth using | **yes** | `agent-loop-l2-context`, `agent-loop-code-review`, `agent-loop-dogfood` |
| Composer dependency | **no** | blocked by the parser conflict above, and by the PHP 8.3+ floor |
| Product integration — a Recall context provider, a review-observation provider | **not yet** | needs repeated runs proving a stable seam |

Discovery and guidance cost nothing when the tool is absent: the probe reports
it, the skills say so, and the run records one plane it did not have. That is
the same deal `rg` already gets.

The last row is the one that needs evidence rather than enthusiasm, because it
is the one that puts an external tool inside a gate. `tests/RealIssueEvidenceToolBoundaryTest.php`
keeps the dependency answer executable, so promoting either tool stays a
deliberate change rather than one that arrives quietly.

## Recorded runs

### Consumer-repository pilot

Tracked in [agent-loop#66](https://github.com/voku/agent-loop/issues/66) against
`voku/Simple-PHP-Code-Parser`: a historical issue was replayed at its frozen
pre-fix commit, the production file that the later fix changed appeared in the
top structural results, and a real gap in Recall's Composer-dependency evidence
became a regression-first change in `voku/agent-recall-compiler` rather than a
note in a report. That run also produced the `dev-branch#SHA` provenance rule
recorded under [Freeze](#freeze).

### First self-run of the evidence planes

Both tools were installed and run against the change that introduced this
document. Per-tool result, in the ledger's terms:

**`slop-scan`: helped, after correction.** 366 findings on the base, 368 on the
candidate. Raw fingerprint comparison said 10 new and 8 resolved; content
matching reduced that to **two** genuinely new findings and zero resolved. Both
are `weak` `php.magic-numbers` in test code — `3600` in a cache fixture that
mirrors the command's own default, and `512`, the depth argument `json_decode`
requires before its flags. Neither is a defect, no project policy gates that
rule, and inventing a named constant to quiet a heuristic would have been the
wrong fix. The other eight pairs were identical lines that moved, including the
`php.clone-cluster` hit on `readOptionValue`, which is duplicated across nine
`Init` commands **on the base too**. That cluster is pre-existing repository
slop, so it belongs to a future task, not to this diff.

**`itp-context`: abstained, correctly.** `NOT_CONFIGURED`. Export produced zero
context documents, query matched nothing, and summarizing a heavily documented
class returned only its heading. The state is also permanent for this package
rather than an oversight: rule enums implement `RuleIdentifier` from the tool,
so declaring rules in `src/` would make `voku/itp-context` a runtime dependency
of the library — the boundary this document exists to hold. `ProjectLayout`'s
"nothing else may spell a state location" is exactly the kind of intent the
tool encodes, and a violation of it shipped anyway, so the value is real; it is
simply not collectable here at an acceptable price.

**The run also produced two tool findings**, both recorded above rather than
worked around silently: the Composer-install autoload bug in `slop-scan` 0.1.4,
and the line-sensitivity of its occurrence fingerprints. Neither was visible
from reading the packages. Both appeared within minutes of actually running
them, which is the argument for the ledger.

**And one harness finding.** The first comparison scanned the base with
`--ignore 'tools/**'` and the candidate without it, which manufactured 22 new
findings in dogfood runners that were sitting unchanged on the base. "Same
configuration" above is not a formality: a changed ignore set produces a delta
that looks exactly like a regression. Re-run the base whenever the scan
arguments change, and treat a suspiciously large delta as a harness bug before
treating it as a code problem.
