# ECC read-only subagent adaptation

Status: reviewed upstream mechanism adapted into the package-owned host projection boundary.

Reviewed upstream: `affaan-m/ECC@c9148d0bb239ed01a95724a5928b98cdf9c30658`.

## Adapted mechanism

ECC projects Explorer and Reviewer roles with a host-native read-only sandbox. The useful mechanism is the conversion of a semantic read-only role contract into an objective host capability where the host exposes one.

`agent-loop` adapts only that mechanism:

- canonical subagent definitions own host-neutral mutation intent;
- investigator, solution-triager, blindspot-reviewer, and code-reviewer declare `read-only` intent;
- the surgical builder remains writable;
- Codex projection maps read-only intent to `sandbox_mode = "read-only"`;
- hosts without an owned native mapping receive no invented sandbox field;
- `HostCapabilityMatrix` reports Codex projection separately from live runtime enforcement, which remains unverified until observed by the host/runtime boundary.

## Rejected ECC surface

This adaptation does not import ECC's model selection, agent taxonomy, orchestration workflow, or runtime dependency. Model/provider selection remains host-owned, and generated host configuration does not become workflow authority.

## Compile-down consequence

Read-only prose remains a semantic fallback for hosts without native enforcement. It may be reduced later only where deterministic host enforcement and a truthful unsupported-host fallback make the duplicated prose unnecessary.
