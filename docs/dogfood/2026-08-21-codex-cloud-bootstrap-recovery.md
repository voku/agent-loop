# Codex Cloud bootstrap-recovery dogfood

Date: 2026-08-21
Issue: #259
Mode: agent-host dogfood
Host: ChatGPT Codex Cloud
Observed baseline task: `task_e_6a885908e1c083229d4502f47fd57ff5`
Task artifact: https://chatgpt.com/codex/cloud/tasks/task_e_6a885908e1c083229d4502f47fd57ff5
Repository at handoff: `voku/agent-loop` `main` at `3963848ea5893a3f3305e1ca8b1deb9667a6f254`
Model: not exposed by the supplied Codex Cloud task receipt

## Purpose

A real release-finishing task was used as host dogfood rather than a synthetic
prompt test. The task asked the acting coding agent to finish the remaining
`agent-*` slices, dogfood the resulting release set, and prepare the pre-release.
The exact baseline wording is retained in the Codex Cloud task artifact above;
the candidate rerun must reuse the same task wording when the host permits it, or
record the exact wording deviation instead of calling the runs identical.

The run stopped before implementation. This report preserves the observable
failure so the candidate guidance has a fixed baseline to falsify.

## Baseline evidence

Observed tools/commands named in the task receipt:

- public GitHub API reads via `curl -fsSL https://api.github.com/repos/voku/<repo>/...`;
- `git diff origin/main...pr40 -- MEMORY.md`;
- `rg -n "RedactionGuard|adopt_existing|ConstraintManifestActivator|validationCommands|validationFilePath|canAdopt" src tests`;
- `gh auth status`;
- `bin/agent-loop init status`;
- `git status --short --branch`.

Observed validation/evidence work:

- queried open PRs and issues across the seven named repositories;
- queried exact-head check runs for `agent-learning#40`;
- inspected the complete `MEMORY.md` PR diff;
- traced both review concerns through executable Learning source paths.

The acting host reported all of the following:

- current checkout had no configured Git remote;
- `gh auth status` had no authenticated GitHub host;
- no sibling `agent-*` repositories were checked out under the workspace;
- `vendor/autoload.php` was absent, so the repository lifecycle entrypoint could
  not run;
- no isolated branch/worktree recovery was reported before the stop;
- no repository mutation, commit, PR merge, release, or governed Learning record
  was produced;
- the agent nevertheless had working public GitHub reads and successfully
  inspected `agent-learning#40`, `agent-learning#39`, exact-head checks, the raw
  PR diff, and the relevant Learning source paths;
- projected repository skills/hooks: not observable from the supplied receipt;
- local Git hook eligibility: not observable from the supplied receipt;
- generated Recall/system briefing consumed: no — governed lifecycle never ran.

The run classified the missing remote/auth/toolchain as a terminal
`STATUS: blocked` and stopped while substantial local work remained possible.

## What the baseline proved

The baseline proves only the observable host behavior: Codex Cloud stopped before
lifecycle execution, and the supplied receipt still listed useful local
implementation/validation work that had not been attempted.

It did **not** prove that the lifecycle kernel rejected a legal next step. The
lifecycle binary never became runnable. It also did **not** prove that first-party
guidance caused the stop.

The candidate hypothesis is narrower: current first-party guidance may leave the
host/environment boundary ambiguous enough that a fresh hosted checkout can treat
missing reversible bootstrap as a terminal workflow/authority failure. The
candidate rerun must demonstrate a changed observable behavior before that causal
hypothesis is accepted.

This is distinct from a real authority gate. A real gate exists only when the
next required action itself cannot be performed after safe local work has been
exhausted.

## Candidate change

The candidate guidance adds one boundary, not another workflow phase:

1. restore minimum reversible workspace/tool bootstrap first, including an
   isolated branch/worktree before implementation;
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

Freeze or record these comparison inputs before interpreting the result:

- exact task wording or exact documented deviation;
- repository revision(s);
- model identifier when exposed by the host, otherwise `not exposed`;
- tools available/used;
- validation commands;
- projected skills/hooks and whether the generated briefing was consumed;
- local Git hook eligibility;
- every bootstrap recovery action actually performed.

Record at minimum:

| Observation | Baseline | Candidate target |
| --- | --- | --- |
| task wording | exact task artifact linked above | identical, or exact deviation recorded |
| repository revision | `agent-loop` `3963848...` at handoff | exact revision recorded |
| model | not exposed | exact model or `not exposed` |
| lifecycle binary initially runnable | no | may be no |
| declared Composer dependencies recovered | no | yes when missing |
| expected public repository remote recovered/fetched | no | yes when needed |
| required public sibling checkouts obtained | no | yes when needed |
| isolated branch/worktree initially present | not reported | observed |
| isolated branch/worktree recovered | no reported recovery | yes when missing |
| governed `enter` reached after bootstrap | no | yes |
| projected skills/hooks | not observable | observed |
| generated Recall briefing consumed | no | yes/no/not-observable with evidence |
| local Git hooks eligible | not observable | yes/no/not-observable |
| remote-write capability available | no authenticated `gh`; other write capability not observed | observed, not assumed |
| recovery actions actually performed | none reported | exact actions listed |
| local implementation continued when remote write unavailable | no | yes when authorized work remains |
| first genuinely impossible required action named | remote access broadly | exact operation or none |
| useful local work left undone at stop | yes | no |
| self dogfood executed | no | yes before release-ready claim |
| installed-consumer release-set dogfood executed | no | yes before release-ready claim |

If the candidate still stops at `vendor/`/remote/`gh`/workspace-isolation absence
while safe recovery or useful local work remains, reject the guidance and inspect
the next owner of that behavior instead of explaining the miss away.

## Learning disposition

The durable learning candidate is narrow:

> Hosted workspace bootstrap precedes the governed product-mutation boundary;
> unavailable preferred remote-write tooling is terminal only when the next
> required action needs it and no useful local work remains.

Whether this becomes a durable Learning record is decided only after the
candidate host run demonstrates that the guidance changes observable behavior.
