# Second learning pass — 2026-08-14

The first learning pass processed historical evidence: findings reconstructed
from past sessions, issues and diffs. This one processes evidence that only
existed because the improved workflow was then used on real work — one
self-governed change and two external consumer replays.

The distinction matters. A pass that only re-reads its own history cannot tell
you whether an improvement worked or merely moved the problem somewhere less
visible.

## Decisions

| Finding | Exposed by | Decision |
| --- | --- | --- |
| `finding.2026-08-14.005` — `.git` shape assumption | Running `init doctor` / `init sync-githooks` inside a linked worktree | **Durable rule + static rule + test.** `GitWorkTree`, `NoGitDirectoryShapeAssumptionRule`, `GitWorkTreeDetectionTest`. |
| `finding.2026-08-14.006` — tooling isolation had no detector | Auditing which MEMORY rows name an executable canonical home | **Static rule.** `NoInProcessPhpstanRuleTestCaseRule`; the memory row now points at it. |
| `finding.2026-08-14.007` — memory row contradicted its canonical home | Inspecting a *passing* run's artifacts instead of its exit code | **Correct the row, add nothing.** The gate already existed in `WorkflowCloseCommand` with tests; the harness records the residual status as evidence. |
| `finding.2026-08-14.008` — derived search index had a reader and no writer | Simple-PHP-Code-Parser replay: decisive symbol absent from governed context | **Durable rule + two tests.** Ownership moved to `ProjectLayout::mapSearchIndex()`; approve reports when ranked evidence is unavailable. |
| `finding.2026-08-14.009` — agent-map's PHPStan cache falls outside the state mapping | anti-xss replay produced an untracked 5.3 MB cache directory | **Documentation correction + follow-up.** The exception is now named in `docs/compact-layout.md`; redirecting it needs a cache-location option in `agent-map`, and there is no failing case yet, so no package change. |
| `finding.2026-08-14.010` — `itp-context` documented external, required at runtime | Both consumer replays installing it unasked | **`FOLLOW_UP_REQUIRED`.** A boundary contradiction with no failing case. Not fixed by guessing which side is wrong. |

## What the comparison shows

**An old problem genuinely disappeared.** The Learning-root split-brain is gone:
project configuration selects the root once, the Run binds it, downstream
commands consume the binding, and no workflow command accepts
`--learning-root` any more. The self-shape runner lost eight flag repetitions
and gained nothing in their place.

**New friction became visible, and it was the important kind.** The known
Map → Recall → Context loss had been read as a retrieval-quality problem. Using
the workflow on a real external task showed it was not: agent-map had the
decisive symbol, and the governed context still shipped without it, because one
derived path was read by `workflow approve` and written by nothing. The fix
widened no search, changed no ranking and raised no budget — it gave one path
one owner. That is the difference between tuning retrieval and conserving
evidence, and only a real replay could tell them apart.

**Complexity did not move, in one case because we refused to move it.**
`finding.2026-08-14.007` was the temptation: the obvious response to "MEMORY
claims a gate nobody enforces" is to add the gate to the runner. The gate
already existed one layer down, so adding it would have produced two
enforcement points and the appearance of progress. The row was corrected
instead, and the harness only records what the review actually said.

**No new static rules could be distilled from this pass, and that is a
result.** Findings 009 and 010 are an upstream hardcoded path and a dependency
boundary contradiction. Neither is a pattern a PHPStan rule can see, and
inventing rules that approximate them would have produced exactly the
style-police checks this project keeps refusing. Two of the six findings ended
without a durable rule; one ended as an explicit follow-up.

## What is measurably better

For the class of task these replays represent — a real request whose decisive
evidence already exists in the target repository:

- **Less lost decisive evidence.** Same task, same ranking, same budget: 17
  context lines with the decisive API absent, versus 55 with it present.
- **Less silent degradation.** A missing evidence channel now says so at
  approve time instead of producing a well-formed, quietly narrower context.
- **Fewer ambiguous ownership choices.** One Learning root, selected once. One
  search-index path, read and written by the same owner.
- **Less rediscovery.** `map search-index` is advertised in the namespace help
  it belongs to, so the command that makes ranked evidence possible is findable
  from the CLI rather than from agent-map's own help.

What is *not* better: a consumer still meets an untracked agent-map PHPStan
cache outside the workflow state root (see `docs/compact-layout.md` for the
exclusion lines), and still installs `itp-context` whether or not it wants the
intent plane. Both are recorded with evidence and neither was papered over.
