# Codex Cloud bootstrap-recovery dogfood

Date: 2026-08-21
Issue: #259
Mode: agent-host dogfood
Host: ChatGPT Codex Cloud
Observed baseline task: `task_e_6a885908e1c083229d4502f47fd57ff5`

## Purpose

A real release-finishing task was used as host dogfood rather than a synthetic
prompt test. The task asked the acting coding agent to finish the remaining
`agent-*` slices, dogfood the resulting release set, and prepare the pre-release.

The run stopped before implementation. This report preserves the observable
failure so the candidate guidance has a fixed baseline to falsify.

## Baseline observations

The acting host reported all of the following:

- current checkout had no configured Git remote;
- `gh auth status` had no authenticated GitHub host;
- no sibling `agent-*` repositories were checked out under the workspace;
- `vendor/autoload.php` was absent, so the repository lifecycle entrypoint could
  not run;
- no repository mutation, commit, PR merge, release, or governed Learning record
  was produced;
- the agent nevertheless had working public GitHub reads and successfully
  inspected `agent-learning#40`, `agent-learning#39`, exact-head checks, the raw
  PR diff, and the relevant Learning source paths.

The run classified the missing remote/auth/toolchain as a terminal
`STATUS: blocked` and stopped while substantial local work remained possible.

## What the baseline proved

The failure did **not** prove that the lifecycle kernel rejected a legal next
step. The lifecycle binary never became runnable.

The failure did prove a first-party guidance gap at the host/environment boundary:
a fresh hosted checkout could interpret missing reversible bootstrap as a
workflow authority failure, then abandon locally executable work because one
preferred remote-write path was unavailable.

This is distinct from a real authority gate. A real gate exists only when the
next required action itself cannot be performed after safe local work has been
exhausted.

## Candidate change

The candidate guidance adds one boundary, not another workflow phase:

1. restore minimum reversible workspace/tool bootstrap first;
2. once `agent-loop` is runnable, use `enter`/`finish` as the sole governed
   lifecycle router;
3. treat missing remote-write capability as a capability boundary only for the
   operations that actually require it;
4. continue useful authorized local work before declaring a terminal gate.

No runtime dependency, lifecycle state, owner artifact, or environment manager is
introduced.

## Candidate acceptance run

The guidance is not accepted from this documentation change alone. Rerun the same
release-finishing task in a fresh Codex Cloud session after the candidate assets
are projected.

Record at minimum:

| Observation | Baseline | Candidate target |
| --- | --- | --- |
| lifecycle binary initially runnable | no | may be no |
| declared Composer dependencies recovered | no | yes when missing |
| expected public repository remote recovered/fetched | no | yes when needed |
| required public sibling checkouts obtained | no | yes when needed |
| governed `enter` reached after bootstrap | no | yes |
| remote-write capability available | no | observed, not assumed |
| local implementation continued when remote write unavailable | no | yes |
| first genuinely impossible required action named | remote access broadly | exact operation or none |
| useful local work left undone at stop | yes | no |
| self dogfood executed | no | yes before release-ready claim |
| installed-consumer release-set dogfood executed | no | yes before release-ready claim |

If the candidate still stops at `vendor/`/remote/`gh` absence while safe recovery
or useful local work remains, reject the guidance and inspect the next owner of
that behavior instead of explaining the miss away.

## Learning disposition

The durable learning candidate is narrow:

> Hosted workspace bootstrap precedes the governed product-mutation boundary;
> unavailable preferred remote-write tooling is terminal only when the next
> required action needs it and no useful local work remains.

Whether this becomes a durable Learning record is decided only after the
candidate host run demonstrates that the guidance changes observable behavior.
