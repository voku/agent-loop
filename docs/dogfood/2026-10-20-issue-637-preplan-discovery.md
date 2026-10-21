# Docker dogfood: Issue #637 pre-PLAN discovery

Date: 2026-10-20

## Run identity

- Open issue: [voku/agent-loop #637](https://github.com/voku/agent-loop/issues/637)
- Docker CLI source: local `codex/issue-637-preplan-discovery` worktree, based at `61253749d3703da7c26e9ce1074b9818e74c461a` with the approved Issue #637 changes under test.
- Project target: clean checkout at `61253749d3703da7c26e9ce1074b9818e74c461a`, mounted with `AGENT_LOOP_TARGET` through `./docker/agent-loop`.
- Task IDs: `ISSUE-637-DOGFOOD` and `ISSUE-637-DOGFOOD-FOCUSED`.
- No Contract was created or approved in the target; both `enter` calls returned the existing PLAN command template, `mutation_ready=false`, and exit code 1.

## Evidence setup and results

The missing-evidence run started from a separate clean checkout with no `.agent-loop/map` artifacts. `enter ... --request ... --format=json` returned `status=abstained`, `reason=map_not_current`, `map_state=missing`, and `authority=none`. It did not build an index.

For the current-evidence run, Map and Search were explicitly built through the Docker CLI in the project target:

- Map: 553 files, 4,887 relations, 552 diagnostics.
- Search: 4,615 chunks from 553 files.
- Map/Search snapshot: `sha256:8e7260fb02c0fe5898b2a8f9be67d0f00ea1493b756d8344f90fe3df37ef3f0b`.

With the short task request (query SHA-256 `64310f6a47abb9ea80ed703b9c786c3e48668a17ce453267484f0efd113df472`), `enter` returned `status=ready`, `authority=none`, and five `ranked_unverified` candidates. The first three were directly useful to the task:

1. `WorkflowRankedMapContextExpander::add` in `src/Workflow/WorkflowRankedMapContextExpander.php`.
2. `WorkflowContextRankedMapExpansionTest::testRankedMethodLeadExpandsThroughExistingMapRelationsWithoutChangingApprovedScope` in `tests/WorkflowContextRankedMapExpansionTest.php`.
3. `RunManifestProjectorTest::testMapAndSearchReadinessAreProjectedFromAgentMapOwnerFacts` in `tests/RunManifestProjectorTest.php`.

The remaining two results (`WorkflowStatusCommandTest::testStatusNamesDurableLifecycleWithoutWritingAnything` and `MethodRenameEditRunner::run`) were noise for this task. A longer request also ranked general test-candidate helpers and an unrelated agent-discipline hook ahead of some useful results, so concise task requests produced a better ranking in this run.

The projected structural context contained three bounded primary slices, one per top candidate, with line ranges and source hashes; it contained no caller/test relations, blind spots, or omissions. Manual inspection of the existing expander showed that it resolves a method ID and passes the resolved `Class::method` name to `EditContextPlanner`. The first implementation attempted to pass the raw symbol ID; the Docker dogfood exposed that mismatch, and the implementation was corrected to follow the existing consumer.

## Decision impact

The evidence confirmed the existing owner API and test pattern and changed the implementation detail for invoking `EditContextPlanner`. It did not change the already-approved Contract file scope or validation plan. It did not authorize scope, create a Contract, or approve the PLAN. Candidate ranking still requires source inspection, and the lack of relation slices limited the context's value beyond locating the relevant implementation and tests.

Observed extra setup cost was explicit Map/Search construction: one Map build and one Search-index build before the fresh-evidence run. The ordinary `enter` path itself only read the current artifacts. This dogfood demonstrates both safe abstention and useful candidate navigation for this issue; it does not establish that every issue benefits from Map/Search discovery.
